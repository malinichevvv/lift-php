<?php

/**
 * Lift internal micro-benchmark.
 *
 * Measures key subsystem throughput in isolation — no HTTP server overhead.
 *
 * Run:
 *   php benchmarks/micro.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Lift\Container\Container;
use Lift\Crypto\Encrypter;
use Lift\Crypto\Signer;
use Lift\Http\Request;
use Lift\Http\Response;
use Lift\Jwt\Claims;
use Lift\Jwt\Jwt;
use Lift\Routing\Router;
use Lift\Support\Uuid;

// ---------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------

function bench(string $label, int $iterations, callable $fn): void
{
    // Warm-up
    for ($i = 0; $i < min(100, $iterations / 10); $i++) {
        $fn();
    }

    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $fn();
    }
    $ns   = hrtime(true) - $start;
    $ms   = $ns / 1_000_000;
    $ops  = (int) ($iterations / ($ns / 1_000_000_000));
    $each = round($ns / $iterations / 1_000, 2);

    printf("%-40s %8d ops/s  %6.2f µs/op  %6.2f ms total\n", $label, $ops, $each, $ms);
}

$iterations = (int) ($argv[1] ?? 10_000);

printf("Lift Micro-Benchmark — PHP %s — %s iterations\n", PHP_VERSION, number_format($iterations));
printf("%s\n\n", str_repeat('-', 80));

// ---------------------------------------------------------------
// Router — static fast-path
// ---------------------------------------------------------------

$container = new Container();
$router    = new Router($container);
$router->add('GET', '/', fn() => 'home');
$router->add('GET', '/ping', fn() => 'pong');
$router->add('GET', '/users/{id}', fn() => 'user');
$router->add('GET', '/posts/{slug:[a-z0-9-]+}', fn() => 'post');

$staticReq  = Request::fromGlobals();
$dynamicReq = Request::fromGlobals();

bench('Router: static route  GET /', $iterations, function () use ($router, $staticReq) {
    $router->dispatch($staticReq, []);
});

$_SERVER['REQUEST_URI'] = '/users/42';
$dynamicUserReq = Request::fromGlobals();
bench('Router: dynamic route GET /users/{id}', $iterations, function () use ($router, $dynamicUserReq) {
    $router->dispatch($dynamicUserReq, []);
});

// ---------------------------------------------------------------
// Container
// ---------------------------------------------------------------

class BenchService
{
    public function __construct(public readonly string $value = 'ok') {}
}

$container->singleton(BenchService::class);

bench('Container: singleton make()', $iterations, function () use ($container) {
    $container->make(BenchService::class);
});

$c2 = new Container();
bench('Container: autowire make()', $iterations, function () use ($c2) {
    $c2->make(BenchService::class);
});

// ---------------------------------------------------------------
// JWT
// ---------------------------------------------------------------

$jwt = new Jwt(secret: 'benchmark-secret-key-for-testing-only');

$payload = Claims::make()
    ->subject('bench_user')
    ->expiresIn(3600)
    ->extra(['role' => 'admin'])
    ->toArray();

$token = $jwt->encode($payload);

bench('JWT: encode HS256', $iterations, function () use ($jwt, $payload) {
    $jwt->encode($payload);
});

bench('JWT: decode+verify HS256', $iterations, function () use ($jwt, $token) {
    $jwt->decode($token);
});

// ---------------------------------------------------------------
// Crypto
// ---------------------------------------------------------------

$key       = Encrypter::generateKey();
$encrypter = new Encrypter($key);
$plaintext = 'Hello, World! This is a test payload for AES-256-GCM benchmarking.';
$ciphertext = $encrypter->encrypt($plaintext);

bench('Encrypter: encrypt AES-256-GCM', $iterations, function () use ($encrypter, $plaintext) {
    $encrypter->encrypt($plaintext);
});

bench('Encrypter: decrypt AES-256-GCM', $iterations, function () use ($encrypter, $ciphertext) {
    $encrypter->decrypt($ciphertext);
});

$signer = new Signer('benchmark-hmac-secret-key');
$data   = 'payload data to sign for benchmarking purposes';
$sig    = $signer->sign($data);

bench('Signer: sign HMAC-SHA256', $iterations, function () use ($signer, $data) {
    $signer->sign($data);
});

bench('Signer: verify HMAC-SHA256', $iterations, function () use ($signer, $data, $sig) {
    $signer->verify($data, $sig);
});

// ---------------------------------------------------------------
// UUID / ULID
// ---------------------------------------------------------------

bench('Uuid::v4()', $iterations, fn() => Uuid::v4());
bench('Uuid::v7()', $iterations, fn() => Uuid::v7());
bench('Uuid::ulid()', $iterations, fn() => Uuid::ulid());

// ---------------------------------------------------------------
// Response serialization
// ---------------------------------------------------------------

$data = ['status' => 'ok', 'users' => range(1, 50), 'meta' => ['page' => 1, 'total' => 500]];

bench('Response::json() serialize', $iterations, function () use ($data) {
    Response::json($data);
});

printf("\n%s\n", str_repeat('-', 80));
printf("Done. Memory peak: %s KB\n", number_format(memory_get_peak_usage() / 1024, 1));
