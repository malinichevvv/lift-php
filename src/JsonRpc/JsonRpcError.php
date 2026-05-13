<?php

declare(strict_types=1);

namespace Lift\JsonRpc;

/**
 * Standard JSON-RPC 2.0 error codes as defined in the specification.
 *
 * Pre-defined error codes occupy the range -32768 to -32000.
 * Application-specific error codes must be outside this range.
 *
 * @see https://www.jsonrpc.org/specification#error_object
 */
final class JsonRpcError
{
    /** Invalid JSON received by the server. */
    public const PARSE_ERROR      = -32700;

    /** The JSON sent is not a valid Request object. */
    public const INVALID_REQUEST  = -32600;

    /** The method does not exist or is not available. */
    public const METHOD_NOT_FOUND = -32601;

    /** Invalid method parameter(s). */
    public const INVALID_PARAMS   = -32602;

    /** Internal JSON-RPC error. */
    public const INTERNAL_ERROR   = -32603;

    /** Server-defined application error range start. */
    public const SERVER_ERROR_MIN = -32099;

    /** Server-defined application error range end. */
    public const SERVER_ERROR_MAX = -32000;

    private static array $messages = [
        self::PARSE_ERROR      => 'Parse error',
        self::INVALID_REQUEST  => 'Invalid Request',
        self::METHOD_NOT_FOUND => 'Method not found',
        self::INVALID_PARAMS   => 'Invalid params',
        self::INTERNAL_ERROR   => 'Internal error',
    ];

    /**
     * Build an error object array compatible with the JSON-RPC 2.0 spec.
     *
     * @param  int        $code    Error code.
     * @param  string     $message Human-readable message (uses the standard message if empty).
     * @param  mixed|null $data    Optional additional error data.
     * @return array{code: int, message: string, data?: mixed}
     */
    public static function make(int $code, string $message = '', mixed $data = null): array
    {
        $msg  = $message !== '' ? $message : (self::$messages[$code] ?? 'Server error');
        $obj  = ['code' => $code, 'message' => $msg];
        if ($data !== null) {
            $obj['data'] = $data;
        }
        return $obj;
    }

    /**
     * Convert a {@see \Throwable} to a JSON-RPC error object.
     *
     * Uses {@see INTERNAL_ERROR} unless the exception code maps to a known
     * JSON-RPC error code.
     *
     * @param  bool $exposeMessage Whether to include the exception message (disable in production).
     * @return array{code: int, message: string}
     */
    public static function fromException(\Throwable $e, bool $exposeMessage = false): array
    {
        $code = isset(self::$messages[$e->getCode()]) ? $e->getCode() : self::INTERNAL_ERROR;
        $msg  = $exposeMessage ? $e->getMessage() : (self::$messages[$code] ?? 'Internal error');
        return self::make($code, $msg);
    }
}
