<?php

declare(strict_types=1);

namespace Lift\Testing;

use Lift\Http\Response;
use PHPUnit\Framework\Assert;

/**
 * Fluent wrapper around {@see Response} for in-test assertions.
 *
 * Returned by every `TestCase::get()` / `post()` / … call. All `assert*`
 * methods return `$this` so assertions can be chained:
 *
 * ```php
 * $this->post('/api/users', ['name' => 'Alice'])
 *      ->assertStatus(201)
 *      ->assertJson(['name' => 'Alice']);
 * ```
 */
final class TestResponse
{
    private string $body;

    public function __construct(private readonly Response $response)
    {
        $this->body = (string) $response->getBody();
    }

    // -----------------------------------------------------------------
    // Raw accessors
    // -----------------------------------------------------------------

    /** Return the HTTP status code. */
    public function status(): int
    {
        return $this->response->getStatusCode();
    }

    /** Return the raw response body string. */
    public function body(): string
    {
        return $this->body;
    }

    /**
     * Decode and return the response body as an associative array.
     *
     * @throws \RuntimeException When the body is not valid JSON.
     */
    public function json(): array
    {
        $data = json_decode($this->body, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Response body is not valid JSON: ' . $this->body);
        }
        return $data;
    }

    /**
     * Return the value of a response header (first value only), or `null` when absent.
     */
    public function header(string $name): ?string
    {
        $values = $this->response->getHeader($name);
        return $values !== [] ? $values[0] : null;
    }

    /** Return the underlying PSR-7 response. */
    public function getResponse(): Response
    {
        return $this->response;
    }

    // -----------------------------------------------------------------
    // Status assertions
    // -----------------------------------------------------------------

    /** Assert that the response has the given HTTP status code. */
    public function assertStatus(int $expected): static
    {
        Assert::assertSame(
            $expected,
            $this->status(),
            "Expected HTTP status {$expected}, got {$this->status()}.",
        );
        return $this;
    }

    /** Assert HTTP 200. */
    public function assertOk(): static
    {
        return $this->assertStatus(200);
    }

    /** Assert HTTP 201. */
    public function assertCreated(): static
    {
        return $this->assertStatus(201);
    }

    /** Assert HTTP 204. */
    public function assertNoContent(): static
    {
        return $this->assertStatus(204);
    }

    /** Assert HTTP 301 or 302 (or any 3xx redirect). */
    public function assertRedirect(?string $url = null): static
    {
        Assert::assertTrue(
            $this->status() >= 300 && $this->status() < 400,
            "Expected a redirect response, got HTTP {$this->status()}.",
        );
        if ($url !== null) {
            $this->assertHeader('Location', $url);
        }
        return $this;
    }

    /** Assert HTTP 401. */
    public function assertUnauthorized(): static
    {
        return $this->assertStatus(401);
    }

    /** Assert HTTP 403. */
    public function assertForbidden(): static
    {
        return $this->assertStatus(403);
    }

    /** Assert HTTP 404. */
    public function assertNotFound(): static
    {
        return $this->assertStatus(404);
    }

    /** Assert HTTP 422. */
    public function assertUnprocessable(): static
    {
        return $this->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // Header assertions
    // -----------------------------------------------------------------

    /**
     * Assert that a response header exists, optionally checking its value.
     *
     * @param string|null $value Expected header value; `null` checks existence only.
     */
    public function assertHeader(string $name, ?string $value = null): static
    {
        Assert::assertTrue(
            $this->response->hasHeader($name),
            "Expected response header [{$name}] to be present.",
        );
        if ($value !== null) {
            Assert::assertSame(
                $value,
                $this->header($name),
                "Expected header [{$name}] to equal [{$value}].",
            );
        }
        return $this;
    }

    /** Assert that the Content-Type header contains the given media type. */
    public function assertContentType(string $type): static
    {
        $ct = (string) $this->header('Content-Type');
        Assert::assertStringContainsString(
            $type,
            $ct,
            "Expected Content-Type to contain [{$type}], got [{$ct}].",
        );
        return $this;
    }

    // -----------------------------------------------------------------
    // Body / JSON assertions
    // -----------------------------------------------------------------

    /** Assert that the response body contains the given string. */
    public function assertSee(string $text): static
    {
        Assert::assertStringContainsString(
            $text,
            $this->body,
            "Expected response body to contain [{$text}].",
        );
        return $this;
    }

    /** Assert that the response body does NOT contain the given string. */
    public function assertDontSee(string $text): static
    {
        Assert::assertStringNotContainsString(
            $text,
            $this->body,
            "Expected response body NOT to contain [{$text}].",
        );
        return $this;
    }

    /**
     * Assert that the JSON body contains the given key-value pairs.
     *
     * When `$exact` is `true`, the entire body must match `$expected` exactly.
     *
     * @param array<string, mixed> $expected
     */
    public function assertJson(array $expected, bool $exact = false): static
    {
        $actual = $this->json();
        if ($exact) {
            Assert::assertSame($expected, $actual, 'JSON body does not match exactly.');
        } else {
            foreach ($expected as $key => $value) {
                Assert::assertArrayHasKey($key, $actual, "JSON body is missing key [{$key}].");
                Assert::assertSame($value, $actual[$key], "JSON key [{$key}] does not match.");
            }
        }
        return $this;
    }

    /**
     * Assert that the decoded JSON body contains the given key (dot-notation supported).
     *
     * ```php
     * $response->assertJsonHas('user.email');
     * ```
     */
    public function assertJsonHas(string $key): static
    {
        $data = $this->json();
        $current = $data;
        foreach (explode('.', $key) as $segment) {
            Assert::assertIsArray($current, "JSON path [{$key}] could not be traversed.");
            Assert::assertArrayHasKey($segment, $current, "JSON body is missing key [{$key}].");
            $current = $current[$segment];
        }
        return $this;
    }

    /**
     * Assert that a dot-notation JSON path equals the expected value.
     *
     * ```php
     * $response->assertJsonPath('data.status', 'active');
     * ```
     */
    public function assertJsonPath(string $path, mixed $expected): static
    {
        $data = $this->json();
        $current = $data;
        foreach (explode('.', $path) as $segment) {
            Assert::assertIsArray($current, "JSON path [{$path}] could not be traversed.");
            Assert::assertArrayHasKey($segment, $current, "JSON path [{$path}] not found.");
            $current = $current[$segment];
        }
        Assert::assertSame($expected, $current, "JSON path [{$path}] does not equal expected value.");
        return $this;
    }

    /** Assert that the JSON body is an array containing at least `$count` items. */
    public function assertJsonCount(int $count, ?string $key = null): static
    {
        $data = $this->json();
        if ($key !== null) {
            Assert::assertArrayHasKey($key, $data);
            $data = $data[$key];
        }
        Assert::assertCount($count, $data, "Expected JSON to have {$count} items.");
        return $this;
    }
}
