<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Lift\App;
use Lift\Http\Request;
use Lift\Http\Response;

$app = new App();

// Static routes — served from O(1) hash map
$app->get('/', fn() => Response::json(['status' => 'ok']));
$app->get('/ping', fn() => 'pong');
$app->get('/health', fn() => Response::json(['healthy' => true]));

// Dynamic routes — regex match
$app->get('/users/{id}', function (Request $req) {
    return Response::json(['id' => $req->param('id')]);
});

$app->get('/posts/{slug:[a-z0-9-]+}', function (Request $req) {
    return Response::json(['slug' => $req->param('slug')]);
});

// JSON body echo
$app->post('/echo', function (Request $req) {
    return Response::json($req->json() ?? []);
});

$request  = Request::fromGlobals();
$response = $app->handle($request);
$app->send($response);
