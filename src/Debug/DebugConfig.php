<?php

declare(strict_types=1);

namespace Lift\Debug;

use Lift\Http\Request;
use Lift\Http\Response;

/**
 * Normalised runtime configuration for Lift's debug subsystem.
 *
 * The debug layer is opt-in and safe by default: `enabled` is false unless the
 * application explicitly enables it. This object centralises decisions about
 * diagnostics collection, PHP error tracking, exception pages, sensitive data
 * masking, and toolbar injection.
 *
 * ```php
 * $config = DebugConfig::fromArray([
 *     'enabled' => true,
 *     'toolbar' => true,
 *     'position' => 'bottom-right',
 *     'hide' => [
 *         'headers' => ['Authorization', 'Cookie'],
 *         'params' => ['password', 'token'],
 *     ],
 * ]);
 * ```
 */
final class DebugConfig
{
    /**
     * @param array<string, mixed> $items Partial config merged over defaults.
     */
    public function __construct(private array $items = [])
    {
        $this->items = array_replace_recursive(self::defaults(), $items);
    }

    /**
     * Create debug configuration from a plain array.
     *
     * @param array<string, mixed> $items Partial config merged over defaults.
     */
    public static function fromArray(array $items): self
    {
        return new self($items);
    }

    /** Return whether any debug functionality is enabled. */
    public function enabled(): bool
    {
        return (bool) $this->items['enabled'];
    }

    /** Return whether the HTML toolbar should be rendered when possible. */
    public function toolbarEnabled(): bool
    {
        return $this->enabled() && (bool) $this->items['toolbar'];
    }

    /** Return whether PHP warnings/notices should be captured by the collector. */
    public function trackPhpErrors(): bool
    {
        return $this->enabled() && (bool) $this->items['track_php_errors'];
    }

    /** Return whether unhandled exceptions may render a detailed HTML page. */
    public function renderExceptionPages(): bool
    {
        return $this->enabled() && (bool) $this->items['exception_pages'];
    }

    /** Return toolbar position, currently `bottom-right` or `bottom-left`. */
    public function position(): string
    {
        return (string) $this->items['position'];
    }

    /**
     * Header names that must be masked before diagnostics are rendered.
     *
     * @return list<string>
     */
    public function hiddenHeaders(): array
    {
        return array_values((array) ($this->items['hide']['headers'] ?? []));
    }

    /**
     * Query, route, or body parameter names that must be masked.
     *
     * @return list<string>
     */
    public function hiddenParams(): array
    {
        return array_values((array) ($this->items['hide']['params'] ?? []));
    }

    /**
     * Decide whether the toolbar can be safely injected into a response.
     *
     * The toolbar is never injected into HEAD responses, 204/304 responses, or
     * requests that explicitly send `X-Debug-Toolbar: off`. With `only_html`
     * enabled, the response must advertise `text/html`.
     */
    public function shouldInject(Request $request, Response $response): bool
    {
        if (!$this->toolbarEnabled()) {
            return false;
        }

        if ($request->isMethod('HEAD') || strtolower($request->getHeaderLine('X-Debug-Toolbar')) === 'off') {
            return false;
        }

        if (in_array($response->getStatusCode(), [204, 304], true)) {
            return false;
        }

        $contentType = strtolower($response->getHeaderLine('Content-Type'));
        if ((bool) $this->items['only_html'] && !str_contains($contentType, 'text/html')) {
            return false;
        }

        return true;
    }

    /**
     * Return the fully normalised config array.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->items;
    }

    /** @return array<string, mixed> */
    private static function defaults(): array
    {
        return [
            'enabled' => false,
            'toolbar' => true,
            'position' => 'bottom-right',
            'only_html' => true,
            'track_php_errors' => true,
            'exception_pages' => true,
            'hide' => [
                'headers' => ['Authorization', 'Cookie', 'Set-Cookie'],
                'params' => ['password', 'password_confirmation', 'token', 'secret'],
            ],
        ];
    }
}
