<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Lift\App;
use Lift\Http\Request;
use Lift\Http\Response;

$app = new App();

$app->get('/ping', fn() => Response::text('pong'));

$app->get('/json', fn() => Response::json([
    'framework' => 'lift',
    'status'    => 'ok',
    'items'     => [1, 2, 3, 4, 5],
]));

$app->get('/users/{id}', fn(Request $req) => Response::json([
    'id'   => (int) $req->param('id'),
    'name' => 'User ' . $req->param('id'),
]));

$app->run();
