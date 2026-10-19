<?php
declare(strict_types=1);

/*
 * API REST para o aplicativo (Fase 6). O controlador frontal é api/index.php.
 * Toda rota exige "Authorization: Bearer <token do Supabase Auth>".
 * As respostas são JSON; os erros saem como {"erro": "..."} com o código HTTP certo:
 * 400 requisição malformada, 401 sem login, 403 proibido, 404 não encontrado,
 * 405 método errado, 409 estado da prova não permite, 413 arquivo grande demais,
 * 415 tipo de arquivo, 422 dado inválido, 500 erro no servidor.
 * As funções de cada rota só traduzem HTTP <-> funções de src/servicos.php.
 */

/** [método, caminho, função]. {id} é um número inteiro. */
function rotas_da_api(): array
{
    return [
        ['GET', 'config', 'api_config'],
        ['GET', 'provas', 'api_listar_provas'],
        ['POST', 'provas', 'api_cadastrar_prova'],
        ['GET', 'provas/{id}', 'api_ver_prova'],
        ['DELETE', 'provas/{id}', 'api_descartar_prova'],
        ['PUT', 'provas/{id}/questoes', 'api_salvar_questoes'],
        ['POST', 'provas/{id}/aprovar', 'api_aprovar_prova'],
        ['GET', 'provas/{id}/folhas', 'api_listar_folhas'],
        ['POST', 'provas/{id}/folhas', 'api_enviar_folhas'],
        ['GET', 'provas/{id}/relatorio', 'api_relatorio'],
        ['GET', 'folhas/{id}', 'api_ver_folha'],
        ['PUT', 'folhas/{id}', 'api_revisar_folha'],
    ];
}

/** Caminho da rota a partir da URL: "/api/provas/4" ou "/api/index.php/provas/4" -> "provas/4". */
function caminho_da_api(string $uri): string
{
    $caminho = parse_url($uri, PHP_URL_PATH) ?: '/';
    return preg_match('#/api(?:/index\.php)?(/.*)?$#', $caminho, $m) ? trim($m[1] ?? '', '/') : '';
}

/**
 * Escolhe a função da rota. Devolve [função, ['id' => ...]].
 * Caminho desconhecido: 404. Caminho certo com outro método: 405.
 */
function encontrar_rota(string $metodo, string $caminho, array $rotas): array
{
    $permitidos = [];
    foreach ($rotas as [$m, $modelo, $funcao]) {
        $regex = '#^' . str_replace('\{id\}', '(?P<id>[1-9]\d{0,17})', preg_quote($modelo, '#')) . '$#';
        if (!preg_match($regex, $caminho, $partes)) {
            continue;
        }
        if ($m === $metodo) {
            return [$funcao, isset($partes['id']) ? ['id' => (int)$partes['id']] : []];
        }
        $permitidos[] = $m;
    }
    if ($permitidos) {
        throw new ErroDoUsuario("Use $caminho com " . implode(' ou ', $permitidos) . '.', 405, ['permitidos' => $permitidos]);
    }
    throw new ErroDoUsuario('Rota não encontrada: ' . ($caminho === '' ? '/' : $caminho), 404);
}

/** Atende uma requisição à API: autentica, chama a função da rota e escreve o JSON. */
function atender_api(array $servidor): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    $metodo = strtoupper($servidor['REQUEST_METHOD'] ?? 'GET');
    foreach (cabecalhos_cors($servidor['HTTP_ORIGIN'] ?? '', (string)getenv('CORS_ORIGENS')) as $cabecalho) {
        header($cabecalho);
    }
    if ($metodo === 'OPTIONS') {
        http_response_code(204); // pré-verificação do navegador; o app no celular não faz
        return;
    }
    try {
        [$funcao, $params] = encontrar_rota($metodo, caminho_da_api($servidor['REQUEST_URI'] ?? '/'), rotas_da_api());
        $professor = autenticar_api($servidor);
        conferir_tamanho_do_envio($servidor);
        [$status, $corpo] = $funcao($params, $professor);
    } catch (ErroDoUsuario $ex) {
        $status = $ex->status;
        $corpo = ['erro' => $ex->getMessage()] + $ex->detalhes;
        if ($status === 401) {
            header('WWW-Authenticate: Bearer');
        } elseif ($status === 405) {
            header('Allow: ' . implode(', ', $ex->detalhes['permitidos']));
        }
    } catch (Throwable $ex) {
        error_log((string)$ex);
        $status = 500;
        $corpo = ['erro' => 'Erro no servidor. Tente de novo; se continuar, avise a coordenação.'];
    }
    http_response_code($status);
    if ($status !== 204) {
        echo json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }
}

/**
 * Cabeçalhos CORS para a versão web do app em desenvolvimento (npx expo start --web).
 * O app no celular não precisa deles. Só vale para as origens listadas em CORS_ORIGENS
 * (separadas por vírgula, ex.: http://localhost:8081); sem a variável, nenhuma.
 */
function cabecalhos_cors(string $origem, string $permitidas): array
{
    $lista = array_filter(array_map('trim', explode(',', $permitidas)));
    if ($origem === '' || !in_array($origem, $lista, true)) {
        return [];
    }
    return [
        "Access-Control-Allow-Origin: $origem",
        'Vary: Origin',
        'Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS',
        'Access-Control-Allow-Headers: Authorization, Content-Type',
        'Access-Control-Max-Age: 600',
    ];
}

/** Professor do token enviado pelo app; sem token válido, 401. */
function autenticar_api(array $servidor): array
{
    if (!isset($servidor['HTTP_AUTHORIZATION']) && function_exists('getallheaders')) {
        // No Apache com mod_php, o cabeçalho Authorization nem sempre chega em $_SERVER.
        foreach (getallheaders() as $nome => $valor) {
            if (strcasecmp($nome, 'Authorization') === 0) {
                $servidor['HTTP_AUTHORIZATION'] = $valor;
            }
        }
    }
    $token = token_do_cabecalho($servidor);
    if ($token === null) {
        throw new ErroDoUsuario('Faça login: envie o token no cabeçalho Authorization: Bearer <token>.', 401);
    }
    return professor_do_token($token) ?? throw new ErroDoUsuario('Sessão expirada ou inválida. Entre de novo.', 401);
}

/** Envio maior que o post_max_size chega sem nenhum campo; avisa o motivo em vez de "falta a foto". */
function conferir_tamanho_do_envio(array $servidor): void
{
    $enviado = (int)($servidor['CONTENT_LENGTH'] ?? 0);
    $limite = tamanho_em_bytes((string)ini_get('post_max_size'));
    if ($limite > 0 && $enviado > $limite) {
        throw new ErroDoUsuario('O envio passa do tamanho máximo aceito pelo servidor.', 413);
    }
}

/** "200M" -> 209715200 */
function tamanho_em_bytes(string $valor): int
{
    $valor = trim($valor);
    $numero = (int)$valor;
    return match (strtoupper(substr($valor, -1))) {
        'G' => $numero * 1024 ** 3,
        'M' => $numero * 1024 ** 2,
        'K' => $numero * 1024,
        default => $numero,
    };
}

/** Corpo JSON da requisição (PUT/POST com Content-Type: application/json). Vazio = []. */
function corpo_json(): array
{
    $texto = (string)file_get_contents('php://input');
    if (trim($texto) === '') {
        return [];
    }
    $dados = json_decode($texto, true);
    if (!is_array($dados)) {
        throw new ErroDoUsuario('O corpo da requisição precisa ser um objeto JSON.', 400);
    }
    return utf8_valido($dados);
}

/* --------------------------------------------------------- Formato JSON */

/** "2026-10-12 14:03:00.12+00" -> "2026-10-12T11:03:00-03:00" */
function data_iso(?string $data): ?string
{
    return $data === null ? null : date(DATE_ATOM, strtotime($data));
}

/** Prova para o app. Com $detalhe, as questões, o catálogo de disciplinas e a imagem do gabarito. */
function prova_json(array $prova, bool $detalhe = true): array
{
    $layout = $prova['layout'];
    $json = [
        'id' => (int)$prova['id'],
        'nome' => $prova['nome'],
        'ano' => (int)$prova['ano'],
        'periodo' => $prova['periodo'] === null ? null : (int)$prova['periodo'],
        'periodo_nome' => nome_periodo($prova['periodo'] === null ? null : (int)$prova['periodo']),
        'status' => $prova['status'],
        'caderno' => $prova['caderno'],
        'modelo' => modelo_do_caderno($prova['caderno'])['rotulo'] ?? null,
        'criado_em' => data_iso($prova['criado_em']),
        'criado_por' => $prova['criado_por'],
        'formato_conhecido' => formato_conhecido($layout),
        'formato' => formato_conhecido($layout) ? descrever_formato($layout, count($prova['questoes'])) : null,
        'alternativas' => $layout['alternativas'],
        'blocos' => $layout['blocos'] ?? null,
        'questoes' => array_map(fn($q) => [
            'numero' => (int)$q['numero'],
            'resposta' => $q['resposta'],
            'disciplina' => $q['disciplina'],
            'dificuldade' => $q['dificuldade'],
            'habilidade' => $q['habilidade'],
            'duvidosa' => $q['duvidosa'],
        ], $prova['questoes']),
    ];
    if ($detalhe) {
        $json['disciplinas'] = disciplinas_da_prova($prova['questoes']);
        $json['imagem_gabarito_url'] = $prova['imagem_gabarito'] ? storage_url_assinada('anotadas', $prova['imagem_gabarito']) : null;
    }
    return $json;
}

/** Folha lida e corrigida: respostas, acertos por questão, nota e questões a revisar. */
function folha_json(array $folha, array $gabarito): array
{
    $folha = corrigir_folha($folha, $gabarito);
    return [
        'id' => (int)$folha['id'],
        'prova_id' => (int)$folha['prova_id'],
        'turma' => $folha['turma'],
        'arquivo' => $folha['arquivo'],
        'pagina' => (int)$folha['pagina'],
        'respostas' => $folha['respostas'],
        'acertos' => $folha['acertos'],
        'n_acertos' => $folha['n_acertos'],
        'questoes' => count($gabarito),
        'taxa' => $folha['taxa'],
        'revisar' => $folha['revisar'],
        'revisada' => in_array($folha['revisada'], [true, 't', 1, '1'], true),
        'criado_em' => data_iso($folha['criado_em'] ?? null),
    ];
}

/** Uma porcentagem por grupo: [acertos, total] -> 63.1 */
function porcentagens(array $grupos): array
{
    return array_map(fn($v) => pct($v[0], $v[1]), $grupos);
}

/**
 * Junta o que o app enviou ({"numero": 3, "resposta": "B", ...}, só as questões e os
 * campos que mudaram) com o gabarito atual. Devolve a entrada de validar_revisao(),
 * uma linha por questão na ordem. Campo enviado como null apaga o valor.
 */
function entrada_da_revisao(array $questoesAtuais, array $enviadas): array
{
    $campos = ['resposta', 'disciplina', 'dificuldade', 'habilidade'];
    $entrada = [];
    $posicao = [];
    foreach (array_values($questoesAtuais) as $i => $q) {
        $entrada[$i] = array_intersect_key($q, array_flip($campos));
        $posicao[(int)$q['numero']] = $i;
    }
    $inexistentes = [];
    foreach ($enviadas as $q) {
        $n = is_array($q) ? filter_var($q['numero'] ?? null, FILTER_VALIDATE_INT) : false;
        if ($n === false) {
            throw new ErroDoUsuario('Cada questão enviada precisa do campo "numero".', 400);
        }
        if (!isset($posicao[$n])) {
            $inexistentes[] = $n;
            continue;
        }
        foreach ($campos as $c) {
            if (array_key_exists($c, $q)) {
                $entrada[$posicao[$n]][$c] = $q[$c] === null ? '' : (is_scalar($q[$c]) ? (string)$q[$c] : '');
            }
        }
    }
    if ($inexistentes) {
        throw new ErroDoUsuario('A prova não tem a questão ' . listar_numeros($inexistentes) . '.');
    }
    return $entrada;
}

/**
 * Respostas de uma folha enviadas pelo app: a lista completa (uma por questão) ou só as
 * que mudaram, por número ({"12": "B", "13": null}). Devolve a lista completa.
 */
function respostas_da_revisao(array $atuais, mixed $enviadas): array
{
    if (!is_array($enviadas)) {
        throw new ErroDoUsuario('Envie "respostas": a lista completa ou {"numero": "letra"}.', 400);
    }
    if (array_is_list($enviadas)) {
        return $enviadas;
    }
    $novas = $atuais;
    foreach ($enviadas as $numero => $valor) {
        $i = (int)$numero - 1;
        if (!array_key_exists($i, $novas)) {
            throw new ErroDoUsuario("A prova não tem a questão $numero.");
        }
        $novas[$i] = $valor;
    }
    return $novas;
}

/* ---------------------------------------------------- Funções das rotas */

function api_config(array $params, array $professor): array
{
    return [200, [
        'professor' => $professor,
        'periodo_escola' => PERIODO_ESCOLA,
        'periodo_rotulo' => nome_da_divisao(),
        'periodos' => array_map(fn($n, $nome) => ['numero' => $n, 'nome' => $nome], array_keys(periodos()), periodos()),
        'periodo_atual' => periodo_atual(),
        'disciplinas' => disciplinas(),
        'dificuldades' => DIFICULDADES,
        'limites' => ['reforco' => LIMITE_REFORCO, 'facil' => LIMITE_FACIL, 'dificil' => LIMITE_DIFICIL],
        'tamanho_maximo_arquivo' => TAMANHO_MAXIMO_ARQUIVO,
        'tipos_aceitos' => array_keys(TIPOS_ACEITOS),
    ]];
}

function api_listar_provas(array $params, array $professor): array
{
    [$provas, , $escolhidos] = listar_provas($_GET);
    return [200, [
        'filtros' => $escolhidos,
        'provas' => array_map(fn($p) => [
            'id' => (int)$p['id'],
            'nome' => $p['nome'],
            'ano' => (int)$p['ano'],
            'periodo' => $p['periodo'] === null ? null : (int)$p['periodo'],
            'periodo_nome' => nome_periodo($p['periodo'] === null ? null : (int)$p['periodo']),
            'status' => $p['status'],
            'caderno' => $p['caderno'],
            'turmas' => (int)$p['turmas'],
            'folhas' => (int)$p['folhas'],
            'criado_em' => data_iso($p['criado_em']),
        ], $provas),
    ]];
}

/**
 * multipart/form-data: ano, periodo, nome (opcional) e o recorte do gabarito feito no app
 * (área de respostas em "gabarito_imagem", QR em "gabarito_qr", caderno lido em "caderno")
 * ou as respostas em "gabarito_digitado". Devolve o rascunho e a leitura do recorte
 * (com as coordenadas das bolinhas, para o app desenhar sobre o próprio recorte).
 */
function api_cadastrar_prova(array $params, array $professor): array
{
    $nova = cadastrar_prova($_POST, recorte_do_app('gabarito_imagem', 'gabarito_qr'), $professor['id']);
    $leitura = $nova['leitura'];
    return [201, [
        'prova' => prova_json(buscar_prova($nova['id'])),
        'leitura' => $leitura === null ? null : array_diff_key($leitura,
            ['arquivo' => 1, 'pagina' => 1, 'ok' => 1, 'imagem_debug' => 1]),
    ]];
}

function api_ver_prova(array $params, array $professor): array
{
    return [200, ['prova' => prova_json(buscar_prova($params['id']))]];
}

function api_descartar_prova(array $params, array $professor): array
{
    descartar_rascunho(buscar_prova($params['id']));
    return [204, null];
}

/** Revisão do gabarito: {"nome": "...", "questoes": [{"numero": 1, "resposta": "A", ...}]}; tudo opcional. */
function revisar_gabarito_pela_api(int $id, bool $aprovar): array
{
    $prova = buscar_prova($id);
    $corpo = corpo_json();
    if (isset($corpo['questoes']) && !is_array($corpo['questoes'])) {
        throw new ErroDoUsuario('"questoes" precisa ser uma lista.', 400);
    }
    $entrada = entrada_da_revisao($prova['questoes'], $corpo['questoes'] ?? []);
    $nome = isset($corpo['nome']) && is_scalar($corpo['nome']) ? (string)$corpo['nome'] : $prova['nome'];
    [, $erros] = aprovar_gabarito($prova, $nome, $entrada, $aprovar);
    if ($erros) {
        throw new ErroDoUsuario($aprovar ? 'O gabarito ainda não pode ser aprovado.' : 'A revisão tem erros.', 422,
            ['erros' => $erros]);
    }
    return [200, ['prova' => prova_json(buscar_prova($id))]];
}

function api_salvar_questoes(array $params, array $professor): array
{
    return revisar_gabarito_pela_api($params['id'], false);
}

function api_aprovar_prova(array $params, array $professor): array
{
    if (buscar_prova($params['id'])['status'] !== 'rascunho') {
        throw new ErroDoUsuario('Esta prova já foi aprovada.', 409);
    }
    return revisar_gabarito_pela_api($params['id'], true);
}

/** Folhas da prova (opcional ?turma=), para a lista e o contador do app. */
function api_listar_folhas(array $params, array $professor): array
{
    $prova = buscar_prova($params['id']);
    [$lidas, $falhas] = carregar_folhas($prova);
    $turma = trim((string)($_GET['turma'] ?? ''));
    $daTurma = fn($f) => $turma === '' || $f['turma'] === $turma;
    return [200, [
        'turma' => $turma,
        'folhas' => array_map(fn($f) => folha_json($f, $prova['gabarito']), array_values(array_filter($lidas, $daTurma))),
        'recusadas' => array_map(fn($f) => ['id' => (int)$f['id'], 'turma' => $f['turma'], 'arquivo' => $f['arquivo'],
            'pagina' => (int)$f['pagina'], 'erro' => $f['erro']], array_values(array_filter($falhas, $daTurma))),
    ]];
}

/**
 * multipart/form-data: turma e o recorte feito no app: área de respostas em "folha", QR em
 * "qr" (opcional) e o caderno lido no app em "caderno" (opcional). A foto inteira, com o
 * nome do aluno, não sai do celular; se chegar no lugar do recorte, é recusada (nao_recorte).
 * Cada página volta com a nota e as questões a revisar, ou com o motivo da recusa
 * ("erro" e "codigo", ex.: desfocada). Folha recusada não é gravada: o app pede outra foto.
 */
function api_enviar_folhas(array $params, array $professor): array
{
    $prova = buscar_prova($params['id']);
    $turma = turma_valida((string)($_POST['turma'] ?? ''));
    $resultado = ler_folhas_aluno($prova, $turma, recorte_do_app('folha', 'qr'), false);
    $folhas = [];
    foreach ($resultado as $r) {
        if (!$r['ok']) {
            $folhas[] = ['ok' => false, 'arquivo' => $r['arquivo'], 'pagina' => $r['pagina'],
                'erro' => $r['erro'], 'codigo' => $r['codigo']];
            continue;
        }
        $folha = ['id' => $r['id'], 'prova_id' => $prova['id'], 'turma' => $turma, 'arquivo' => $r['arquivo'],
            'pagina' => $r['pagina'], 'respostas' => respostas_da_leitura($r['leitura']),
            'revisar' => questoes_para_revisar($r['leitura']), 'revisada' => false];
        $folhas[] = ['ok' => true] + folha_json($folha, $prova['gabarito']) + ['leitura' => $r['leitura']];
    }
    return [200, [
        'turma' => $turma,
        'folhas_da_turma' => contar_folhas_da_turma((int)$prova['id'], $turma),
        'folhas' => $folhas,
    ]];
}

function api_relatorio(array $params, array $professor): array
{
    $prova = buscar_prova($params['id']);
    $r = relatorio_prova($prova, trim((string)($_GET['turma'] ?? '')));
    $questoes = count($prova['questoes']);
    return [200, [
        'prova' => ['id' => (int)$prova['id'], 'nome' => $prova['nome'], 'questoes' => $questoes],
        'turma' => $r['turma'],
        'turmas' => $r['turmas'],
        'limites' => ['reforco' => LIMITE_REFORCO, 'facil' => LIMITE_FACIL, 'dificil' => LIMITE_DIFICIL],
        'folhas' => count($r['visiveis']),
        'recusadas' => count($r['falhas']),
        'resumo' => array_map(fn($t) => [
            'turma' => $t,
            'folhas' => $r['resumo'][$t]['folhas'],
            'acerto' => pct($r['resumo'][$t]['acertos'], $r['resumo'][$t]['total']),
            'por_disciplina' => porcentagens($r['resumo'][$t]['disciplinas']),
            'por_dificuldade' => porcentagens($r['resumo'][$t]['niveis']),
            'para_reforco' => $r['resumo'][$t]['reforco'],
            'a_revisar' => $r['resumo'][$t]['revisar'],
            'precisao' => precisao_json($r['precisao_por_turma'][$t]),
        ], $r['turmas']),
        'precisao' => precisao_json($r['precisao']),
        'prioridades' => array_map(fn($p) => array_intersect_key($p, array_flip(['numero', 'certa', 'disciplina',
            'dificuldade', 'habilidade', 'taxa', 'distrator', 'distrator_qtd', 'brancos', 'grupo', 'motivo'])), $r['prioridades']),
        'habilidades_baixas' => $r['habilidades'],
        'questoes' => $r['estatisticas'],
        'dificuldade' => [
            'matriz' => $r['matriz'],
            'comparadas' => $r['comparadas'],
            'no_nivel_previsto' => $r['iguais'],
            'concordancia' => pct($r['iguais'], $r['comparadas']),
            'divergentes' => array_column($r['divergentes'], 'numero'),
        ],
    ]];
}

/** Precisão da leitura para a API (sem os contadores internos da margem). */
function precisao_json(array $p): array
{
    return [
        'questoes' => $p['questoes'],
        'com_certeza' => $p['certeza'],
        'duvidosas' => $p['duvidosas'],
        'pct_com_certeza' => $p['pct_certeza'],
        'pct_duvidosas' => $p['pct_duvidosas'],
        'conferidas' => $p['conferidas'],
        'pendentes' => $p['pendentes'],
        'margem' => $p['margem'],
        'acerto' => $p['acerto'],
        'acerto_min' => $p['acerto_min'],
        'acerto_max' => $p['acerto_max'],
        'corrigidas_duvidosas' => $p['corrigidas_duvidosas'],
        'pct_corrigidas_duvidosas' => $p['pct_corrigidas_duvidosas'],
        'corrigidas_sem_duvida' => $p['corrigidas_sem_duvida'],
        'conferidas_sem_duvida' => $p['certeza_conferida'],
        'pct_corrigidas_sem_duvida' => $p['pct_corrigidas_sem_duvida'],
        'sem_niveis' => $p['sem_niveis'],
    ];
}

function api_ver_folha(array $params, array $professor): array
{
    $folha = buscar_folha($params['id']);
    $prova = buscar_prova((int)$folha['prova_id']);
    return [200, ['folha' => folha_json($folha, $prova['gabarito']) + [
        'alternativas' => $prova['layout']['alternativas'],
        'gabarito' => $prova['gabarito'],
        'niveis' => $folha['niveis'],
        'imagem_url' => $folha['imagem_debug'] ? storage_url_assinada('anotadas', $folha['imagem_debug']) : null,
    ]]];
}

/** {"respostas": [...]} completa, ou {"respostas": {"12": "B"}} só com as que mudaram. */
function api_revisar_folha(array $params, array $professor): array
{
    $folha = buscar_folha($params['id']);
    $prova = buscar_prova((int)$folha['prova_id']);
    revisar_folha($folha, $prova, respostas_da_revisao($folha['respostas'], corpo_json()['respostas'] ?? null));
    return [200, ['folha' => folha_json(buscar_folha($params['id']), $prova['gabarito'])]];
}
