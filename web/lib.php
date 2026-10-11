<?php
declare(strict_types=1);

/*
 * Núcleo do backend PHP.
 * - Guarda provas e folhas em SQLite (troque o DSN por MySQL/PostgreSQL depois).
 * - Chama o leitor óptico em Python (worker/omr_cli.py). Na versão final essa
 *   chamada vira uma mensagem no RabbitMQ; o formato do JSON continua o mesmo.
 * - Faz a correção: o Python só lê, quem compara com o gabarito é o PHP.
 */

define('RAIZ', dirname(__DIR__));
define('ARMAZENAMENTO', getenv('ARMAZENAMENTO') ?: RAIZ . '/armazenamento');
define('PYTHON', getenv('OMR_PYTHON') ?: 'python3');
define('OMR_CLI', RAIZ . '/worker/omr_cli.py');
define('LIMITE_REFORCO', (float)(getenv('LIMITE_REFORCO') ?: 60)); // % de acerto
define('PERIODO_ESCOLA', getenv('PERIODO_ESCOLA') ?: 'bimestre'); // bimestre, trimestre ou semestre

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    if (!is_dir(ARMAZENAMENTO)) {
        mkdir(ARMAZENAMENTO, 0775, true);
    }
    $pdo = new PDO('sqlite:' . ARMAZENAMENTO . '/corretor.db');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS provas (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nome TEXT NOT NULL,
            layout TEXT NOT NULL,
            gabarito TEXT NOT NULL,
            ano INTEGER,
            periodo INTEGER,
            criado_em TEXT DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS folhas (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            prova_id INTEGER NOT NULL REFERENCES provas(id) ON DELETE CASCADE,
            turma TEXT NOT NULL,
            arquivo TEXT NOT NULL,
            pagina INTEGER NOT NULL,
            respostas TEXT,
            niveis TEXT,
            revisar TEXT NOT NULL DEFAULT '[]',
            revisada INTEGER NOT NULL DEFAULT 0,
            erro TEXT,
            imagem_debug TEXT,
            criado_em TEXT DEFAULT CURRENT_TIMESTAMP
        );
    ");
    // Bancos criados antes do ano letivo: provas antigas ficam no ano em que foram cadastradas.
    $colunas = array_column($pdo->query('PRAGMA table_info(provas)')->fetchAll(), 'name');
    if (!in_array('ano', $colunas, true)) {
        $pdo->exec('ALTER TABLE provas ADD COLUMN ano INTEGER');
        $pdo->exec("UPDATE provas SET ano = CAST(strftime('%Y', criado_em) AS INTEGER)");
    }
    if (!in_array('periodo', $colunas, true)) {
        $pdo->exec('ALTER TABLE provas ADD COLUMN periodo INTEGER');
    }
    return $pdo;
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

function caminho_layout(int $provaId): string
{
    return ARMAZENAMENTO . "/provas/$provaId/layout.json";
}

function salvar_layout(int $provaId, array $layout): void
{
    $dir = dirname(caminho_layout($provaId));
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    file_put_contents(caminho_layout($provaId), json_encode($layout, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    db()->prepare('UPDATE provas SET layout = ? WHERE id = ?')
        ->execute([json_encode($layout, JSON_UNESCAPED_UNICODE), $provaId]);
}

/* ------------------------------------------------------- Leitor óptico */

/**
 * Chama o worker Python e devolve uma entrada por página lida:
 * ['arquivo', 'pagina', 'ok', 'erro'?, 'respostas'?, 'duvidosas'?, 'imagem_debug'?,
 *  'caderno'?, 'layout'?, 'confianca'?]
 */
function ler_folhas(array $arquivos, string $layoutJson, string $pastaDebug): array
{
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

function guardar_upload(array $arq, string $pasta): string
{
    if (!is_dir($pasta)) {
        mkdir($pasta, 0775, true);
    }
    $base = preg_replace('/[^A-Za-z0-9_.-]+/', '_', pathinfo($arq['nome'], PATHINFO_FILENAME));
    $destino = $pasta . '/' . bin2hex(random_bytes(4)) . '_' . $base . '.' . $arq['ext'];
    if (!move_uploaded_file($arq['tmp'], $destino)) {
        throw new RuntimeException("Não foi possível salvar \"{$arq['nome']}\".");
    }
    return $destino;
}

function relativo(string $caminho): string
{
    return ltrim(substr($caminho, strlen(ARMAZENAMENTO)), '/');
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
    $prova['layout'] = json_decode($prova['layout'], true);
    $prova['gabarito'] = json_decode($prova['gabarito'], true);
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
