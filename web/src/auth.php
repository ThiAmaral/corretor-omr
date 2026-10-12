<?php
declare(strict_types=1);

/*
 * Login dos professores pelo Supabase Auth, sem bibliotecas (só curl).
 * - App: faz login direto no Supabase e manda o token (JWT) em toda chamada à API,
 *   no cabeçalho "Authorization: Bearer <token>". O PHP confere o token perguntando
 *   ao Supabase (GET /auth/v1/user) e guarda a resposta em cache por alguns minutos.
 * - Painel web: o formulário de login.php manda e-mail e senha ao Supabase
 *   (POST /auth/v1/token) e guarda o token na sessão PHP. Formulários com CSRF.
 * Os professores são cadastrados no painel do Supabase (Authentication > Users),
 * com o cadastro público desligado: só quem foi convidado entra.
 */

const CACHE_TOKEN_SEGUNDOS = 300;   // quanto tempo um token conferido vale sem perguntar de novo
const RENOVAR_ANTES_SEGUNDOS = 60;  // a sessão web renova o token um pouco antes de ele vencer

/* ------------------------------------------------------ Token (API, app) */

/** Token do cabeçalho "Authorization: Bearer ...", ou null. */
function token_do_cabecalho(array $servidor): ?string
{
    $cabecalho = $servidor['HTTP_AUTHORIZATION'] ?? $servidor['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(\S+)$/i', trim($cabecalho), $m)) {
        return null;
    }
    return $m[1];
}

/** Conteúdo (payload) de um JWT, sem conferir a assinatura: só serve para ler a validade. */
function conteudo_do_jwt(string $token): ?array
{
    $partes = explode('.', $token);
    if (count($partes) !== 3) {
        return null;
    }
    $json = base64_decode(strtr($partes[1], '-_', '+/'), true);
    $dados = $json === false ? null : json_decode($json, true);
    return is_array($dados) ? $dados : null;
}

/** Arquivo de cache do token: o nome é o hash, o token em si nunca vai para o disco. */
function arquivo_cache_token(string $token): string
{
    $pasta = sys_get_temp_dir() . '/corretor_tokens';
    if (!is_dir($pasta)) {
        mkdir($pasta, 0700, true);
    }
    return $pasta . '/' . hash('sha256', $token) . '.json';
}

/**
 * Professor dono do token (['id', 'email']), ou null se o token for inválido ou vencido.
 * A validade é conferida pelo próprio Supabase; a resposta fica em cache por até
 * CACHE_TOKEN_SEGUNDOS, nunca além do vencimento do token.
 */
function professor_do_token(string $token): ?array
{
    $conteudo = conteudo_do_jwt($token);
    if ($conteudo === null || (isset($conteudo['exp']) && $conteudo['exp'] <= time())) {
        return null; // nem parece um JWT, ou já venceu: não precisa perguntar ao Supabase
    }
    $cache = arquivo_cache_token($token);
    $guardado = is_file($cache) ? json_decode((string)file_get_contents($cache), true) : null;
    if (is_array($guardado) && $guardado['ate'] > time()) {
        return $guardado['professor'];
    }
    [$status, $dados] = supabase_http('GET', 'auth/v1/user', '', ["Authorization: Bearer $token"]);
    if ($status !== 200 || !is_array($dados) || empty($dados['id'])) {
        if (is_file($cache)) {
            unlink($cache);
        }
        return null;
    }
    $professor = ['id' => $dados['id'], 'email' => $dados['email'] ?? ''];
    $ate = min(time() + CACHE_TOKEN_SEGUNDOS, (int)($conteudo['exp'] ?? PHP_INT_MAX));
    file_put_contents($cache, json_encode(['professor' => $professor, 'ate' => $ate]), LOCK_EX);
    limpar_cache_de_tokens();
    return $professor;
}

/** De vez em quando, apaga os arquivos de cache vencidos. */
function limpar_cache_de_tokens(): void
{
    if (random_int(1, 50) !== 1) {
        return;
    }
    foreach (glob(sys_get_temp_dir() . '/corretor_tokens/*.json') ?: [] as $arquivo) {
        if (filemtime($arquivo) < time() - CACHE_TOKEN_SEGUNDOS) {
            @unlink($arquivo);
        }
    }
}

/* ---------------------------------------------------- Sessão (painel web) */

function iniciar_sessao(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('corretor');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => ($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

/** Professor da sessão web (['id', 'email']), ou null. Não abre sessão se não houver cookie. */
function professor_logado(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }
    return $_SESSION['professor'] ?? null;
}

/** Guarda na sessão a resposta de /auth/v1/token (login ou renovação). */
function guardar_sessao(array $dados): void
{
    $_SESSION['professor'] = ['id' => $dados['user']['id'], 'email' => $dados['user']['email'] ?? ''];
    $_SESSION['token'] = $dados['access_token'];
    $_SESSION['renovacao'] = $dados['refresh_token'];
    $_SESSION['expira_em'] = (int)($dados['expires_at'] ?? time() + (int)($dados['expires_in'] ?? 3600));
}

/** Login com e-mail e senha no Supabase Auth. Senha errada: ErroDoUsuario 401. */
function entrar(string $email, string $senha): void
{
    if (trim($email) === '' || $senha === '') {
        throw new ErroDoUsuario('Informe o e-mail e a senha.', 400);
    }
    [$status, $dados] = supabase_http('POST', 'auth/v1/token?grant_type=password',
        json_encode(['email' => trim($email), 'password' => $senha]));
    if ($status !== 200 || !is_array($dados) || empty($dados['access_token'])) {
        if ($status === 429) {
            throw new ErroDoUsuario('Muitas tentativas. Espere alguns minutos e tente de novo.', 429);
        }
        if ($status >= 500 || $status === 0) {
            throw new RuntimeException("O Supabase Auth não respondeu ($status). Tente de novo.");
        }
        throw new ErroDoUsuario('E-mail ou senha incorretos.', 401);
    }
    session_regenerate_id(true); // o id e o CSRF de antes do login não servem mais
    unset($_SESSION['csrf']);
    guardar_sessao($dados);
}

/** Troca o token vencido por um novo (refresh token). Falso se a sessão não puder ser renovada. */
function renovar_sessao(): bool
{
    if (empty($_SESSION['renovacao'])) {
        return false;
    }
    [$status, $dados] = supabase_http('POST', 'auth/v1/token?grant_type=refresh_token',
        json_encode(['refresh_token' => $_SESSION['renovacao']]));
    if ($status !== 200 || !is_array($dados) || empty($dados['access_token'])) {
        return false;
    }
    guardar_sessao($dados);
    return true;
}

/** Encerra a sessão web e avisa o Supabase (o refresh token deixa de valer). */
function sair(): void
{
    if (!empty($_SESSION['token'])) {
        try {
            supabase_http('POST', 'auth/v1/logout?scope=local', '', ["Authorization: Bearer {$_SESSION['token']}"]);
        } catch (Throwable) {
            // Mesmo sem resposta do Supabase, a sessão local é encerrada.
        }
    }
    $_SESSION = [];
    session_destroy();
}

/**
 * Início de toda página do painel: exige login (sem login, vai para login.php e volta
 * depois), confere o CSRF dos POST e mostra os erros de forma amigável.
 */
function iniciar_pagina(): array
{
    set_exception_handler('pagina_de_erro');
    iniciar_sessao();
    $vencida = ($_SESSION['expira_em'] ?? 0) < time() + RENOVAR_ANTES_SEGUNDOS;
    if (empty($_SESSION['professor']) || ($vencida && !renovar_sessao())) {
        $_SESSION = [];
        $volta = $_SERVER['REQUEST_METHOD'] === 'GET' ? ($_SERVER['REQUEST_URI'] ?? '') : '';
        redirecionar('login.php' . ($volta !== '' ? '?volta=' . urlencode($volta) : ''));
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        conferir_csrf($_POST['csrf'] ?? null);
    }
    return $_SESSION['professor'];
}

/** Erro não tratado numa página: ErroDoUsuario mostra a mensagem; o resto, uma mensagem genérica. */
function pagina_de_erro(Throwable $ex): void
{
    $status = $ex instanceof ErroDoUsuario ? $ex->status : 500;
    if ($status === 500) {
        error_log((string)$ex);
    }
    if (!headers_sent()) {
        http_response_code($status);
    }
    cabecalho($status === 404 ? 'Não encontrado' : 'Algo deu errado', 'index.php');
    echo '<p class="erro">' . e($status === 500 ? 'Erro no servidor: ' . $ex->getMessage() : $ex->getMessage()) . '</p>';
    rodape();
}

/** Endereço de volta depois do login: só caminhos deste site (nada de "//outro.site"). */
function destino_seguro(string $volta): string
{
    return preg_match('#^/(?![/\\\\])[^\s]*$#', $volta) ? $volta : 'index.php';
}

/* ---------------------------------------------------------------- CSRF */

function token_csrf(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function campo_csrf(): string
{
    return '<input type="hidden" name="csrf" value="' . e(token_csrf()) . '">';
}

/** Formulário sem o token da sessão (ex.: enviado de outro site) é recusado. */
function conferir_csrf(?string $enviado): void
{
    if (!is_string($enviado) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $enviado)) {
        throw new ErroDoUsuario('O formulário expirou. Recarregue a página e tente de novo.', 403);
    }
}
