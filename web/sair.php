<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

// Sair só por POST com o token CSRF: um link em outro site não desconecta o professor.
set_exception_handler('pagina_de_erro');
iniciar_sessao();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirecionar('index.php');
}
conferir_csrf($_POST['csrf'] ?? null);
sair();
redirecionar('login.php');
