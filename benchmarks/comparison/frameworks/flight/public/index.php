<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

Flight::route('GET /ping', function () {
    Flight::response()
        ->status(200)
        ->header('Content-Type', 'text/plain')
        ->write('pong');
});

Flight::route('GET /json', function () {
    Flight::json([
        'framework' => 'flight',
        'status'    => 'ok',
        'items'     => [1, 2, 3, 4, 5],
    ]);
});

Flight::route('GET /users/@id', function ($id) {
    Flight::json([
        'id'   => (int) $id,
        'name' => 'User ' . $id,
    ]);
});

Flight::start();
