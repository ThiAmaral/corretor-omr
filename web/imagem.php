<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

// A imagem anotada fica num bucket privado do Supabase Storage: gera uma URL
// assinada, que vale por poucos minutos, e manda o navegador para ela.
$st = db()->prepare('SELECT imagem_debug FROM folhas WHERE id = ?');
$st->execute([(int)($_GET['folha'] ?? 0)]);
$caminho = $st->fetchColumn();
if (!$caminho) {
    http_response_code(404);
    exit;
}
header('Cache-Control: private, no-store');
redirecionar(storage_url_assinada('anotadas', $caminho));
