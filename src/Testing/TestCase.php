<?php

declare(strict_types=1);

namespace Lift\Testing;

use Lift\App;
use Lift\Http\Request;
use Lift\Http\Stream;
use Lift\Http\Uri;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;

/**
 * Base PHPUnit test case for Lift applications.
 *
 * Extend this class in your feature/integration tests. Override {@see createApp()}
 * to bootstrap your application (bind services, register routes, etc.):
 *
 * ```php
 * final class UserTest extends TestCase
 * {
 *     protected function createApp(): App
 *     {
 *         $app = new App();
 *         $app->get('/users/{id}', fn($req) => Response::json(['id' => $req->param('id')]));
 *         return $app;
 *     }
 *
 *     public function testGetUser(): void
 *     {
 *         $this->get('/users/42')
 *              ->assertOk()
 *              ->assertJson(['id' => '42']);
 *     }
 * }
 * ```
 *
 * Every `get()` / `post()` / … call goes through `App::handle()`, which runs
 * the full middleware stack but does **not** emit headers or output — safe for
 * PHPUnit.
 */
abstract class TestCase extends PhpUnitTestCase
{
    protected App $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createApp();
    }

    /**
     * Bootstrap the application under test.
     *
     * Override this in your test class to register routes, bind services, load
     * configuration, etc. The returned `App` instance is stored as `$this->app`.
     */
    protected function createApp(): App
    {
        return new App();
    }

    // -----------------------------------------------------------------
    // HTTP helpers
    // -----------------------------------------------------------------

    /**
     * Dispatch a GET request and return a {@see TestResponse}.
     *
     * @param array<string, string> $headers Additional request headers.
     */
    protected function get(string $uri, array $headers = []): TestResponse
    {
        return $this->request('GET', $uri, [], $headers);
    }

    /**
     * Dispatch a POST request and return a {@see TestResponse}.
     *
     * `$data` is sent as JSON when `Content-Type` is not overridden.
     *
     * @param array<string, mixed>  $data    Request body data.
     * @param array<string, string> $headers Additional request headers.
     */
    protected function post(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->request('POST', $uri, $data, $headers);
    }

    /**
     * Dispatch a PUT request and return a {@see TestResponse}.
     *
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     */
    protected function put(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->request('PUT', $uri, $data, $headers);
    }

    /**
     * Dispatch a PATCH request and return a {@see TestResponse}.
     *
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     */
    protected function patch(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->request('PATCH', $uri, $data, $headers);
    }

    /**
     * Dispatch a DELETE request and return a {@see TestResponse}.
     *
     * @param array<string, string> $headers
     */
    protected function delete(string $uri, array $headers = []): TestResponse
    {
        return $this->request('DELETE', $uri, [], $headers);
    }

    /**
     * Dispatch an arbitrary HTTP request and return a {@see TestResponse}.
     *
     * Body data is serialised as JSON and the `Content-Type` header is set to
     * `application/json` unless the caller provides it explicitly.
     *
     * @param array<string, mixed>  $data    Body data (ignored for GET/HEAD/DELETE).
     * @param array<string, string> $headers Additional request headers.
     */
    protected function request(string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        $method = strtoupper($method);

        $parsedBody = [];
        $body = Stream::empty();
        $defaultHeaders = [];

        if ($data !== [] && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $json = (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $body = Stream::fromString($json);
            $parsedBody = $data;
            $defaultHeaders['Content-Type'] = 'application/json; charset=utf-8';
        }

        $merged = array_merge($defaultHeaders, $headers);

        // Separate query string from path
        $queryParams = [];
        if (str_contains($uri, '?')) {
            [, $qs] = explode('?', $uri, 2);
            parse_str($qs, $queryParams);
        }

        $request = new Request(
            method: $method,
            uri: new Uri($uri),
            headers: $merged,
            body: $body,
            queryParams: $queryParams,
            parsedBody: $parsedBody,
        );

        return new TestResponse($this->app->handle($request));
    }

    // -----------------------------------------------------------------
    // Convenience wrappers
    // -----------------------------------------------------------------

    /**
     * Make a GET request and assert the response is 200 OK.
     */
    protected function getJson(string $uri, array $headers = []): TestResponse
    {
        return $this->get($uri, array_merge(['Accept' => 'application/json'], $headers))
                    ->assertOk();
    }

    /**
     * Make a POST request with JSON data and assert the response is 200 or 201.
     */
    protected function postJson(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->post($uri, $data, array_merge(['Accept' => 'application/json'], $headers));
    }
}
