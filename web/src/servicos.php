<?php
declare(strict_types=1);

/*
 * Operações do sistema, usadas do mesmo jeito pelas páginas (index.php, gabarito.php,
 * prova.php, folha.php) e pela API (api/index.php). Recebem dados já lidos da
 * requisição, gravam no banco e no Storage e devolvem arrays; não escrevem HTML nem
 * JSON. Erro que o professor pode resolver sai como ErroDoUsuario, com o código HTTP.
 */

/** Lista de provas com os filtros escolhidos (ano, período). Devolve [provas, filtros, escolhidos]. */
function listar_provas(array $entrada): array
{
    $filtros = filtros_de_provas();
    [$where, $params, $escolhidos] = aplicar_filtros($filtros, $entrada);
    $st = db()->prepare("
        SELECT p.id, p.nome, p.ano, p.periodo, p.status, p.caderno, p.criado_em,
               COUNT(f.id) AS folhas,
               COUNT(DISTINCT f.turma) AS turmas
        FROM provas p LEFT JOIN folhas f ON f.prova_id = p.id AND f.erro IS NULL
        WHERE $where
        GROUP BY p.id ORDER BY p.ano DESC, p.periodo DESC NULLS LAST, p.id DESC
    ");
    $st->execute($params);
    return [$st->fetchAll(), $filtros, $escolhidos];
}

/**
 * Cadastro de uma prova nova como rascunho, com o gabarito por foto ou digitado.
 * $dados: ano, periodo, nome (opcional), gabarito_digitado (se não houver foto).
 * $fotos: upload_normalizado() do campo da foto do gabarito.
 * Devolve ['id' => id da prova, 'leitura' => leitura da foto ou null].
 */
function cadastrar_prova(array $dados, array $fotos, ?string $professorId): array
{
    $ano = (int)($dados['ano'] ?? 0);
    $periodo = (int)($dados['periodo'] ?? 0);
    if ($ano < 2000 || $ano > 2100) {
        throw new ErroDoUsuario('Informe o ano letivo com quatro dígitos, por exemplo ' . date('Y') . '.');
    }
    if (!isset(periodos()[$periodo])) {
        throw new ErroDoUsuario('Escolha o ' . PERIODO_ESCOLA . ' da prova.');
    }

    $digitado = trim((string)($dados['gabarito_digitado'] ?? ''));
    $leitura = null;
    $imagemGabarito = null;
    if ($fotos) {
        // A foto do gabarito só é lida; fica guardada apenas a área de respostas anotada.
        $leitura = ler_folhas([guardar_upload($fotos[0])])[0];
        if (!$leitura['ok']) {
            throw new ErroDoUsuario('Não foi possível ler o gabarito: ' . $leitura['erro']
                . ' Tire outra foto ou digite as respostas.', 422, ['codigo' => $leitura['codigo'] ?? null]);
        }
        $layout = $leitura['layout'];
        $modelo = modelo_do_caderno($layout['caderno'] ?? null);
        $questoes = questoes_da_leitura($leitura, $modelo['disciplina'] ?? null);
        if (isset($leitura['imagem_debug'])) {
            $imagemGabarito = storage_enviar('anotadas', 'gabaritos/' . bin2hex(random_bytes(8)) . '.jpg',
                $leitura['imagem_debug']);
        }
    } elseif ($digitado !== '') {
        // Sem foto, o formato e o caderno vêm da primeira folha de aluno lida.
        $questoes = questoes_digitadas($digitado);
        $layout = ['questoes' => count($questoes), 'alternativas' => alternativas_digitadas($questoes)];
    } else {
        throw new ErroDoUsuario('Envie a foto do gabarito ou digite as respostas.');
    }

    $nome = mb_substr(trim((string)($dados['nome'] ?? '')), 0, 200) ?: sugerir_nome_prova($layout['caderno'] ?? null);
    try {
        $id = criar_rascunho($nome, $ano, $periodo, $layout, $questoes, $imagemGabarito, $professorId);
    } catch (Throwable $ex) {
        apagar_do_storage(['anotadas' => array_filter([$imagemGabarito])]);
        throw $ex;
    }
    return ['id' => $id, 'leitura' => $leitura];
}

/**
 * Grava a revisão do gabarito: nome e, por questão, resposta, disciplina, dificuldade e
 * habilidade ($entrada na ordem das questões, como no formulário). Com $aprovar, o
 * rascunho vira prova ativa. Numa prova ativa o gabarito precisa continuar completo.
 * Devolve [questões conferidas, erros]; com erros, nada é gravado.
 */
function aprovar_gabarito(array $prova, string $nome, array $entrada, bool $aprovar): array
{
    $rascunho = $prova['status'] === 'rascunho';
    $nome = mb_substr(trim($nome), 0, 200);
    $erros = $nome === '' ? ['Dê um nome para a prova.'] : [];
    // Prova ativa: as folhas já são corrigidas com este gabarito, então ele precisa estar completo.
    $aprovar = $aprovar && $rascunho;
    [$questoes, $errosRevisao] = validar_revisao($entrada, $prova, $aprovar || !$rascunho);
    $erros = array_merge($erros, $errosRevisao);
    if (!$erros) {
        salvar_revisao((int)$prova['id'], $nome, $questoes, $aprovar);
    }
    return [$questoes, $erros];
}

/** Apaga um rascunho e a imagem do gabarito. Prova ativa não pode ser descartada. */
function descartar_rascunho(array $prova): void
{
    if ($prova['status'] !== 'rascunho') {
        throw new ErroDoUsuario('Só um rascunho pode ser descartado.', 409);
    }
    db()->prepare('DELETE FROM provas WHERE id = ?')->execute([$prova['id']]);
    apagar_do_storage(['anotadas' => array_filter([$prova['imagem_gabarito']])]);
}

/** Erro no Storage durante a limpeza não esconde o erro original. */
function apagar_do_storage(array $porBucket): void
{
    foreach ($porBucket as $bucket => $caminhos) {
        try {
            storage_apagar($bucket, $caminhos);
        } catch (Throwable) {
            // O erro que importa para o professor é o da operação principal.
        }
    }
}

/** "7A": sem espaços sobrando, até 20 caracteres. */
function turma_valida(string $turma): string
{
    $turma = trim(preg_replace('/\s+/', ' ', $turma));
    if ($turma === '') {
        throw new ErroDoUsuario('Informe a turma das folhas enviadas.');
    }
    if (mb_strlen($turma) > 20) {
        throw new ErroDoUsuario('O nome da turma pode ter até 20 caracteres.');
    }
    return $turma;
}

/**
 * Lê, corrige e grava as folhas de uma turma. Devolve uma entrada por página:
 * lida: ['id', 'arquivo', 'pagina', 'ok' => true, 'leitura' => saída do leitor]
 * recusada: ['id' (null se não for guardada), 'arquivo', 'pagina', 'ok' => false, 'erro', 'codigo'].
 * As fotos ficam só na pasta temporária; no Storage vão a área de respostas recortada
 * (sem o nome do aluno) e a imagem anotada. Com $guardarFalhas = false (app), a folha
 * recusada não é gravada: o professor tira outra foto na hora.
 */
function ler_folhas_aluno(array $prova, string $turma, array $uploads, bool $guardarFalhas = true): array
{
    if ($prova['status'] === 'rascunho') {
        throw new ErroDoUsuario('Aprove o gabarito antes de enviar as folhas dos alunos.', 409);
    }
    $turma = turma_valida($turma);
    if (!$uploads) {
        throw new ErroDoUsuario('Selecione ao menos um arquivo.');
    }
    $id = (int)$prova['id'];
    $layout = $prova['layout'];
    $gabarito = $prova['gabarito'];
    $caminhos = [];
    $nomeOriginal = [];
    foreach ($uploads as $u) {
        $c = guardar_upload($u);
        $caminhos[] = $c;
        $nomeOriginal[$c] = mb_substr($u['nome'], 0, 200);
    }

    // Gabarito digitado: sem formato ainda, cada folha é lida com o formato detectado,
    // e a primeira que servir define o formato e o caderno da prova.
    $semFormato = !formato_conhecido($layout);
    $leituras = ler_folhas($caminhos, $layout);
    if ($semFormato) {
        foreach ($leituras as &$l) {
            if ($l['ok'] && ($motivo = conferir_formato_da_folha($layout, $l, $gabarito)) !== null) {
                $l = ['ok' => false, 'erro' => $motivo, 'codigo' => 'formato'] + $l;
            }
        }
        unset($l);
    }

    $ins = db()->prepare('INSERT INTO folhas (prova_id, turma, arquivo, pagina, imagem, imagem_debug, respostas, niveis, revisar, erro)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id');
    $enviadas = ['folhas' => [], 'anotadas' => []];
    $resultado = [];
    try {
        db()->beginTransaction();
        foreach ($leituras as $l) {
            $nome = $nomeOriginal[$l['arquivo']] ?? basename($l['arquivo']);
            $item = ['id' => null, 'arquivo' => $nome, 'pagina' => $l['pagina'], 'ok' => $l['ok']];
            if ($l['ok']) {
                $prefixo = "$id/" . bin2hex(random_bytes(8));
                $imagem = $imagemDebug = null;
                if (isset($l['imagem_recorte'])) {
                    $imagem = $enviadas['folhas'][] = storage_enviar('folhas', "{$prefixo}_area.jpg", $l['imagem_recorte']);
                }
                if (isset($l['imagem_debug'])) {
                    $imagemDebug = $enviadas['anotadas'][] = storage_enviar('anotadas', "{$prefixo}_anotada.jpg", $l['imagem_debug']);
                }
                $ins->execute([
                    $id, $turma, $nome, $l['pagina'], $imagem, $imagemDebug,
                    json_encode(respostas_da_leitura($l)),
                    json_encode(array_column($l['respostas'], 'niveis')),
                    json_encode(questoes_para_revisar($l)),
                    null,
                ]);
                $item['id'] = (int)$ins->fetchColumn();
                $item['leitura'] = array_diff_key($l, ['arquivo' => 1, 'pagina' => 1, 'ok' => 1,
                    'imagem_debug' => 1, 'imagem_recorte' => 1]);
            } else {
                $item += ['erro' => $l['erro'], 'codigo' => $l['codigo'] ?? null];
                if ($guardarFalhas) {
                    $ins->execute([$id, $turma, $nome, $l['pagina'], null, null, null, null, '[]', $l['erro']]);
                    $item['id'] = (int)$ins->fetchColumn();
                }
            }
            $resultado[] = $item;
        }
        if ($semFormato && formato_conhecido($layout)) {
            $modelo = modelo_do_caderno($layout['caderno'] ?? null);
            db()->prepare('UPDATE provas SET layout = ?, caderno = ?, modelo = ? WHERE id = ?')
                ->execute([json_encode(layout_do_leitor($layout), JSON_UNESCAPED_UNICODE),
                    $layout['caderno'] ?? null, $modelo['chave'] ?? null, $id]);
        }
        db()->commit();
    } catch (Throwable $ex) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        apagar_do_storage($enviadas);
        throw $ex;
    }
    return $resultado;
}

/** Folha lida (sem erro), com as respostas, os níveis e as questões a revisar já decodificados. */
function buscar_folha(int $id): array
{
    $st = db()->prepare('SELECT * FROM folhas WHERE id = ? AND erro IS NULL');
    $st->execute([$id]);
    $folha = $st->fetch();
    if (!$folha) {
        throw new ErroDoUsuario('Folha não encontrada.', 404);
    }
    $folha['respostas'] = json_decode($folha['respostas'], true);
    $folha['niveis'] = json_decode($folha['niveis'] ?? '[]', true) ?? [];
    $folha['revisar'] = json_decode($folha['revisar'], true);
    $folha['revisada'] = in_array($folha['revisada'], [true, 't', 1, '1'], true);
    return $folha;
}

/**
 * Revisão manual de uma folha. $respostas tem uma entrada por questão, na ordem:
 * letra, "MULTIPLA" (marcação dupla) ou null/"" (em branco).
 */
function revisar_folha(array $folha, array $prova, array $respostas): void
{
    $alts = $prova['layout']['alternativas'];
    $n = count($prova['gabarito']);
    if (count($respostas) !== $n) {
        throw new ErroDoUsuario("Envie as respostas das $n questões.");
    }
    $novas = [];
    $invalidas = [];
    foreach (array_values($respostas) as $i => $v) {
        $v = $v === null ? '' : strtoupper(trim((string)$v));
        if ($v !== '' && $v !== 'MULTIPLA' && !in_array($v, $alts, true)) {
            $invalidas[] = $i + 1;
        }
        $novas[] = $v === '' ? null : $v;
    }
    if ($invalidas) {
        throw new ErroDoUsuario('Resposta inválida ' . (count($invalidas) === 1 ? 'na questão ' : 'nas questões ')
            . listar_numeros($invalidas) . '. Use ' . implode(', ', $alts) . ', MULTIPLA ou vazio.');
    }
    db()->prepare("UPDATE folhas SET respostas = ?, revisar = '[]', revisada = true WHERE id = ?")
        ->execute([json_encode($novas), $folha['id']]);
}

/** Folhas lidas da turma (as que não puderam ser lidas não contam). */
function contar_folhas_da_turma(int $provaId, string $turma): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM folhas WHERE prova_id = ? AND turma = ? AND erro IS NULL');
    $st->execute([$provaId, $turma]);
    return (int)$st->fetchColumn();
}

/**
 * Tudo o que o relatório da prova mostra, para uma turma ($turma) ou todas (''):
 * resumo por turma, estatísticas e prioridades por questão, habilidades com acerto
 * baixo e a comparação entre dificuldade marcada e observada.
 */
function relatorio_prova(array $prova, string $turma = ''): array
{
    if ($prova['status'] === 'rascunho') {
        throw new ErroDoUsuario('A prova ainda não tem gabarito aprovado.', 409);
    }
    $questoes = $prova['questoes'];
    [$lidas, $falhas] = carregar_folhas($prova);
    $resumo = resumo_por_turma($questoes, $lidas);
    $turmas = array_map('strval', array_keys($resumo)); // turma "7" vira chave inteira no array
    $turma = in_array($turma, $turmas, true) ? $turma : '';
    $visiveis = $turma !== '' ? array_values(array_filter($lidas, fn($f) => $f['turma'] === $turma)) : $lidas;
    $estatisticas = estatisticas_das_questoes($questoes, $visiveis);
    $matriz = matriz_de_dificuldade($estatisticas);
    return [
        'turma' => $turma,
        'turmas' => $turmas,
        'resumo' => $resumo,
        'lidas' => $lidas,
        'falhas' => $falhas,
        'visiveis' => $visiveis,
        'disciplinas' => array_values(array_unique(array_map('disciplina_da_questao', $questoes))),
        'niveis' => niveis_da_prova($questoes),
        'estatisticas' => $estatisticas,
        'prioridades' => prioridades_de_revisao($estatisticas),
        'habilidades' => array_values(array_filter(acerto_por_habilidade($estatisticas),
            fn($h) => $h['taxa'] !== null && $h['taxa'] < LIMITE_REFORCO)),
        'matriz' => $matriz,
        'comparadas' => array_sum(array_map('array_sum', $matriz)),
        'iguais' => array_sum(array_map(fn($n) => $matriz[$n][$n], array_keys(DIFICULDADES))),
        'divergentes' => array_values(array_filter($estatisticas, fn($e) => $e['diverge'])),
    ];
}
