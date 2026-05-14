<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = new Leaf\App();

$app->get('/ping', function () use ($app) {
    $app->response()->plain('pong');
});

$app->get('/json', function () use ($app) {
    $app->response()->json([
        'framework' => 'leaf',
        'status'    => 'ok',
        'items'     => [1, 2, 3, 4, 5],
    ]);
});

$app->get('/users/{id}', function ($id) use ($app) {
    $app->response()->json([
        'id'   => (int) $id,
        'name' => 'User ' . $id,
    ]);
});

$app->run();
