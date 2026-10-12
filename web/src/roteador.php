<?php
declare(strict_types=1);

/*
 * Roteador do servidor embutido do PHP (php -S), usado sem Docker (iniciar.cmd):
 *   php -S localhost:8000 -t web web/src/roteador.php
 * Faz o que o Apache faz no Docker: /api/... vai para api/index.php, e as pastas
 * src/ e testes/ não podem ser abertas pelo navegador.
 */
$caminho = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
if (preg_match('#^/(src|testes)(/|$)#i', $caminho)) {
    http_response_code(404);
    return true;
}
if (preg_match('#^/api(/|$)#', $caminho)) {
    require dirname(__DIR__) . '/api/index.php';
    return true;
}
return false; // o resto (páginas, CSS, JS) o servidor embutido entrega normalmente
