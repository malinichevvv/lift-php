<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Factory\AppFactory;

$app = AppFactory::create();

$app->get('/ping', function (ServerRequestInterface $req, ResponseInterface $res) {
    $res->getBody()->write('pong');
    return $res->withHeader('Content-Type', 'text/plain');
});

$app->get('/json', function (ServerRequestInterface $req, ResponseInterface $res) {
    $res->getBody()->write(json_encode([
        'framework' => 'slim',
        'status'    => 'ok',
        'items'     => [1, 2, 3, 4, 5],
    ]));
    return $res->withHeader('Content-Type', 'application/json');
});

$app->get('/users/{id}', function (ServerRequestInterface $req, ResponseInterface $res, array $args) {
    $res->getBody()->write(json_encode([
        'id'   => (int) $args['id'],
        'name' => 'User ' . $args['id'],
    ]));
    return $res->withHeader('Content-Type', 'application/json');
});

$app->run();
