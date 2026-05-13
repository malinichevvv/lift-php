<?php

declare(strict_types=1);

namespace Lift\JsonRpc;

/**
 * Represents a decoded JSON-RPC 2.0 request or notification.
 *
 * A notification is a request without an `id` field; the server must not
 * send a response for it.
 *
 * @see https://www.jsonrpc.org/specification#request_object
 */
final class JsonRpcRequest
{
    /**
     * @param string              $method Method name as declared on the server.
     * @param array|object|null   $params Positional (array) or named (object) params.
     * @param int|string|null     $id     Request identifier; null for notifications.
     */
    public function __construct(
        public readonly string $method,
        public readonly array|object|null $params,
        public readonly int|string|null $id,
    ) {}

    /**
     * Parse a raw decoded JSON value into a {@see JsonRpcRequest}.
     *
     * @param  mixed $data Decoded JSON (array from {@see json_decode(..., true)}).
     * @throws \InvalidArgumentException If the request is structurally invalid.
     */
    public static function fromArray(mixed $data): self
    {
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Request must be a JSON object', JsonRpcError::INVALID_REQUEST);
        }

        if (($data['jsonrpc'] ?? '') !== '2.0') {
            throw new \InvalidArgumentException(
                'Missing or invalid "jsonrpc" field, must be "2.0"',
                JsonRpcError::INVALID_REQUEST,
            );
        }

        if (!isset($data['method']) || !is_string($data['method']) || $data['method'] === '') {
            throw new \InvalidArgumentException(
                '"method" must be a non-empty string',
                JsonRpcError::INVALID_REQUEST,
            );
        }

        $params = $data['params'] ?? null;
        $id     = $data['id'] ?? null;

        if ($id !== null && !is_int($id) && !is_string($id)) {
            throw new \InvalidArgumentException('"id" must be a string, integer, or null', JsonRpcError::INVALID_REQUEST);
        }

        return new self(
            method: $data['method'],
            params: $params,
            id: $id,
        );
    }

    /**
     * Returns true if this is a notification (no `id` field).
     * The server must not return a response for notifications.
     */
    public function isNotification(): bool
    {
        return $this->id === null;
    }

    /**
     * Return params as a flat positional array.
     *
     * Named params (object) are returned as a sequential array of values in
     * definition order; positional params are returned as-is.
     *
     * @return list<mixed>
     */
    public function positionalParams(): array
    {
        if ($this->params === null) {
            return [];
        }
        if (is_object($this->params)) {
            return array_values((array) $this->params);
        }
        return array_values($this->params);
    }

    /**
     * Return params as a named key-value map.
     *
     * Positional arrays are returned as-is (integer-keyed); named objects are
     * cast to arrays.
     *
     * @return array<string|int, mixed>
     */
    public function namedParams(): array
    {
        if ($this->params === null) {
            return [];
        }
        return (array) $this->params;
    }
}
