<?php

declare(strict_types=1);

namespace Lift\Http;

use JsonSerializable;

/**
 * Base class for transforming models or arrays into JSON API payloads.
 *
 * Resources keep controllers thin by moving response shaping into dedicated
 * classes. They can be returned directly from route handlers because the router
 * normalises objects to JSON responses.
 *
 * ```php
 * final class UserResource extends JsonResource
 * {
 *     public function toArray(): array
 *     {
 *         return ['id' => $this->value('id'), 'email' => $this->value('email')];
 *     }
 * }
 *
 * return new UserResource($user);
 * ```
 */
abstract class JsonResource implements JsonSerializable
{
    public function __construct(protected readonly mixed $resource) {}

    /** Transform the wrapped resource into an array. */
    abstract public function toArray(): array;

    /** Return the transformed array for json_encode(). */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** Create a JSON response from the resource. */
    public function response(int $status = 200): Response
    {
        return Response::json($this->toArray(), $status);
    }

    /** Transform a list of resources with the current resource class. */
    public static function collection(iterable $items): array
    {
        $resources = [];
        foreach ($items as $item) {
            $resources[] = new static($item);
        }
        return $resources;
    }

    /** Read a value from an array, ArrayAccess object, or public object property. */
    protected function value(string $key, mixed $default = null): mixed
    {
        if (is_array($this->resource)) {
            return $this->resource[$key] ?? $default;
        }

        if ($this->resource instanceof \ArrayAccess && isset($this->resource[$key])) {
            return $this->resource[$key];
        }

        if (is_object($this->resource) && isset($this->resource->{$key})) {
            return $this->resource->{$key};
        }

        return $default;
    }
}
