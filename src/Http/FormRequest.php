<?php

declare(strict_types=1);

namespace Lift\Http;

/**
 * Base class for validated request DTOs.
 *
 * FormRequest gives controllers a small, framework-native alternative to large
 * request objects. Subclasses declare validation rules and optional hooks, then
 * call {@see fromRequest()} to validate an incoming {@see Request} and hydrate a
 * reusable object with the validated data.
 *
 * ```php
 * final class StoreUserRequest extends FormRequest
 * {
 *     public function rules(): array
 *     {
 *         return ['email' => 'required|email', 'name' => 'required|string'];
 *     }
 * }
 *
 * $form = StoreUserRequest::fromRequest($request);
 * $email = $form->string('email');
 * ```
 */
abstract class FormRequest
{
    /** @param array<string, mixed> $validated */
    final public function __construct(
        protected readonly Request $request,
        protected readonly array $validated,
    ) {}

    /**
     * Build and validate the form object from an HTTP request.
     *
     * @template T of self
     * @param Request $request Source request.
     * @param class-string<T>|null $class Explicit subclass, defaults to late static class.
     * @return T
     */
    public static function fromRequest(Request $request, ?string $class = null): static
    {
        $class ??= static::class;
        $prototype = new $class($request, []);
        $prototype->authorize($request);
        $validated = $request->validate($prototype->rules());
        $prototype->afterValidation($validated, $request);

        return new $class($request, $validated);
    }

    /**
     * Return validation rules accepted by {@see \Lift\Validation\Validator}.
     *
     * @return array<string, string|string[]>
     */
    abstract public function rules(): array;

    /**
     * Authorize the request before validation.
     *
     * Throw an exception or return a response from the controller if the current
     * user is not allowed to perform the action. The default allows everything.
     */
    public function authorize(Request $request): void {}

    /**
     * Hook called after validation and before the final immutable form object is returned.
     *
     * @param array<string, mixed> $validated
     */
    public function afterValidation(array $validated, Request $request): void {}

    /** Return all validated input. */
    public function validated(): array
    {
        return $this->validated;
    }

    /** Return a validated value by key. */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->validated[$key] ?? $default;
    }

    /** Return a validated value as string. */
    public function string(string $key, string $default = ''): string
    {
        return (string) ($this->validated[$key] ?? $default);
    }

    /** Return a validated value as integer. */
    public function integer(string $key, int $default = 0): int
    {
        return (int) ($this->validated[$key] ?? $default);
    }

    /** Return the original HTTP request. */
    public function request(): Request
    {
        return $this->request;
    }
}
