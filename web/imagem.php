<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

// Serve só imagens PNG de depuração que estão dentro da pasta de armazenamento.
$base = realpath(ARMAZENAMENTO);
$alvo = realpath(ARMAZENAMENTO . '/' . ($_GET['f'] ?? ''));
if (!$base || !$alvo || !str_starts_with($alvo, $base . DIRECTORY_SEPARATOR) || !str_ends_with($alvo, '.png')) {
    http_response_code(404);
    exit;
}
header('Content-Type: image/png');
header('Cache-Control: private, max-age=3600');
readfile($alvo);
