<?php

declare(strict_types=1);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($method !== 'GET') {
    http_response_code(405);
    return;
}

if ($path === '/ping') {
    header('Content-Type: text/plain');
    echo 'pong';
    return;
}

if ($path === '/json') {
    header('Content-Type: application/json');
    echo json_encode([
        'framework' => 'raw-php',
        'status'    => 'ok',
        'items'     => [1, 2, 3, 4, 5],
    ]);
    return;
}

if (preg_match('#^/users/(\d+)$#', $path, $m)) {
    header('Content-Type: application/json');
    echo json_encode([
        'id'   => (int) $m[1],
        'name' => 'User ' . $m[1],
    ]);
    return;
}

http_response_code(404);
echo 'Not Found';
