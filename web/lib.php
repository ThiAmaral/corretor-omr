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
 * As operações do sistema (cadastrar prova, aprovar gabarito, ler folhas, relatório)
 * ficam em src/servicos.php e são as mesmas para as páginas e para a API (api/).
 * O login dos professores é o Supabase Auth (src/auth.php).
 */

date_default_timezone_set('America/Sao_Paulo'); // datas no horário de Brasília
define('RAIZ', dirname(__DIR__));
carregar_env(RAIZ . '/.env');
// No Windows, "python3" costuma ser só o atalho da Microsoft Store; o instalador cria "python".
define('PYTHON', getenv('OMR_PYTHON') ?: (PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3'));
define('OMR_CLI', RAIZ . '/worker/omr_cli.py');
define('LIMITE_REFORCO', (float)(getenv('LIMITE_REFORCO') ?: 60)); // % de acerto
// Dificuldade observada pela proporção de acerto: acima de LIMITE_FACIL é fácil, abaixo de LIMITE_DIFICIL é difícil.
define('LIMITE_FACIL', (float)(getenv('LIMITE_FACIL') ?: 70));
define('LIMITE_DIFICIL', (float)(getenv('LIMITE_DIFICIL') ?: 30));
define('PERIODO_ESCOLA', getenv('PERIODO_ESCOLA') ?: 'bimestre'); // bimestre, trimestre ou semestre
define('TAMANHO_MAXIMO_ARQUIVO', 20 * 1024 * 1024); // por foto ou PDF enviado
// Tipo real do arquivo (lido do conteúdo, não da extensão) => extensão usada na pasta temporária.
const TIPOS_ACEITOS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/tiff' => 'tif', 'application/pdf' => 'pdf'];

require __DIR__ . '/src/servicos.php';
require __DIR__ . '/src/auth.php';

// Todo texto recebido vira UTF-8 válido antes de chegar ao banco (ver utf8_valido()).
$_GET = utf8_valido($_GET);
$_POST = utf8_valido($_POST);

/**
 * Erro que o professor (ou o app) consegue resolver: dado faltando, prova inexistente,
 * foto ilegível. Na página vira uma mensagem; na API, JSON com o código HTTP $status.
 * $detalhes vai junto no JSON da API (ex.: a lista de erros da revisão, o código do leitor).
 */
class ErroDoUsuario extends InvalidArgumentException
{
    public function __construct(string $mensagem, public readonly int $status = 422, public readonly array $detalhes = [])
    {
        parent::__construct($mensagem);
    }
}

/**
 * O navegador sempre envia UTF-8, mas outros clientes (curl no Windows, o app) podem
 * enviar Windows-1252. Byte inválido quebra a consulta no PostgreSQL, então converte.
 */
function utf8_valido(mixed $valor): mixed
{
    if (is_array($valor)) {
        return array_map('utf8_valido', $valor);
    }
    if (is_string($valor) && !mb_check_encoding($valor, 'UTF-8')) {
        return mb_convert_encoding($valor, 'UTF-8', 'Windows-1252');
    }
    return $valor;
}

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

/**
 * Requisição ao Supabase (Storage ou Auth) com a chave secret, que só existe no servidor.
 * Devolve [código HTTP, JSON da resposta (ou o texto, se não for JSON)].
 */
function supabase_http(string $metodo, string $rota, string $corpo = '', array $cabecalhos = [],
                       string $tipo = 'application/json'): array
{
    $chave = config('SUPABASE_SECRET_KEY');
    $cabecalhos = array_merge(["apikey: $chave", "Content-Type: $tipo"], $cabecalhos);
    if (str_starts_with($chave, 'eyJ') && !preg_grep('/^Authorization:/i', $cabecalhos)) {
        $cabecalhos[] = "Authorization: Bearer $chave"; // chave antiga (service_role, JWT)
    }
    $ch = curl_init(rtrim(config('SUPABASE_URL'), '/') . '/' . $rota);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $metodo,
        CURLOPT_HTTPHEADER => $cabecalhos,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
    ]);
    if ($metodo !== 'GET') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $corpo);
    }
    if (PHP_OS_FAMILY === 'Windows' && !ini_get('curl.cainfo')) {
        // O PHP do winget não traz lista de certificados: usa a do próprio Windows.
        curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
    }
    $resposta = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $falha = curl_error($ch);
    curl_close($ch);
    if ($resposta === false) {
        throw new RuntimeException("Não foi possível acessar o Supabase: $falha");
    }
    $dados = json_decode($resposta, true);
    return [$status, is_array($dados) ? $dados : $resposta];
}

/** Chamada à API do Storage. Devolve o JSON da resposta. */
function storage(string $metodo, string $rota, string $corpo = '', string $tipo = 'application/json'): array
{
    [$status, $dados] = supabase_http($metodo, 'storage/v1/' . $rota, $corpo, ['x-upsert: true'], $tipo);
    if ($status >= 300) {
        $msg = is_array($dados) ? ($dados['message'] ?? $dados['error'] ?? json_encode($dados)) : $dados;
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

/* ------------------------------------------- Disciplinas e dificuldade */

/** Catálogo fixo: a disciplina de cada questão é escolhida desta lista, nunca digitada. */
function disciplinas(): array
{
    return ['Língua Portuguesa', 'Matemática', 'Ciências', 'História', 'Geografia',
        'Língua Inglesa', 'Arte', 'Educação Física'];
}

/** Opções da lista de disciplinas: o catálogo e, se houver, nomes antigos já gravados na prova. */
function disciplinas_da_prova(array $questoes): array
{
    $antigas = array_filter(array_column($questoes, 'disciplina'), fn($d) => $d !== null && $d !== '');
    return array_values(array_unique(array_merge(disciplinas(), $antigas)));
}

const DIFICULDADES = ['facil' => 'Fácil', 'medio' => 'Médio', 'dificil' => 'Difícil'];

/**
 * Modelos de prova, reconhecidos pelo código do caderno lido no QR (ex.: 2265M0801).
 * Num caderno de uma disciplina, todas as questões já recebem essa disciplina.
 * Caderno desconhecido ou com todas as disciplinas: o professor informa na revisão.
 * Para um modelo novo basta acrescentar uma entrada. O padrão captura o ano escolar.
 */
function modelos_de_prova(): array
{
    return [
        'ama_matematica' => [
            'rotulo' => 'AMA – Matemática',
            'caderno' => '/^\d*M(\d{2})\d{2}$/',
            'disciplina' => 'Matemática',
        ],
        // A letra L para Língua Portuguesa ainda não foi confirmada (pergunta em aberto do plano).
        // Como é só uma sugestão que o professor confere na revisão, já fica cadastrada.
        'ama_portugues' => [
            'rotulo' => 'AMA – Língua Portuguesa',
            'caderno' => '/^\d*L(\d{2})\d{2}$/',
            'disciplina' => 'Língua Portuguesa',
        ],
    ];
}

/** ['chave', 'rotulo', 'disciplina', 'ano_escolar'] do modelo do caderno, ou null se for desconhecido. */
function modelo_do_caderno(?string $caderno): ?array
{
    if ($caderno === null || $caderno === '') {
        return null;
    }
    foreach (modelos_de_prova() as $chave => $m) {
        if (preg_match($m['caderno'], strtoupper($caderno), $partes)) {
            return ['chave' => $chave, 'rotulo' => $m['rotulo'], 'disciplina' => $m['disciplina'],
                'ano_escolar' => (int)$partes[1]];
        }
    }
    return null;
}

/** "AMA – Matemática 8º ano (M0801)"; sem modelo, um nome com a data que o professor pode trocar. */
function sugerir_nome_prova(?string $caderno): string
{
    $modelo = modelo_do_caderno($caderno);
    if ($modelo) {
        $codigo = preg_replace('/^\d+(?=[A-Z])/', '', strtoupper($caderno));
        return "{$modelo['rotulo']} {$modelo['ano_escolar']}º ano ($codigo)";
    }
    return $caderno ? "Prova do caderno $caderno" : 'Prova de ' . date('d/m/Y');
}

/** "Caderno 2265M0801 · 26 questões em 4 blocos (7, 7, 7, 5) · alternativas A–D" */
function descrever_formato(array $layout, int $questoes): string
{
    $partes = [];
    if (!empty($layout['caderno'])) {
        $partes[] = 'Caderno ' . $layout['caderno'];
    }
    $blocos = $layout['blocos'] ?? null;
    if (!$blocos && !empty($layout['colunas'])) {
        $blocos = array_fill(0, (int)$layout['colunas'], intdiv($questoes, (int)$layout['colunas']));
    }
    $partes[] = "$questoes questões" . ($blocos && count($blocos) > 1
            ? ' em ' . count($blocos) . ' blocos (' . implode(', ', $blocos) . ')' : '');
    $alts = $layout['alternativas'] ?? [];
    if ($alts) {
        $partes[] = 'alternativas ' . reset($alts) . '–' . end($alts);
    }
    return implode(' · ', $partes);
}

/* ---------------------------------------------------------------- Layout */

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

/** O banco guarda no layout só o que o leitor precisa; o nome e as áreas ficam em provas e questoes. */
function layout_do_leitor(array $layout): array
{
    return array_diff_key($layout, ['nome' => 1, 'areas' => 1]);
}

/** O formato só é conhecido depois de uma foto (do gabarito ou da 1ª folha de aluno). */
function formato_conhecido(array $layout): bool
{
    return !empty($layout['blocos']) || !empty($layout['colunas']);
}

/**
 * Questões do rascunho a partir da leitura da foto do gabarito. Branco e marcação
 * dupla ficam sem resposta e em destaque; marcação fraca fica com a letra e em destaque.
 */
function questoes_da_leitura(array $leitura, ?string $disciplina): array
{
    $questoes = [];
    foreach ($leitura['respostas'] as $r) {
        $questoes[] = [
            'resposta' => $r['situacao'] === 'marcada' ? $r['resposta'] : null,
            'duvidosa' => $r['duvidosa'] || $r['situacao'] !== 'marcada',
            'disciplina' => $disciplina,
        ];
    }
    return $questoes;
}

/** Questões do rascunho a partir do gabarito digitado (ex.: "CBDA..."). */
function questoes_digitadas(string $texto): array
{
    $letras = str_split(preg_replace('/[^A-Z]/', '', strtoupper($texto)));
    if ($letras === [''] || !$letras) {
        throw new ErroDoUsuario('Digite as respostas do gabarito em ordem, por exemplo CBDAABCD.');
    }
    if (max($letras) > 'E') {
        throw new ErroDoUsuario('O gabarito digitado só pode usar as letras A a E.');
    }
    return array_map(fn($l) => ['resposta' => $l, 'duvidosa' => false, 'disciplina' => null], $letras);
}

/** Alternativas de um gabarito digitado, até a folha ser lida: A–D, ou até a maior letra usada. */
function alternativas_digitadas(array $questoes): array
{
    return range('A', max('D', ...array_column($questoes, 'resposta')));
}

/** Grava a prova como rascunho, com uma linha por questão. Devolve o id. */
function criar_rascunho(string $nome, int $ano, int $periodo, array $layout, array $questoes, ?string $imagemGabarito,
                        ?string $professorId = null): int
{
    $modelo = modelo_do_caderno($layout['caderno'] ?? null);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("INSERT INTO provas (nome, ano, periodo, caderno, modelo, layout, imagem_gabarito, status, criado_por)
                             VALUES (?, ?, ?, ?, ?, ?, ?, 'rascunho', ?) RETURNING id");
        $st->execute([$nome, $ano, $periodo, $layout['caderno'] ?? null, $modelo['chave'] ?? null,
            json_encode(layout_do_leitor($layout), JSON_UNESCAPED_UNICODE), $imagemGabarito, $professorId]);
        $id = (int)$st->fetchColumn();
        $ins = $pdo->prepare('INSERT INTO questoes (prova_id, numero, resposta, disciplina, duvidosa) VALUES (?, ?, ?, ?, ?)');
        foreach (array_values($questoes) as $i => $q) {
            $ins->execute([$id, $i + 1, $q['resposta'], $q['disciplina'], $q['duvidosa'] ? 'true' : 'false']);
        }
        $pdo->commit();
        return $id;
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
}

/**
 * Confere a revisão enviada pelo formulário e devolve [questões limpas, erros].
 * Ao aprovar (ou ao salvar uma prova já ativa), toda questão precisa de resposta e disciplina.
 */
function validar_revisao(array $entrada, array $prova, bool $completa): array
{
    $alts = $prova['layout']['alternativas'];
    $catalogo = disciplinas_da_prova($prova['questoes']);
    $questoes = [];
    $semResposta = $semDisciplina = $respostaInvalida = $disciplinaInvalida = [];
    $erros = [];
    foreach ($prova['questoes'] as $i => $atual) {
        $n = $atual['numero'];
        $q = (array)($entrada[$i] ?? []);
        $resposta = strtoupper(trim((string)($q['resposta'] ?? '')));
        $disciplina = trim((string)($q['disciplina'] ?? ''));
        $dificuldade = (string)($q['dificuldade'] ?? '');
        $habilidade = mb_substr(strtoupper(trim((string)($q['habilidade'] ?? ''))), 0, 20);
        if ($resposta !== '' && !in_array($resposta, $alts, true)) {
            $respostaInvalida[] = $n;
            $resposta = '';
        }
        if ($disciplina !== '' && !in_array($disciplina, $catalogo, true)) {
            $disciplinaInvalida[] = $n;
            $disciplina = '';
        }
        if ($dificuldade !== '' && !isset(DIFICULDADES[$dificuldade])) {
            $dificuldade = '';
        }
        if ($resposta === '') {
            $semResposta[] = $n;
        }
        if ($disciplina === '') {
            $semDisciplina[] = $n;
        }
        $questoes[] = [
            'numero' => $n,
            'resposta' => $resposta ?: null,
            'disciplina' => $disciplina ?: null,
            'dificuldade' => $dificuldade ?: null,
            'habilidade' => $habilidade ?: null,
            // A dúvida da leitura some quando o professor confere e salva com uma resposta.
            'duvidosa' => $resposta === '',
        ];
    }
    $das = fn(array $nums) => (count($nums) === 1 ? 'da questão ' : 'das questões ') . listar_numeros($nums);
    if ($respostaInvalida) {
        $erros[] = 'A resposta ' . $das($respostaInvalida) . ' precisa ser uma das alternativas (' . implode(', ', $alts) . ').';
    }
    if ($disciplinaInvalida) {
        $erros[] = 'A disciplina ' . $das($disciplinaInvalida) . ' não está na lista.';
    }
    if ($completa && $semResposta) {
        $erros[] = 'Falta a resposta ' . $das($semResposta) . '.';
    }
    if ($completa && $semDisciplina) {
        $erros[] = 'Falta a disciplina ' . $das($semDisciplina) . '.';
    }
    return [$questoes, $erros];
}

/** [1, 2, 3, 5, 7, 8] -> "1 a 3, 5, 7 e 8" */
function listar_numeros(array $numeros): string
{
    sort($numeros);
    $faixas = [];
    foreach ($numeros as $n) {
        $ultima = array_key_last($faixas);
        if ($ultima !== null && $faixas[$ultima][1] === $n - 1) {
            $faixas[$ultima][1] = $n;
        } else {
            $faixas[] = [$n, $n];
        }
    }
    $textos = array_map(fn($f) => $f[0] === $f[1] ? (string)$f[0]
        : ($f[1] === $f[0] + 1 ? "{$f[0]}, {$f[1]}" : "{$f[0]} a {$f[1]}"), $faixas);
    $ultimo = array_pop($textos);
    return $textos ? implode(', ', $textos) . " e $ultimo" : $ultimo;
}

/** Grava a revisão do gabarito; com $aprovar, a prova passa a aceitar folhas. */
function salvar_revisao(int $provaId, string $nome, array $questoes, bool $aprovar): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE provas SET nome = ?' . ($aprovar ? ", status = 'ativa'" : '') . ' WHERE id = ?')
            ->execute([$nome, $provaId]);
        $st = $pdo->prepare('UPDATE questoes SET resposta = ?, disciplina = ?, dificuldade = ?, habilidade = ?, duvidosa = ?
                             WHERE prova_id = ? AND numero = ?');
        foreach ($questoes as $q) {
            $st->execute([$q['resposta'], $q['disciplina'], $q['dificuldade'], $q['habilidade'],
                $q['duvidosa'] ? 'true' : 'false', $provaId, $q['numero']]);
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
}

/**
 * Gabarito digitado: a prova ainda não tem formato. A primeira folha lida define
 * o formato e o caderno; as outras precisam ter o mesmo. Devolve o erro da folha,
 * ou null se ela serve (e, se for a primeira, completa o $layout).
 */
function conferir_formato_da_folha(array &$layout, array $leitura, array $gabarito): ?string
{
    $detectado = $leitura['layout'];
    $n = count($gabarito);
    if ((int)$detectado['questoes'] !== $n) {
        return "Esta folha tem {$detectado['questoes']} questões, e o gabarito da prova tem $n.";
    }
    $foraDaFolha = array_diff(array_filter($gabarito), $detectado['alternativas']);
    if ($foraDaFolha) {
        return 'O gabarito usa a alternativa ' . implode(', ', array_unique($foraDaFolha))
            . ', que não existe nesta folha (' . implode(', ', $detectado['alternativas']) . ').';
    }
    if (formato_conhecido($layout)) {
        $caderno = $layout['caderno'] ?? null;
        if ($caderno && $leitura['caderno'] && $leitura['caderno'] !== $caderno) {
            return "Esta folha é do caderno {$leitura['caderno']}, e a prova é do caderno $caderno.";
        }
        if ($detectado['blocos'] !== $layout['blocos'] || $detectado['alternativas'] !== $layout['alternativas']) {
            return 'O formato desta folha (blocos ' . implode(', ', $detectado['blocos'])
                . ') é diferente do das outras folhas da prova (' . implode(', ', $layout['blocos']) . ').';
        }
        return null;
    }
    $layout = ['caderno' => $leitura['caderno'] ?? null] + array_intersect_key($detectado, ['alternativas' => 1, 'blocos' => 1, 'questoes' => 1]) + $layout;
    return null;
}

/* ------------------------------------------------------- Leitor óptico */

/**
 * Chama o worker Python e devolve uma entrada por página lida:
 * ['arquivo', 'pagina', 'ok', 'erro'?, 'respostas'?, 'duvidosas'?, 'imagem_debug'?, 'imagem_recorte'?,
 *  'caderno'?, 'layout'?, 'confianca'?]
 * Sem layout (ou com um layout ainda sem formato), o leitor detecta o formato de cada folha.
 */
function ler_folhas(array $arquivos, ?array $layout = null): array
{
    $pastaDebug = pasta_temporaria() . '/lidas';
    $cmd = array_merge([PYTHON, OMR_CLI, 'ler'], $arquivos, ['--debug', $pastaDebug]);
    if ($layout && formato_conhecido($layout)) {
        $layoutJson = tempnam(pasta_temporaria(), 'layout');
        file_put_contents($layoutJson, json_encode(layout_do_leitor($layout), JSON_UNESCAPED_UNICODE));
        array_push($cmd, '--layout', $layoutJson);
    }
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

/* ------------------------------------------------------------ Relatório */

/** Folhas da prova: [lidas, falhas]. As lidas já vêm corrigidas (acertos, n_acertos, taxa). */
function carregar_folhas(array $prova): array
{
    $st = db()->prepare('SELECT * FROM folhas WHERE prova_id = ? ORDER BY turma, id');
    $st->execute([$prova['id']]);
    $lidas = $falhas = [];
    foreach ($st->fetchAll() as $f) {
        if ($f['erro'] !== null) {
            $falhas[] = $f;
            continue;
        }
        $f['respostas'] = json_decode($f['respostas'], true);
        $f['revisar'] = json_decode($f['revisar'], true);
        $lidas[] = corrigir_folha($f, $prova['gabarito']);
    }
    return [$lidas, $falhas];
}

/** Acrescenta à folha os acertos por questão, o total de acertos e a taxa. */
function corrigir_folha(array $folha, array $gabarito): array
{
    $folha['acertos'] = corrigir($gabarito, $folha['respostas']);
    $folha['n_acertos'] = count(array_filter($folha['acertos']));
    $folha['taxa'] = pct($folha['n_acertos'], count($gabarito));
    return $folha;
}

/** Nível pela proporção de acerto (índice de dificuldade clássico), ou null sem folhas. */
function dificuldade_observada(?float $taxa): ?string
{
    if ($taxa === null) {
        return null;
    }
    return $taxa > LIMITE_FACIL ? 'facil' : ($taxa < LIMITE_DIFICIL ? 'dificil' : 'medio');
}

/** Questão sem disciplina (provas antigas, sem áreas) conta como "Geral". */
function disciplina_da_questao(array $q): string
{
    return $q['disciplina'] !== null && $q['disciplina'] !== '' ? $q['disciplina'] : 'Geral';
}

/** Níveis de dificuldade usados na prova, na ordem Fácil, Médio, Difícil. */
function niveis_da_prova(array $questoes): array
{
    $usados = array_column($questoes, 'dificuldade');
    return array_values(array_filter(array_keys(DIFICULDADES), fn($n) => in_array($n, $usados, true)));
}

/**
 * Resumo por turma: folhas, acerto geral, alunos para reforço, folhas a revisar e acerto
 * por disciplina e por nível de dificuldade ([acertos, respostas] em cada grupo).
 */
function resumo_por_turma(array $questoes, array $folhas): array
{
    $resumo = [];
    foreach ($folhas as $f) {
        $t = $f['turma'];
        $resumo[$t] ??= ['folhas' => 0, 'acertos' => 0, 'total' => 0, 'reforco' => 0, 'revisar' => 0,
            'disciplinas' => [], 'niveis' => []];
        $r = &$resumo[$t];
        $r['folhas']++;
        $r['acertos'] += $f['n_acertos'];
        $r['total'] += count($questoes);
        $r['reforco'] += $f['taxa'] !== null && $f['taxa'] < LIMITE_REFORCO ? 1 : 0;
        $r['revisar'] += $f['revisar'] ? 1 : 0;
        foreach ($questoes as $i => $q) {
            $certo = (int)($f['acertos'][$i] ?? false);
            $d = disciplina_da_questao($q);
            $r['disciplinas'][$d] = [($r['disciplinas'][$d][0] ?? 0) + $certo, ($r['disciplinas'][$d][1] ?? 0) + 1];
            if ($q['dificuldade'] !== null) {
                $n = $q['dificuldade'];
                $r['niveis'][$n] = [($r['niveis'][$n][0] ?? 0) + $certo, ($r['niveis'][$n][1] ?? 0) + 1];
            }
        }
        unset($r);
    }
    ksort($resumo, SORT_NATURAL);
    return $resumo;
}

/**
 * Uma linha por questão, calculada sobre as folhas recebidas (uma turma ou todas):
 * gabarito, disciplina, dificuldade marcada e observada, acerto e o erro comum
 * (a alternativa errada mais marcada; branco e dupla não entram).
 */
function estatisticas_das_questoes(array $questoes, array $folhas): array
{
    $linhas = [];
    foreach ($questoes as $i => $q) {
        $acertos = $brancos = 0;
        $erradas = [];
        foreach ($folhas as $f) {
            $r = $f['respostas'][$i] ?? null;
            if ($r === $q['resposta']) {
                $acertos++;
            } elseif ($r === null || $r === 'MULTIPLA') {
                $brancos++;
            } else {
                $erradas[$r] = ($erradas[$r] ?? 0) + 1;
            }
        }
        ksort($erradas);
        arsort($erradas); // ordenação estável: no empate fica a primeira letra
        $taxa = pct($acertos, count($folhas));
        $observada = dificuldade_observada($taxa);
        $habilidade = trim((string)($q['habilidade'] ?? ''));
        $linhas[] = [
            'numero' => (int)$q['numero'],
            'certa' => $q['resposta'],
            'disciplina' => disciplina_da_questao($q),
            'dificuldade' => $q['dificuldade'],
            'habilidade' => $habilidade !== '' ? $habilidade : null,
            'alunos' => count($folhas),
            'acertos' => $acertos,
            'brancos' => $brancos,
            'taxa' => $taxa,
            'distrator' => $erradas ? array_key_first($erradas) : null,
            'distrator_qtd' => $erradas ? reset($erradas) : 0,
            'observada' => $observada,
            'diverge' => $q['dificuldade'] !== null && $observada !== null && $q['dificuldade'] !== $observada,
        ];
    }
    return $linhas;
}

/**
 * Prioridades de revisão: questões com acerto abaixo do LIMITE_REFORCO, da mais urgente
 * para a menos urgente. Primeiro as fáceis e médias (conteúdo que a turma deveria dominar),
 * depois as sem nível marcado e por último as difíceis (acerto baixo já era esperado).
 * Dentro de cada grupo, do menor acerto para o maior; no empate, a fácil vem antes.
 */
function prioridades_de_revisao(array $estatisticas): array
{
    $grupo = ['facil' => 0, 'medio' => 0, '' => 1, 'dificil' => 2];
    $lista = array_values(array_filter($estatisticas, fn($e) => $e['taxa'] !== null && $e['taxa'] < LIMITE_REFORCO));
    foreach ($lista as &$e) {
        $e['grupo'] = $grupo[$e['dificuldade'] ?? ''];
        $e['motivo'] = motivo_da_prioridade($e);
    }
    unset($e);
    usort($lista, fn($a, $b) => [$a['grupo'], $a['taxa'], $a['dificuldade'] === 'medio', $a['numero']]
        <=> [$b['grupo'], $b['taxa'], $b['dificuldade'] === 'medio', $b['numero']]);
    return $lista;
}

/** "Marcada como fácil, mas só 35,0% acertaram. Erro comum: B (12 alunos)." */
function motivo_da_prioridade(array $e): string
{
    $acerto = fmt_pct($e['taxa']);
    $texto = match ($e['dificuldade']) {
        'facil', 'medio' => 'Marcada como ' . mb_strtolower(DIFICULDADES[$e['dificuldade']]) . ", mas só $acerto acertaram.",
        'dificil' => "Questão difícil: $acerto de acerto.",
        default => "$acerto de acerto (sem nível marcado).",
    };
    if ($e['distrator'] !== null) {
        $texto .= " Erro comum: {$e['distrator']} ({$e['distrator_qtd']} " . ($e['distrator_qtd'] === 1 ? 'aluno' : 'alunos') . ').';
    }
    if ($e['alunos'] > 0 && $e['brancos'] * 2 >= $e['alunos']) {
        $texto .= " {$e['brancos']} em branco ou com marcação dupla.";
    }
    return $texto;
}

/**
 * Acerto por habilidade (descritor), juntando as questões que a avaliam, do menor para o maior.
 * Questões sem habilidade ficam de fora.
 */
function acerto_por_habilidade(array $estatisticas): array
{
    $grupos = [];
    foreach ($estatisticas as $e) {
        if ($e['habilidade'] === null) {
            continue;
        }
        $h = $e['habilidade'];
        $grupos[$h] ??= ['habilidade' => $h, 'questoes' => [], 'acertos' => 0, 'respostas' => 0];
        $grupos[$h]['questoes'][] = $e['numero'];
        $grupos[$h]['acertos'] += $e['acertos'];
        $grupos[$h]['respostas'] += $e['alunos'];
    }
    foreach ($grupos as &$g) {
        $g['taxa'] = pct($g['acertos'], $g['respostas']);
    }
    unset($g);
    $grupos = array_values($grupos);
    usort($grupos, fn($a, $b) => [$a['taxa'] ?? 101, $a['habilidade']] <=> [$b['taxa'] ?? 101, $b['habilidade']]);
    return $grupos;
}

/**
 * Dificuldade marcada pelo professor × observada nas folhas: [marcada][observada] => número de
 * questões, só das que têm as duas. Cada questão divergente vem marcada em estatisticas_das_questoes().
 */
function matriz_de_dificuldade(array $estatisticas): array
{
    $niveis = array_keys(DIFICULDADES);
    $matriz = array_fill_keys($niveis, array_fill_keys($niveis, 0));
    foreach ($estatisticas as $e) {
        if ($e['dificuldade'] !== null && $e['observada'] !== null) {
            $matriz[$e['dificuldade']][$e['observada']]++;
        }
    }
    return $matriz;
}

/** Filtros do mapa de questões por disciplina e dificuldade ('sem' = sem nível marcado); vazio = todas. */
function filtrar_questoes(array $estatisticas, string $disciplina, string $dificuldade): array
{
    return array_values(array_filter($estatisticas, fn($e) =>
        ($disciplina === '' || $e['disciplina'] === $disciplina)
        && ($dificuldade === '' || ($e['dificuldade'] ?? 'sem') === $dificuldade)));
}

/**
 * Arquivos de um campo de $_FILES (um ou vários). Confere o tamanho e o tipo real pelo
 * conteúdo (finfo): um arquivo renomeado para .jpg não passa. A extensão vem do tipo real.
 */
function upload_normalizado(array $campo): array
{
    $arquivos = [];
    if (!isset($campo['name'])) {
        return $arquivos;
    }
    $nomes = (array)$campo['name'];
    $limite = intdiv(TAMANHO_MAXIMO_ARQUIVO, 1024 * 1024) . ' MB';
    foreach ($nomes as $i => $nome) {
        $erro = ((array)$campo['error'])[$i];
        if ($erro === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($erro === UPLOAD_ERR_INI_SIZE || $erro === UPLOAD_ERR_FORM_SIZE) {
            throw new ErroDoUsuario("\"$nome\" passa do tamanho máximo de envio.", 413);
        }
        if ($erro !== UPLOAD_ERR_OK) {
            throw new ErroDoUsuario("Falha no envio de \"$nome\" (código $erro). Tente de novo.", 400);
        }
        $tmp = ((array)$campo['tmp_name'])[$i];
        if (filesize($tmp) > TAMANHO_MAXIMO_ARQUIVO) {
            throw new ErroDoUsuario("\"$nome\" tem mais de $limite.", 413);
        }
        $ext = tipo_do_arquivo($tmp);
        if ($ext === null) {
            throw new ErroDoUsuario("\"$nome\" não é PNG, JPG, TIFF ou PDF.", 415);
        }
        $arquivos[] = ['nome' => $nome, 'tmp' => $tmp, 'ext' => $ext];
    }
    return $arquivos;
}

/** Extensão do arquivo pelo conteúdo (jpg, png, tif, pdf), ou null se não for um tipo aceito. */
function tipo_do_arquivo(string $caminho): ?string
{
    $tipo = (new finfo(FILEINFO_MIME_TYPE))->file($caminho);
    return TIPOS_ACEITOS[$tipo] ?? null;
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
        throw new ErroDoUsuario('Prova não encontrada.', 404);
    }
    $st = db()->prepare('SELECT numero, resposta, disciplina, dificuldade, habilidade, duvidosa
                         FROM questoes WHERE prova_id = ? ORDER BY numero');
    $st->execute([$id]);
    $questoes = $st->fetchAll();
    foreach ($questoes as &$q) {
        $q['duvidosa'] = in_array($q['duvidosa'], [true, 't', 1, '1'], true);
    }
    unset($q);
    $prova['layout'] = json_decode($prova['layout'], true) + ['nome' => $prova['nome'], 'questoes' => count($questoes)];
    $prova['layout']['areas'] = areas_das_questoes($questoes);
    $prova['questoes'] = $questoes;
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
    if ($professor = professor_logado()) {
        echo '<form method="post" action="sair.php" class="sessao">' . campo_csrf()
            . '<span>' . e($professor['email']) . '</span><button type="submit" class="secundario">Sair</button></form>';
    }
    echo '</header><h1>' . e($titulo) . '</h1>';
}

function rodape(): void
{
    echo '</main></body></html>';
}
