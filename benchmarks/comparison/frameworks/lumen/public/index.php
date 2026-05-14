<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

(new Laravel\Lumen\Bootstrap\LoadEnvironmentVariables(
    dirname(__DIR__)
))->bootstrap();

date_default_timezone_set(env('APP_TIMEZONE', 'UTC'));

$app = new Laravel\Lumen\Application(dirname(__DIR__));

$app->router->get('/ping', function () {
    return response('pong', 200, ['Content-Type' => 'text/plain']);
});

$app->router->get('/json', function () {
    return response()->json([
        'framework' => 'lumen',
        'status'    => 'ok',
        'items'     => [1, 2, 3, 4, 5],
    ]);
});

$app->router->get('/users/{id}', function ($id) {
    return response()->json([
        'id'   => (int) $id,
        'name' => 'User ' . $id,
    ]);
});

$app->run();
