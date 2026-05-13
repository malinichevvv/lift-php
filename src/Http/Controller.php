<?php

declare(strict_types=1);

namespace Lift\Http;

/**
 * Convenience base controller with common response helpers.
 *
 * Controllers are optional in Lift: closures, invokable classes, and attribute
 * controllers all work without extending anything. This base class simply keeps
 * repetitive response creation out of application controllers.
 */
abstract class Controller
{
    /** Return a JSON response. */
    protected function json(mixed $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    /** Return an HTML response. */
    protected function html(string $content, int $status = 200): Response
    {
        return Response::html($content, $status);
    }

    /** Return a plain text response. */
    protected function text(string $content, int $status = 200): Response
    {
        return Response::text($content, $status);
    }

    /** Return a redirect response. */
    protected function redirect(string $url, int $status = 302): Response
    {
        return Response::redirect($url, $status);
    }

    /** Return a no-content response. */
    protected function noContent(): Response
    {
        return Response::noContent();
    }
}
