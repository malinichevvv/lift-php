<?php

declare(strict_types=1);

namespace Lift\Validation;

use RuntimeException;

/**
 * Thrown when request validation fails.
 *
 * Carries a structured errors map (`field → [message, …]`) that can be
 * serialised directly to a JSON 422 response.
 *
 * ```php
 * try {
 *     $data = $req->validate(['email' => 'required|email']);
 * } catch (ValidationException $e) {
 *     return Response::json(['errors' => $e->errors()], 422);
 * }
 * ```
 */
final class ValidationException extends RuntimeException
{
    /**
     * @param array<string, string[]> $errors  Field → list of error messages.
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('The given data was invalid.');
    }

    /**
     * @return array<string, string[]>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Convenience factory matching Laravel's convention.
     *
     * @param array<string, string[]> $errors
     */
    public static function withErrors(array $errors): self
    {
        return new self($errors);
    }
}
