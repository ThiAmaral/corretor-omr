<?php
declare(strict_types=1);

// Controlador frontal da API: /api/<rota>. As rotas e o formato das respostas estão em src/api.php.
require dirname(__DIR__) . '/lib.php';
require dirname(__DIR__) . '/src/api.php';

atender_api($_SERVER);
