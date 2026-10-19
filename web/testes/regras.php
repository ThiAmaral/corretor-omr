<?php
declare(strict_types=1);

/*
 * Testes das regras do PHP que não dependem do banco nem do Storage.
 * Rodar na raiz do projeto:  php web/testes/regras.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
ini_set('zend.assertions', '1');
ini_set('assert.exception', '1');
require dirname(__DIR__) . '/lib.php';
require dirname(__DIR__) . '/src/api.php';

$testes = 0;
function teste(string $nome, callable $corpo): void
{
    global $testes;
    try {
        $corpo();
        $testes++;
    } catch (Throwable $ex) {
        fwrite(STDERR, "FALHOU: $nome\n  " . $ex->getMessage() . "\n");
        exit(1);
    }
}

function igual(mixed $esperado, mixed $obtido): void
{
    if ($esperado !== $obtido) {
        throw new AssertionError('esperado ' . var_export($esperado, true) . ', obtido ' . var_export($obtido, true));
    }
}

/* ------------------------------------------------------------ Correção */

teste('corrigir: branco e dupla contam como erro', function () {
    igual([true, false, false, false], corrigir(['A', 'B', 'C', 'D'], ['A', null, 'MULTIPLA', 'A']));
});

teste('texto em Windows-1252 vira UTF-8', function () {
    igual(['nome' => 'Prova – Matemática', 'q' => [['disciplina' => 'Matemática']]],
        utf8_valido(['nome' => "Prova \x96 Matem\xe1tica", 'q' => [['disciplina' => "Matem\xe1tica"]]]));
    igual('já em UTF-8', utf8_valido('já em UTF-8'));
});

/* ------------------------------------------------- Modelos de caderno */

teste('caderno de Matemática vira disciplina automática', function () {
    $m = modelo_do_caderno('2265M0801');
    igual('ama_matematica', $m['chave']);
    igual('Matemática', $m['disciplina']);
    igual(8, $m['ano_escolar']);
    igual('AMA – Matemática 8º ano (M0801)', sugerir_nome_prova('2265M0801'));
});

teste('caderno de Língua Portuguesa', function () {
    igual('Língua Portuguesa', modelo_do_caderno('2265L0902')['disciplina']);
});

teste('caderno desconhecido ou ausente fica sem modelo', function () {
    igual(null, modelo_do_caderno('2265X0801'));
    igual(null, modelo_do_caderno(null));
    igual('Prova do caderno ABC', sugerir_nome_prova('ABC'));
    igual('Prova de ' . date('d/m/Y'), sugerir_nome_prova(null));
});

/* ------------------------------------------------- Rascunho do gabarito */

teste('leitura da foto: branco e dupla ficam sem resposta, fraca fica em destaque', function () {
    $leitura = ['respostas' => [
        ['questao' => 1, 'situacao' => 'marcada', 'resposta' => 'C', 'duvidosa' => false],
        ['questao' => 2, 'situacao' => 'marcada', 'resposta' => 'A', 'duvidosa' => true],
        ['questao' => 3, 'situacao' => 'branco', 'resposta' => null, 'duvidosa' => false],
        ['questao' => 4, 'situacao' => 'multipla', 'resposta' => null, 'duvidosa' => false],
    ]];
    $q = questoes_da_leitura($leitura, 'Matemática');
    igual(['C', 'A', null, null], array_column($q, 'resposta'));
    igual([false, true, true, true], array_column($q, 'duvidosa'));
    igual(array_fill(0, 4, 'Matemática'), array_column($q, 'disciplina'));
});

teste('gabarito digitado ignora espaços e minúsculas', function () {
    $q = questoes_digitadas('cb da-a');
    igual(['C', 'B', 'D', 'A', 'A'], array_column($q, 'resposta'));
    igual(['A', 'B', 'C', 'D'], alternativas_digitadas($q));
    igual(['A', 'B', 'C', 'D', 'E'], alternativas_digitadas(questoes_digitadas('ABE')));
});

teste('gabarito digitado vazio ou com letra além do E é recusado', function () {
    foreach (['', '  ', 'ABF'] as $texto) {
        try {
            questoes_digitadas($texto);
            throw new AssertionError("aceitou \"$texto\"");
        } catch (InvalidArgumentException) {
        }
    }
});

/* --------------------------------------------------- Revisão e aprovação */

function prova_exemplo(): array
{
    $questoes = [];
    foreach (['A', null, 'C'] as $i => $r) {
        $questoes[] = ['numero' => $i + 1, 'resposta' => $r, 'disciplina' => null, 'dificuldade' => null,
            'habilidade' => null, 'duvidosa' => $r === null];
    }
    return ['layout' => ['alternativas' => ['A', 'B', 'C', 'D']], 'questoes' => $questoes];
}

teste('rascunho pode ser salvo incompleto', function () {
    [$q, $erros] = validar_revisao([['resposta' => 'A'], ['resposta' => ''], ['resposta' => 'c']], prova_exemplo(), false);
    igual([], $erros);
    igual(['A', null, 'C'], array_column($q, 'resposta'));
    igual([false, true, false], array_column($q, 'duvidosa'));
});

teste('aprovação exige resposta e disciplina em todas as questões', function () {
    [, $erros] = validar_revisao([
        ['resposta' => 'A', 'disciplina' => 'Matemática'],
        ['resposta' => '', 'disciplina' => ''],
        ['resposta' => 'C', 'disciplina' => ''],
    ], prova_exemplo(), true);
    igual(['Falta a resposta da questão 2.', 'Falta a disciplina das questões 2, 3.'], $erros);
});

teste('aprovação completa passa, com dificuldade e habilidade', function () {
    [$q, $erros] = validar_revisao([
        ['resposta' => 'A', 'disciplina' => 'Matemática', 'dificuldade' => 'facil', 'habilidade' => ' d07 '],
        ['resposta' => 'B', 'disciplina' => 'Matemática', 'dificuldade' => 'dificil'],
        ['resposta' => 'C', 'disciplina' => 'Língua Portuguesa', 'dificuldade' => 'xyz'],
    ], prova_exemplo(), true);
    igual([], $erros);
    igual(['facil', 'dificil', null], array_column($q, 'dificuldade'));
    igual(['D07', null, null], array_column($q, 'habilidade'));
    igual([false, false, false], array_column($q, 'duvidosa'));
});

teste('alternativa e disciplina fora da lista são recusadas', function () {
    [, $erros] = validar_revisao([
        ['resposta' => 'E', 'disciplina' => 'Matemática'],
        ['resposta' => 'B', 'disciplina' => 'Astrologia'],
        ['resposta' => 'C', 'disciplina' => 'Matemática'],
    ], prova_exemplo(), false);
    igual(['A resposta da questão 1 precisa ser uma das alternativas (A, B, C, D).',
        'A disciplina da questão 2 não está na lista.'], $erros);
});

teste('disciplina antiga gravada na prova continua aceita', function () {
    $prova = prova_exemplo();
    $prova['questoes'][0]['disciplina'] = 'Linguagens';
    [$q, $erros] = validar_revisao([['resposta' => 'A', 'disciplina' => 'Linguagens']], $prova, false);
    igual([], $erros);
    igual('Linguagens', $q[0]['disciplina']);
});

teste('listar_numeros junta intervalos', function () {
    igual('1 a 3, 5, 7, 8 e 10', listar_numeros([10, 1, 2, 3, 5, 7, 8]));
    igual('4', listar_numeros([4]));
});

teste('faixas de disciplina remontadas das questões', function () {
    $q = [['numero' => 1, 'disciplina' => 'LP'], ['numero' => 2, 'disciplina' => 'LP'],
        ['numero' => 3, 'disciplina' => null], ['numero' => 4, 'disciplina' => 'MAT']];
    igual([['nome' => 'LP', 'de' => 1, 'ate' => 2], ['nome' => 'MAT', 'de' => 4, 'ate' => 4]], areas_das_questoes($q));
});

/* --------------------------------- Formato vindo da 1ª folha (gabarito digitado) */

function leitura_ama(string $caderno = '2265M0801', array $blocos = [7, 7, 7, 5]): array
{
    return ['caderno' => $caderno, 'layout' => ['caderno' => $caderno, 'alternativas' => ['A', 'B', 'C', 'D'],
        'blocos' => $blocos, 'questoes' => array_sum($blocos)]];
}

teste('primeira folha define formato e caderno', function () {
    $layout = ['questoes' => 26, 'alternativas' => ['A', 'B', 'C', 'D']];
    igual(null, conferir_formato_da_folha($layout, leitura_ama(), array_fill(0, 26, 'A')));
    igual('2265M0801', $layout['caderno']);
    igual([7, 7, 7, 5], $layout['blocos']);
    igual(true, formato_conhecido($layout));
    igual('Caderno 2265M0801 · 26 questões em 4 blocos (7, 7, 7, 5) · alternativas A–D', descrever_formato($layout, 26));
});

teste('folhas seguintes precisam ser do mesmo caderno', function () {
    $layout = ['questoes' => 26, 'alternativas' => ['A', 'B', 'C', 'D']];
    $gab = array_fill(0, 26, 'B');
    conferir_formato_da_folha($layout, leitura_ama(), $gab);
    igual(null, conferir_formato_da_folha($layout, leitura_ama(), $gab));
    igual('Esta folha é do caderno 2265L0801, e a prova é do caderno 2265M0801.',
        conferir_formato_da_folha($layout, leitura_ama('2265L0801'), $gab));
});

teste('número de questões diferente do digitado é recusado', function () {
    $layout = ['questoes' => 20, 'alternativas' => ['A', 'B', 'C', 'D']];
    igual('Esta folha tem 26 questões, e o gabarito da prova tem 20.',
        conferir_formato_da_folha($layout, leitura_ama(), array_fill(0, 20, 'A')));
    igual(false, formato_conhecido($layout));
});

teste('gabarito com alternativa que a folha não tem é recusado', function () {
    $layout = ['questoes' => 26, 'alternativas' => ['A', 'B', 'C', 'D', 'E']];
    $gab = array_fill(0, 26, 'A');
    $gab[3] = 'E';
    igual('O gabarito usa a alternativa E, que não existe nesta folha (A, B, C, D).',
        conferir_formato_da_folha($layout, leitura_ama(), $gab));
});

/* ------------------------------------------- Relatórios por dificuldade */

/** Prova de 5 questões: 1–3 Matemática (fácil, fácil, médio), 4–5 Língua Portuguesa (difícil, sem nível). */
function questoes_do_relatorio(): array
{
    $q = fn($n, $r, $d, $dif, $h) => ['numero' => $n, 'resposta' => $r, 'disciplina' => $d, 'dificuldade' => $dif, 'habilidade' => $h];
    return [$q(1, 'A', 'Matemática', 'facil', 'D07'), $q(2, 'B', 'Matemática', 'facil', ' D07 '),
        $q(3, 'C', 'Matemática', 'medio', ''), $q(4, 'D', 'Língua Portuguesa', 'dificil', 'D12'),
        $q(5, 'A', 'Língua Portuguesa', null, null)];
}

/** Quatro alunos da 8A e um da 10A, já corrigidos. */
function folhas_do_relatorio(): array
{
    $gab = array_column(questoes_do_relatorio(), 'resposta');
    $f = fn($t, $r, $rev = []) => corrigir_folha(['turma' => $t, 'respostas' => $r, 'revisar' => $rev], $gab);
    return [$f('8A', ['A', 'B', 'C', 'D', 'A']), $f('8A', ['B', 'B', 'A', null, 'A']),
        $f('8A', ['B', 'C', 'A', 'C', 'MULTIPLA'], [5]), $f('8A', ['C', 'B', 'A', 'C', null]),
        $f('10A', ['A', 'B', 'A', 'D', 'B'])];
}

function folhas_da_turma(string $turma): array
{
    return array_values(array_filter(folhas_do_relatorio(), fn($f) => $f['turma'] === $turma));
}

teste('dificuldade observada pelas faixas de acerto', function () {
    igual('facil', dificuldade_observada(70.1));
    igual('medio', dificuldade_observada(70.0));
    igual('medio', dificuldade_observada(30.0));
    igual('dificil', dificuldade_observada(29.9));
    igual(null, dificuldade_observada(null));
});

teste('resumo por turma: acerto geral, por disciplina e por nível', function () {
    $r = resumo_por_turma(questoes_do_relatorio(), folhas_do_relatorio());
    igual(['8A', '10A'], array_keys($r)); // ordem natural das turmas
    igual(4, $r['8A']['folhas']);
    igual(40.0, pct($r['8A']['acertos'], $r['8A']['total']));
    igual(3, $r['8A']['reforco']); // 40%, 0% e 20%
    igual(1, $r['8A']['revisar']);
    igual(['Matemática' => [5, 12], 'Língua Portuguesa' => [3, 8]], $r['8A']['disciplinas']);
    igual(['facil' => [4, 8], 'medio' => [1, 4], 'dificil' => [1, 4]], $r['8A']['niveis']);
    igual(['facil', 'medio', 'dificil'], niveis_da_prova(questoes_do_relatorio()));
});

teste('estatísticas por questão: acerto, erro comum, brancos e divergência', function () {
    $e = estatisticas_das_questoes(questoes_do_relatorio(), folhas_da_turma('8A'));
    igual([25.0, 75.0, 25.0, 25.0, 50.0], array_column($e, 'taxa'));
    igual(['B', 'C', 'A', 'C', null], array_column($e, 'distrator'));
    igual([2, 1, 3, 2, 0], array_column($e, 'distrator_qtd'));
    igual([0, 0, 0, 1, 2], array_column($e, 'brancos'));
    igual(['dificil', 'facil', 'dificil', 'dificil', 'medio'], array_column($e, 'observada'));
    igual([true, false, true, false, false], array_column($e, 'diverge')); // sem nível marcado não diverge
    igual(['D07', 'D07', null, 'D12', null], array_column($e, 'habilidade'));
});

teste('erro comum empatado fica com a primeira letra', function () {
    $q = [['numero' => 1, 'resposta' => 'A', 'disciplina' => null, 'dificuldade' => null, 'habilidade' => null]];
    $f = [['respostas' => ['C']], ['respostas' => ['B']]];
    $e = estatisticas_das_questoes($q, $f)[0];
    igual('B', $e['distrator']);
    igual('Geral', $e['disciplina']);
});

teste('prioridades: fáceis e médias primeiro, depois sem nível, difíceis por último', function () {
    $p = prioridades_de_revisao(estatisticas_das_questoes(questoes_do_relatorio(), folhas_da_turma('8A')));
    igual([1, 3, 5, 4], array_column($p, 'numero')); // a 2 (75%) está acima do limite
    igual('Marcada como fácil, mas só 25,0% acertaram. Erro comum: B (2 alunos).', $p[0]['motivo']);
    igual('Marcada como médio, mas só 25,0% acertaram. Erro comum: A (3 alunos).', $p[1]['motivo']);
    igual('50,0% de acerto (sem nível marcado). 2 em branco ou com marcação dupla.', $p[2]['motivo']);
    igual('Questão difícil: 25,0% de acerto. Erro comum: C (2 alunos).', $p[3]['motivo']);
});

teste('prioridades: no mesmo grupo, menor acerto primeiro', function () {
    $e = fn($n, $dif, $taxa) => ['numero' => $n, 'dificuldade' => $dif, 'taxa' => $taxa, 'alunos' => 10,
        'brancos' => 0, 'distrator' => 'B', 'distrator_qtd' => 1];
    $p = prioridades_de_revisao([$e(1, 'medio', 10.0), $e(2, 'facil', 50.0), $e(3, 'facil', 10.0), $e(4, 'dificil', 0.0)]);
    igual([3, 1, 2, 4], array_column($p, 'numero'));
    igual('Marcada como médio, mas só 10,0% acertaram. Erro comum: B (1 aluno).', $p[1]['motivo']);
});

teste('sem folhas não há prioridades nem dificuldade observada', function () {
    $e = estatisticas_das_questoes(questoes_do_relatorio(), []);
    igual([null, null, null, null, null], array_column($e, 'taxa'));
    igual([], prioridades_de_revisao($e));
    igual(0, array_sum(array_map('array_sum', matriz_de_dificuldade($e))));
});

teste('acerto por habilidade junta as questões, do menor para o maior', function () {
    $h = acerto_por_habilidade(estatisticas_das_questoes(questoes_do_relatorio(), folhas_da_turma('8A')));
    igual(['D12', 'D07'], array_column($h, 'habilidade'));
    igual([[4], [1, 2]], array_column($h, 'questoes'));
    igual([25.0, 50.0], array_column($h, 'taxa'));
});

teste('matriz de dificuldade marcada × observada', function () {
    $m = matriz_de_dificuldade(estatisticas_das_questoes(questoes_do_relatorio(), folhas_da_turma('8A')));
    igual(['facil' => 1, 'medio' => 0, 'dificil' => 1], $m['facil']);
    igual(['facil' => 0, 'medio' => 0, 'dificil' => 1], $m['medio']);
    igual(['facil' => 0, 'medio' => 0, 'dificil' => 1], $m['dificil']);
});

teste('filtros do mapa por disciplina e dificuldade', function () {
    $e = estatisticas_das_questoes(questoes_do_relatorio(), folhas_do_relatorio());
    igual([1, 2, 3], array_column(filtrar_questoes($e, 'Matemática', ''), 'numero'));
    igual([5], array_column(filtrar_questoes($e, '', 'sem'), 'numero'));
    igual([4], array_column(filtrar_questoes($e, 'Língua Portuguesa', 'dificil'), 'numero'));
    igual([1, 2, 3, 4, 5], array_column(filtrar_questoes($e, '', ''), 'numero'));
});

/* ------------------------------------------------------- API e login */

/** Roda a função e devolve o ErroDoUsuario esperado (falha se não lançar). */
function erro_de(callable $corpo): ErroDoUsuario
{
    try {
        $corpo();
    } catch (ErroDoUsuario $ex) {
        return $ex;
    }
    throw new AssertionError('esperava ErroDoUsuario');
}

/** JWT de mentira (sem assinatura válida), só para testar a leitura do conteúdo. */
function jwt_de_teste(array $conteudo): string
{
    $b64 = fn($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    return $b64('{"alg":"HS256"}') . '.' . $b64(json_encode($conteudo)) . '.assinatura';
}

teste('caminho da API pela URL', function () {
    igual('provas/4/folhas', caminho_da_api('/api/provas/4/folhas?turma=8A'));
    igual('provas', caminho_da_api('/api/index.php/provas'));
    igual('provas', caminho_da_api('/corretor/api/provas/'));
    igual('', caminho_da_api('/api'));
});

teste('rotas: função certa, id numérico, 404 e 405', function () {
    $rotas = rotas_da_api();
    igual(['api_listar_provas', []], encontrar_rota('GET', 'provas', $rotas));
    igual(['api_cadastrar_prova', []], encontrar_rota('POST', 'provas', $rotas));
    igual(['api_salvar_questoes', ['id' => 12]], encontrar_rota('PUT', 'provas/12/questoes', $rotas));
    igual(['api_revisar_folha', ['id' => 7]], encontrar_rota('PUT', 'folhas/7', $rotas));
    igual(404, erro_de(fn() => encontrar_rota('GET', 'provas/abc', $rotas))->status);
    igual(404, erro_de(fn() => encontrar_rota('GET', 'provas/0', $rotas))->status);
    igual(404, erro_de(fn() => encontrar_rota('GET', 'usuarios', $rotas))->status);
    $ex = erro_de(fn() => encontrar_rota('PATCH', 'provas/3', $rotas));
    igual(405, $ex->status);
    igual(['GET', 'DELETE'], $ex->detalhes['permitidos']);
});

teste('edições do simulado no lugar dos bimestres', function () {
    igual('edicao', PERIODO_ESCOLA);
    igual([1 => '1ª edição', 2 => '2ª edição'], periodos());
    igual('Edição', nome_da_divisao());
    igual('2ª edição', nome_periodo(2));
    igual('4ª edição', nome_periodo(4)); // prova antiga, cadastrada como 4º bimestre
    igual('–', nome_periodo(null));
});

teste('CORS só para as origens configuradas', function () {
    igual([], cabecalhos_cors('http://localhost:8081', ''));
    igual([], cabecalhos_cors('', 'http://localhost:8081'));
    igual([], cabecalhos_cors('http://outro.site', 'http://localhost:8081'));
    $c = cabecalhos_cors('http://localhost:8081', 'http://localhost:19006, http://localhost:8081');
    igual('Access-Control-Allow-Origin: http://localhost:8081', $c[0]);
    igual('Vary: Origin', $c[1]);
});

teste('token Bearer do cabeçalho', function () {
    igual('abc.def.ghi', token_do_cabecalho(['HTTP_AUTHORIZATION' => 'Bearer abc.def.ghi']));
    igual('abc', token_do_cabecalho(['REDIRECT_HTTP_AUTHORIZATION' => 'bearer   abc ']));
    igual(null, token_do_cabecalho(['HTTP_AUTHORIZATION' => 'Basic dXN1YXJpbzpzZW5oYQ==']));
    igual(null, token_do_cabecalho([]));
});

teste('token vencido ou que não é JWT é recusado sem chamar o Supabase', function () {
    igual(['sub' => 'x', 'exp' => 100], conteudo_do_jwt(jwt_de_teste(['sub' => 'x', 'exp' => 100])));
    igual(null, conteudo_do_jwt('nao-e-um-jwt'));
    igual(null, professor_do_token(jwt_de_teste(['sub' => 'x', 'exp' => time() - 10])));
    igual(null, professor_do_token('nao-e-um-jwt'));
});

teste('token conferido fica em cache até vencer', function () {
    $token = jwt_de_teste(['sub' => 'p1', 'exp' => time() + 3600, 'teste' => random_int(0, PHP_INT_MAX)]);
    $cache = arquivo_cache_token($token);
    $professor = ['id' => '00000000-0000-0000-0000-000000000001', 'email' => 'prof@escola.br'];
    file_put_contents($cache, json_encode(['professor' => $professor, 'ate' => time() + 60]));
    igual($professor, professor_do_token($token));
    unlink($cache);
    igual(false, str_contains($cache, $token)); // o token nunca vai para o nome do arquivo
});

teste('CSRF: só o token da sessão passa', function () {
    $_SESSION = [];
    $token = token_csrf();
    igual($token, token_csrf());
    conferir_csrf($token);
    igual(403, erro_de(fn() => conferir_csrf('outro'))->status);
    igual(403, erro_de(fn() => conferir_csrf(null))->status);
    $_SESSION = [];
    igual(403, erro_de(fn() => conferir_csrf($token))->status);
});

teste('volta do login só para caminhos deste site', function () {
    igual('/prova.php?id=4', destino_seguro('/prova.php?id=4'));
    igual('index.php', destino_seguro('//outro.site/x'));
    igual('index.php', destino_seguro('/' . chr(92) . 'outro.site'));
    igual('index.php', destino_seguro('https://outro.site'));
    igual('index.php', destino_seguro(''));
});

teste('tipo real do arquivo, não a extensão', function () {
    $raiz = dirname(__DIR__, 2);
    igual('jpg', tipo_do_arquivo("$raiz/exemplos/ama/geradas_8A/gabarito.jpg"));
    igual('png', tipo_do_arquivo("$raiz/exemplos/gabarito_oficial.png"));
    igual('pdf', tipo_do_arquivo("$raiz/exemplos/ama/AMA_2026_M0801_folha_em_branco.pdf"));
    $falso = tempnam(sys_get_temp_dir(), 'falso');
    file_put_contents($falso, "<?php echo 'não sou uma foto';");
    igual(null, tipo_do_arquivo($falso));
    unlink($falso);
});

teste('upload grande demais ou de outro tipo é recusado', function () {
    $falso = tempnam(sys_get_temp_dir(), 'falso');
    file_put_contents($falso, 'texto');
    $campo = fn($tmp, $erro = UPLOAD_ERR_OK) => ['name' => 'foto.jpg', 'tmp_name' => $tmp, 'error' => $erro, 'size' => 5];
    igual(415, erro_de(fn() => upload_normalizado($campo($falso)))->status);
    igual(413, erro_de(fn() => upload_normalizado($campo($falso, UPLOAD_ERR_INI_SIZE)))->status);
    igual([], upload_normalizado($campo('', UPLOAD_ERR_NO_FILE)));
    unlink($falso);
    $foto = dirname(__DIR__, 2) . '/exemplos/ama/geradas_8A/gabarito.jpg';
    igual('jpg', upload_normalizado(['name' => ['x.png'], 'tmp_name' => [$foto], 'error' => [0], 'size' => [1]])[0]['ext']);
});

teste('tamanho do post_max_size em bytes', function () {
    igual(200 * 1024 * 1024, tamanho_em_bytes('200M'));
    igual(2 * 1024 ** 3, tamanho_em_bytes('2G'));
    igual(512, tamanho_em_bytes('512'));
});

teste('turma: espaços limpos, vazia ou longa recusada', function () {
    igual('8A', turma_valida('  8A '));
    igual('8º ano A', turma_valida("8º  ano\tA"));
    igual(422, erro_de(fn() => turma_valida('   '))->status);
    igual(422, erro_de(fn() => turma_valida(str_repeat('x', 21)))->status);
});

teste('revisão pela API: só as questões e os campos enviados mudam', function () {
    $atuais = [
        ['numero' => 1, 'resposta' => 'A', 'disciplina' => 'Matemática', 'dificuldade' => null, 'habilidade' => null, 'duvidosa' => false],
        ['numero' => 2, 'resposta' => null, 'disciplina' => 'Matemática', 'dificuldade' => 'facil', 'habilidade' => 'D07', 'duvidosa' => true],
    ];
    $entrada = entrada_da_revisao($atuais, [['numero' => 2, 'resposta' => 'C', 'habilidade' => null]]);
    igual(['resposta' => 'A', 'disciplina' => 'Matemática', 'dificuldade' => null, 'habilidade' => null], $entrada[0]);
    igual(['resposta' => 'C', 'disciplina' => 'Matemática', 'dificuldade' => 'facil', 'habilidade' => ''], $entrada[1]);
    igual('A', entrada_da_revisao($atuais, [])[0]['resposta']);
    igual('A prova não tem a questão 3 e 9.', erro_de(fn() => entrada_da_revisao($atuais, [['numero' => 3], ['numero' => 9]]))->getMessage());
    igual(400, erro_de(fn() => entrada_da_revisao($atuais, [['resposta' => 'B']]))->status);
});

teste('revisão pela API passa pela mesma validação das páginas', function () {
    $prova = ['layout' => ['alternativas' => ['A', 'B', 'C', 'D']], 'questoes' => [
        ['numero' => 1, 'resposta' => 'A', 'disciplina' => 'Matemática', 'dificuldade' => null, 'habilidade' => null, 'duvidosa' => false],
    ]];
    [, $erros] = validar_revisao(entrada_da_revisao($prova['questoes'], [['numero' => 1, 'resposta' => 'E']]), $prova, true);
    igual(['A resposta da questão 1 precisa ser uma das alternativas (A, B, C, D).', 'Falta a resposta da questão 1.'], $erros);
});

teste('respostas de folha: lista completa ou só as que mudaram', function () {
    $atuais = ['A', null, 'MULTIPLA'];
    igual(['B', 'C', 'D'], respostas_da_revisao($atuais, ['B', 'C', 'D']));
    igual(['A', 'B', null], respostas_da_revisao($atuais, ['2' => 'B', '3' => null]));
    igual(422, erro_de(fn() => respostas_da_revisao($atuais, ['4' => 'A']))->status);
    igual(400, erro_de(fn() => respostas_da_revisao($atuais, 'ABC'))->status);
});

teste('folha corrigida no formato da API', function () {
    $f = folha_json(['id' => '3', 'prova_id' => '1', 'turma' => '8A', 'arquivo' => 'a.jpg', 'pagina' => '1',
        'respostas' => ['A', 'C', null], 'revisar' => [2], 'revisada' => 'f'], ['A', 'B', 'C']);
    igual([true, false, false], $f['acertos']);
    igual(1, $f['n_acertos']);
    igual(33.3, $f['taxa']);
    igual(false, $f['revisada']);
});

teste('data do banco em ISO 8601 no horário de Brasília', function () {
    igual('2026-10-12T11:03:00-03:00', data_iso('2026-10-12 14:03:00.123456+00'));
    igual(null, data_iso(null));
});

teste('nome guardado da folha é genérico, nunca o original', function () {
    igual('7A_folha03.jpg', nome_generico('7A', 3, 'jpg'));
    igual('9Z-APP_folha12.pdf', nome_generico('9Z-APP', 12, 'pdf'));
    igual('7Atarde_folha01.jpg', nome_generico('7A tarde/../', 1, 'jpg'));
});

echo "$testes testes passaram.\n";
