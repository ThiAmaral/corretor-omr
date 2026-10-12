<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

// Login do painel web. A senha vai direto ao Supabase Auth; o PHP guarda só o token na sessão.
set_exception_handler('pagina_de_erro');
iniciar_sessao();
$volta = destino_seguro((string)($_GET['volta'] ?? ''));
if (professor_logado()) {
    redirecionar($volta);
}
$erro = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        conferir_csrf($_POST['csrf'] ?? null);
        entrar((string)($_POST['email'] ?? ''), (string)($_POST['senha'] ?? ''));
        redirecionar($volta);
    } catch (ErroDoUsuario $ex) {
        http_response_code($ex->status);
        $erro = $ex->getMessage();
    }
}

cabecalho('Entrar');
?>
<p class="meta">Use o e-mail e a senha cadastrados pela coordenação. O mesmo login vale no aplicativo.</p>
<?php if ($erro): ?><p class="erro" role="alert"><?= e($erro) ?></p><?php endif; ?>

<form method="post" class="formulario login">
  <?= campo_csrf() ?>
  <label>E-mail
    <input type="email" name="email" required autocomplete="username" autofocus value="<?= e($_POST['email'] ?? '') ?>">
  </label>
  <label>Senha
    <input type="password" name="senha" required autocomplete="current-password">
  </label>
  <button type="submit">Entrar</button>
</form>
<?php rodape();
