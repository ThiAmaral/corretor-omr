<?php
declare(strict_types=1);

/*
 * Núcleo do backend PHP.
 * - Guarda provas, questões e folhas no PostgreSQL do Supabase (DATABASE_URL).
 *   O esquema vem das migrações em supabase/migrations.
 * - Guarda as imagens no Supabase Storage (buckets privados "folhas" e "anotadas").
 * - Chama o leitor óptico em Python (worker/omr_cli.py). Na versão final essa
 *   chamada vira uma mensagem no RabbitMQ; o formato do JSON continua o mesmo.
 * - Faz a correção: o Python só lê, quem compara com o gabarito é o PHP.
 */

define('RAIZ', dirname(__DIR__));
carregar_env(RAIZ . '/.env');
define('PYTHON', getenv('OMR_PYTHON') ?: 'python3');
define('OMR_CLI', RAIZ . '/worker/omr_cli.py');
define('LIMITE_REFORCO', (float)(getenv('LIMITE_REFORCO') ?: 60)); // % de acerto
define('PERIODO_ESCOLA', getenv('PERIODO_ESCOLA') ?: 'bimestre'); // bimestre, trimestre ou semestre

/** Lê o .env da raiz quando o sistema roda sem Docker. Variáveis já definidas no ambiente têm prioridade. */
function carregar_env(string $arquivo): void
{
    if (!is_file($arquivo)) {
        return;
    }
    foreach (file($arquivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linha) {
        if (str_starts_with(ltrim($linha), '#') || !str_contains($linha, '=')) {
            continue;
        }
        [$nome, $valor] = array_map('trim', explode('=', $linha, 2));
        if (getenv($nome) === false) {
            putenv("$nome=" . trim($valor, "\"'"));
        }
    }
}

function config(string $nome): string
{
    $valor = getenv($nome);
    if ($valor === false || $valor === '') {
        throw new RuntimeException("Configure $nome no arquivo .env (veja o .env.exemplo).");
    }
    return $valor;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    // postgresql://usuario:senha@host:porta/banco?sslmode=require
    $url = parse_url(config('DATABASE_URL'));
    if (!$url || !isset($url['host'], $url['user'])) {
        throw new RuntimeException('DATABASE_URL inválida. Copie a string do "Session pooler" no painel do Supabase.');
    }
    parse_str($url['query'] ?? '', $opcoes);
    $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
        $url['host'], $url['port'] ?? 5432, ltrim($url['path'] ?? '/postgres', '/') ?: 'postgres',
        $opcoes['sslmode'] ?? 'require');
    $pdo = new PDO($dsn, urldecode($url['user']), urldecode($url['pass'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        // Os poolers do Supabase podem entregar outra conexão a cada requisição, e o
        // "prepared statement" do servidor se perde. Preparar no PHP funciona com todos.
        PDO::ATTR_EMULATE_PREPARES => true,
    ]);
    return $pdo;
}

/* ------------------------------------------------------ Supabase Storage */

/** Chamada à API do Storage com a chave secret (só no servidor). Devolve o JSON da resposta. */
function storage(string $metodo, string $rota, string $corpo = '', string $tipo = 'application/json'): array
{
    $chave = config('SUPABASE_SECRET_KEY');
    $cabecalhos = ["apikey: $chave", "Content-Type: $tipo", 'x-upsert: true'];
    if (str_starts_with($chave, 'eyJ')) { // chave antiga (service_role, JWT)
        $cabecalhos[] = "Authorization: Bearer $chave";
    }
    $ch = curl_init(rtrim(config('SUPABASE_URL'), '/') . '/storage/v1/' . $rota);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $metodo,
        CURLOPT_POSTFIELDS => $corpo,
        CURLOPT_HTTPHEADER => $cabecalhos,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
    ]);
    if (PHP_OS_FAMILY === 'Windows' && !ini_get('curl.cainfo')) {
        // O PHP do winget não traz lista de certificados: usa a do próprio Windows.
        curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
    }
    $resposta = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $falha = curl_error($ch);
    curl_close($ch);
    if ($resposta === false) {
        throw new RuntimeException("Não foi possível acessar o Supabase Storage: $falha");
    }
    $dados = json_decode($resposta, true);
    if ($status >= 300) {
        $msg = is_array($dados) ? ($dados['message'] ?? $dados['error'] ?? $resposta) : $resposta;
        throw new RuntimeException("O Supabase Storage recusou a operação ($status): $msg");
    }
    return is_array($dados) ? $dados : [];
}

function caminho_storage(string $caminho): string
{
    return implode('/', array_map('rawurlencode', explode('/', $caminho)));
}

/** Envia uma imagem JPEG local para o bucket e devolve o caminho guardado no banco. */
function storage_enviar(string $bucket, string $caminho, string $arquivoLocal): string
{
    storage('POST', "object/$bucket/" . caminho_storage($caminho), file_get_contents($arquivoLocal), 'image/jpeg');
    return $caminho;
}

/** URL temporária (padrão: 5 minutos) para o navegador abrir uma imagem de bucket privado. */
function storage_url_assinada(string $bucket, string $caminho, int $segundos = 300): string
{
    $dados = storage('POST', "object/sign/$bucket/" . caminho_storage($caminho), json_encode(['expiresIn' => $segundos]));
    return rtrim(config('SUPABASE_URL'), '/') . '/storage/v1' . $dados['signedURL'];
}

function storage_apagar(string $bucket, array $caminhos): void
{
    if ($caminhos) {
        storage('DELETE', "object/$bucket", json_encode(['prefixes' => array_values($caminhos)]));
    }
}

/* ------------------------------------------------- Arquivos temporários */

/** Pasta temporária da requisição; o Python lê dela e ela é apagada no fim. Nada fica no disco. */
function pasta_temporaria(): string
{
    static $pasta = null;
    if ($pasta === null) {
        $pasta = sys_get_temp_dir() . '/corretor_' . bin2hex(random_bytes(6));
        mkdir($pasta, 0700, true);
        register_shutdown_function(fn() => apagar_pasta($pasta));
    }
    return $pasta;
}

function apagar_pasta(string $pasta): void
{
    if (!is_dir($pasta)) {
        return;
    }
    foreach (scandir($pasta) as $item) {
        if ($item !== '.' && $item !== '..') {
            $c = "$pasta/$item";
            is_dir($c) ? apagar_pasta($c) : unlink($c);
        }
    }
    rmdir($pasta);
}

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function pct(float $acertos, float $total): ?float
{
    return $total > 0 ? round(100 * $acertos / $total, 1) : null;
}

function fmt_pct(?float $v): string
{
    return $v === null ? '–' : number_format($v, 1, ',', '') . '%';
}

/* ------------------------------------------------ Ano letivo e filtros */

/** [1 => '1º bimestre', ...] conforme a divisão do ano letivo da escola. */
function periodos(): array
{
    $quantos = match (PERIODO_ESCOLA) {
        'bimestre' => 4,
        'trimestre' => 3,
        'semestre' => 2,
        default => throw new RuntimeException('PERIODO_ESCOLA deve ser bimestre, trimestre ou semestre.'),
    };
    $lista = [];
    for ($n = 1; $n <= $quantos; $n++) {
        $lista[$n] = "{$n}º " . PERIODO_ESCOLA;
    }
    return $lista;
}

/** Rótulo do período; números que não existem no formato atual (a escola mudou de formato) aparecem como "período". */
function nome_periodo(?int $n): string
{
    return $n === null ? '–' : (periodos()[$n] ?? "{$n}º período");
}

/** Sugestão para o cadastro: divide o ano civil em partes iguais. */
function periodo_atual(): int
{
    return (int)ceil((int)date('n') * count(periodos()) / 12);
}

/** Resultado de uma consulta de uma coluna no formato [valor => rótulo] usado pelos filtros. */
function opcoes_sql(string $sql): array
{
    $valores = db()->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    return array_combine($valores, $valores);
}

/**
 * Filtros da lista de provas. Cada entrada vira um <select> no index e uma condição no WHERE
 * (a tabela de provas é "p"). Para criar um filtro novo basta acrescentar uma entrada, por exemplo:
 *   'turma' => ['rotulo' => 'Turma',
 *               'condicao' => 'EXISTS (SELECT 1 FROM folhas t WHERE t.prova_id = p.id AND t.turma = ?)',
 *               'opcoes' => opcoes_sql('SELECT DISTINCT turma FROM folhas ORDER BY turma')],
 */
function filtros_de_provas(): array
{
    return [
        'ano' => [
            'rotulo' => 'Ano letivo',
            'condicao' => 'p.ano = ?',
            'opcoes' => opcoes_sql('SELECT DISTINCT ano FROM provas WHERE ano IS NOT NULL ORDER BY ano DESC'),
        ],
        'periodo' => [
            'rotulo' => ucfirst(PERIODO_ESCOLA),
            'condicao' => 'p.periodo = ?',
            'opcoes' => periodos(),
        ],
    ];
}

/** Lê os filtros escolhidos (ex.: $_GET) e devolve [where, parâmetros, escolhidos]. Valores fora das opções são ignorados. */
function aplicar_filtros(array $filtros, array $entrada): array
{
    $condicoes = ['1 = 1'];
    $params = [];
    $escolhidos = [];
    foreach ($filtros as $chave => $f) {
        $valor = trim((string)($entrada[$chave] ?? ''));
        if ($valor === '' || !array_key_exists($valor, $f['opcoes'])) {
            continue;
        }
        $condicoes[] = $f['condicao'];
        $params[] = $valor;
        $escolhidos[$chave] = $valor;
    }
    return [implode(' AND ', $condicoes), $params, $escolhidos];
}

/* ---------------------------------------------------------------- Layout */

/** "Linguagens: 1-10" por linha -> [['nome'=>..., 'de'=>1, 'ate'=>10], ...] */
function ler_areas(string $texto, int $questoes): array
{
    $areas = [];
    foreach (preg_split('/\R/u', trim($texto)) as $n => $linha) {
        if (trim($linha) === '') {
            continue;
        }
        if (!preg_match('/^\s*(.+?)\s*:\s*(\d+)\s*(?:-|–|a|até)\s*(\d+)\s*$/u', $linha, $m)) {
            throw new InvalidArgumentException('Linha ' . ($n + 1) . ' das áreas não está no formato "Nome: 1-10".');
        }
        [$de, $ate] = [(int)$m[2], (int)$m[3]];
        if ($de < 1 || $ate > $questoes || $de > $ate) {
            throw new InvalidArgumentException("A área \"{$m[1]}\" precisa estar entre as questões 1 e $questoes.");
        }
        foreach ($areas as $outra) {
            if ($de <= $outra['ate'] && $ate >= $outra['de']) {
                throw new InvalidArgumentException("As áreas \"{$outra['nome']}\" e \"{$m[1]}\" usam as mesmas questões. Cada questão fica em uma área só.");
            }
        }
        $areas[] = ['nome' => $m[1], 'de' => $de, 'ate' => $ate];
    }
    return $areas;
}

function areas_texto(array $layout): string
{
    return implode("\n", array_map(fn($a) => "{$a['nome']}: {$a['de']}-{$a['ate']}", $layout['areas'] ?? []));
}

/** Sem áreas cadastradas, a prova inteira vira "Geral". */
function areas_efetivas(array $layout): array
{
    return $layout['areas'] ?: [['nome' => 'Geral', 'de' => 1, 'ate' => $layout['questoes']]];
}

/** Cada questão guarda a sua disciplina; as faixas "Nome: de-até" são remontadas a partir delas. */
function areas_das_questoes(array $questoes): array
{
    $areas = [];
    foreach ($questoes as $q) {
        $ultima = array_key_last($areas);
        if ($q['disciplina'] === null) {
            continue;
        }
        if ($ultima !== null && $areas[$ultima]['nome'] === $q['disciplina'] && $areas[$ultima]['ate'] === $q['numero'] - 1) {
            $areas[$ultima]['ate'] = $q['numero'];
        } else {
            $areas[] = ['nome' => $q['disciplina'], 'de' => $q['numero'], 'ate' => $q['numero']];
        }
    }
    return $areas;
}

function salvar_areas(int $provaId, array $areas): void
{
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE questoes SET disciplina = NULL WHERE prova_id = ?')->execute([$provaId]);
    $st = $pdo->prepare('UPDATE questoes SET disciplina = ? WHERE prova_id = ? AND numero BETWEEN ? AND ?');
    foreach ($areas as $a) {
        $st->execute([$a['nome'], $provaId, $a['de'], $a['ate']]);
    }
    $pdo->commit();
}

/** O banco guarda no layout só o que o leitor precisa; o nome e as áreas ficam em provas e questoes. */
function layout_do_leitor(array $layout): array
{
    return array_diff_key($layout, ['nome' => 1, 'areas' => 1]);
}

/** Grava a prova e o gabarito (uma linha por questão) de uma vez só. Devolve o id. */
function criar_prova(string $nome, int $ano, int $periodo, array $layout, array $gabarito): int
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("INSERT INTO provas (nome, ano, periodo, caderno, layout, status)
                             VALUES (?, ?, ?, ?, ?, 'ativa') RETURNING id");
        $st->execute([$nome, $ano, $periodo, $layout['caderno'] ?? null,
            json_encode(layout_do_leitor($layout), JSON_UNESCAPED_UNICODE)]);
        $id = (int)$st->fetchColumn();
        $ins = $pdo->prepare('INSERT INTO questoes (prova_id, numero, resposta, disciplina) VALUES (?, ?, ?, ?)');
        foreach ($gabarito as $i => $resposta) {
            $n = $i + 1;
            $area = array_values(array_filter($layout['areas'] ?? [], fn($a) => $n >= $a['de'] && $n <= $a['ate']));
            $ins->execute([$id, $n, $resposta, $area[0]['nome'] ?? null]);
        }
        $pdo->commit();
        return $id;
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
}

/* ------------------------------------------------------- Leitor óptico */

/**
 * Chama o worker Python e devolve uma entrada por página lida:
 * ['arquivo', 'pagina', 'ok', 'erro'?, 'respostas'?, 'duvidosas'?, 'imagem_debug'?, 'imagem_recorte'?,
 *  'caderno'?, 'layout'?, 'confianca'?]
 */
function ler_folhas(array $arquivos, array $layout): array
{
    $layoutJson = tempnam(pasta_temporaria(), 'layout');
    file_put_contents($layoutJson, json_encode(layout_do_leitor($layout), JSON_UNESCAPED_UNICODE));
    $pastaDebug = pasta_temporaria() . '/lidas';
    $cmd = array_merge([PYTHON, OMR_CLI, 'ler'], $arquivos, ['--layout', $layoutJson, '--debug', $pastaDebug]);
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        throw new RuntimeException('Não foi possível iniciar o leitor óptico (Python).');
    }
    $saida = stream_get_contents($pipes[1]);
    $erros = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($proc) !== 0) {
        throw new RuntimeException('O leitor óptico falhou: ' . trim($erros));
    }
    $dados = json_decode($saida, true);
    if (!is_array($dados)) {
        throw new RuntimeException('Resposta inválida do leitor óptico.');
    }
    return $dados;
}

/** Converte a leitura do Python em respostas: letra, null (branco) ou "MULTIPLA". */
function respostas_da_leitura(array $leitura): array
{
    return array_map(fn($r) => match ($r['situacao']) {
        'marcada' => $r['resposta'],
        'multipla' => 'MULTIPLA',
        default => null,
    }, $leitura['respostas']);
}

function questoes_para_revisar(array $leitura): array
{
    $lista = [];
    foreach ($leitura['respostas'] as $r) {
        if ($r['duvidosa'] || $r['situacao'] === 'multipla') {
            $lista[] = $r['questao'];
        }
    }
    return $lista;
}

/* ------------------------------------------------------------ Correção */

/** Acerto = marcação única igual ao gabarito. Branco e dupla contam como erro. */
function corrigir(array $gabarito, array $respostas): array
{
    $acertos = [];
    foreach ($gabarito as $i => $certa) {
        $acertos[] = ($respostas[$i] ?? null) === $certa;
    }
    return $acertos;
}

function upload_normalizado(array $campo): array
{
    $arquivos = [];
    if (!isset($campo['name'])) {
        return $arquivos;
    }
    $nomes = (array)$campo['name'];
    foreach ($nomes as $i => $nome) {
        $erro = ((array)$campo['error'])[$i];
        if ($erro === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($erro !== UPLOAD_ERR_OK) {
            throw new RuntimeException("Falha no envio de \"$nome\" (código $erro). Verifique o tamanho máximo de upload.");
        }
        $ext = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
        if (!in_array($ext, ['png', 'jpg', 'jpeg', 'pdf', 'tif', 'tiff'], true)) {
            throw new RuntimeException("\"$nome\" não é PNG, JPG, TIFF ou PDF.");
        }
        $arquivos[] = ['nome' => $nome, 'tmp' => ((array)$campo['tmp_name'])[$i], 'ext' => $ext];
    }
    return $arquivos;
}

/** Move o upload para a pasta temporária da requisição (o nome original fica legível para o Python). */
function guardar_upload(array $arq): string
{
    $pasta = pasta_temporaria();
    $base = preg_replace('/[^A-Za-z0-9_.-]+/', '_', pathinfo($arq['nome'], PATHINFO_FILENAME));
    $destino = $pasta . '/' . bin2hex(random_bytes(4)) . '_' . $base . '.' . $arq['ext'];
    if (!move_uploaded_file($arq['tmp'], $destino)) {
        throw new RuntimeException("Não foi possível salvar \"{$arq['nome']}\".");
    }
    return $destino;
}

function buscar_prova(int $id): array
{
    $st = db()->prepare('SELECT * FROM provas WHERE id = ?');
    $st->execute([$id]);
    $prova = $st->fetch();
    if (!$prova) {
        http_response_code(404);
        exit('Prova não encontrada.');
    }
    $st = db()->prepare('SELECT numero, resposta, disciplina, dificuldade FROM questoes WHERE prova_id = ? ORDER BY numero');
    $st->execute([$id]);
    $questoes = $st->fetchAll();
    $prova['layout'] = json_decode($prova['layout'], true) + ['nome' => $prova['nome']];
    $prova['layout']['areas'] = areas_das_questoes($questoes);
    $prova['gabarito'] = array_column($questoes, 'resposta');
    return $prova;
}

function redirecionar(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function cabecalho(string $titulo, string $voltar = ''): void
{
    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . e($titulo) . ' | Corretor de provas</title>'
        . '<link rel="preconnect" href="https://fonts.googleapis.com">'
        . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Atkinson+Hyperlegible:wght@400;700&display=swap">'
        . '<link rel="stylesheet" href="estilo.css"></head><body><main>';
    echo '<header class="topo"><a class="marca" href="index.php">Corretor de provas</a>';
    if ($voltar) {
        echo '<a class="voltar" href="' . e($voltar) . '">Voltar</a>';
    }
    echo '</header><h1>' . e($titulo) . '</h1>';
}

function rodape(): void
{
    echo '</main></body></html>';
}
