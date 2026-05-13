<?php

declare(strict_types=1);

namespace Lift\JsonRpc;

use Lift\Container\Container;
use Lift\Http\Request;
use Lift\Http\Response;
use Lift\JsonRpc\Attribute\RpcMethod;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;

/**
 * JSON-RPC 2.0 server that integrates with Lift as a route handler.
 *
 * Supports both single requests and batch requests as per the spec.
 *
 * ```php
 * $rpc = new JsonRpcServer($app->container());
 *
 * // Register callable
 * $rpc->register('math.add', fn(int $a, int $b): int => $a + $b);
 *
 * // Register a class and scan #[RpcMethod] attributes
 * $rpc->registerService(MathService::class);
 *
 * // Mount as a POST route
 * $app->post('/rpc', $rpc);
 * ```
 *
 * @see https://www.jsonrpc.org/specification
 */
final class JsonRpcServer
{
    /** @var array<string, callable> Registered method name → handler */
    private array $methods = [];

    /** @var bool Whether to expose exception messages in error responses. */
    private bool $debug = false;

    public function __construct(
        private readonly ?Container $container = null,
    ) {}

    /**
     * Enable or disable debug mode (exposes exception messages to clients).
     *
     * Disable in production to avoid leaking internal details.
     */
    public function setDebug(bool $debug): self
    {
        $this->debug = $debug;
        return $this;
    }

    // -----------------------------------------------------------------
    // Registration
    // -----------------------------------------------------------------

    /**
     * Register a callable under a JSON-RPC method name.
     *
     * Parameters are injected by name matching the JSON-RPC `params` object,
     * or positionally if `params` is an array.
     *
     * @param string   $name    Method name (e.g. "math.add", "users.list").
     * @param callable $handler Any callable; parameter types are used for coercion.
     */
    public function register(string $name, callable $handler): self
    {
        $this->methods[$name] = $handler;
        return $this;
    }

    /**
     * Scan a service class for {@see RpcMethod} attributes and register all
     * annotated public methods.
     *
     * The class is resolved from the DI container if one is configured.
     *
     * ```php
     * class MathService
     * {
     *     #[RpcMethod('math.add')]
     *     public function add(int $a, int $b): int { return $a + $b; }
     * }
     *
     * $rpc->registerService(MathService::class);
     * ```
     *
     * @param class-string $class
     * @throws \ReflectionException
     */
    public function registerService(string $class): self
    {
        $ref      = new ReflectionClass($class);
        $instance = $this->container ? $this->container->make($class) : new $class();

        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(RpcMethod::class) as $attr) {
                /** @var RpcMethod $rpcAttr */
                $rpcAttr = $attr->newInstance();
                $name    = $rpcAttr->name ?? $class . '.' . $method->getName();
                $this->methods[$name] = [$instance, $method->getName()];
            }
        }

        return $this;
    }

    /**
     * List all registered method names.
     *
     * @return list<string>
     */
    public function methods(): array
    {
        return array_keys($this->methods);
    }

    // -----------------------------------------------------------------
    // Invokable — use as a Lift route handler
    // -----------------------------------------------------------------

    /**
     * Handle an HTTP request and return a JSON-RPC response.
     *
     * Can be used directly as a Lift route handler:
     * ```php
     * $app->post('/rpc', $rpcServer);
     * ```
     */
    public function __invoke(Request $req): Response
    {
        $raw  = (string) $req->getBody();
        $data = json_decode($raw, true);

        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            return $this->errorResponse(
                null,
                JsonRpcError::make(JsonRpcError::PARSE_ERROR),
            );
        }

        // Batch request
        if (is_array($data) && array_is_list($data)) {
            if (empty($data)) {
                return $this->errorResponse(null, JsonRpcError::make(JsonRpcError::INVALID_REQUEST));
            }
            $responses = array_filter(
                array_map(fn(mixed $item) => $this->handleSingle($item), $data),
                fn($r) => $r !== null, // omit null (notifications)
            );
            return Response::json(array_values($responses));
        }

        // Single request
        $result = $this->handleSingle($data);
        if ($result === null) {
            // Notification — no response body
            return Response::noContent();
        }

        return Response::json($result);
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Handle a single decoded request and return a response array (or null for notifications).
     *
     * @param  mixed $data Decoded JSON value.
     * @return array<string, mixed>|null
     */
    private function handleSingle(mixed $data): ?array
    {
        try {
            $rpcReq = JsonRpcRequest::fromArray($data);
        } catch (\InvalidArgumentException $e) {
            $id   = is_array($data) ? ($data['id'] ?? null) : null;
            return $this->errorArray($id, JsonRpcError::make($e->getCode() ?: JsonRpcError::INVALID_REQUEST, $e->getMessage()));
        }

        if (!isset($this->methods[$rpcReq->method])) {
            if ($rpcReq->isNotification()) {
                return null;
            }
            return $this->errorArray($rpcReq->id, JsonRpcError::make(JsonRpcError::METHOD_NOT_FOUND));
        }

        try {
            $result = $this->invoke($this->methods[$rpcReq->method], $rpcReq);
        } catch (\Throwable $e) {
            if ($rpcReq->isNotification()) {
                return null;
            }
            return $this->errorArray($rpcReq->id, JsonRpcError::fromException($e, $this->debug));
        }

        if ($rpcReq->isNotification()) {
            return null;
        }

        return ['jsonrpc' => '2.0', 'result' => $result, 'id' => $rpcReq->id];
    }

    /**
     * Invoke a registered handler with parameters from the RPC request.
     *
     * Named params are matched to PHP parameter names via reflection.
     * Positional params are passed in order.
     */
    private function invoke(callable $handler, JsonRpcRequest $rpcReq): mixed
    {
        $named = $rpcReq->namedParams();

        if (is_array($handler)) {
            [$obj, $method] = $handler;
            $ref  = new ReflectionMethod($obj, $method);
            $args = $this->resolveArgs($ref->getParameters(), $named);
            return $obj->{$method}(...$args);
        }

        $ref  = new ReflectionFunction(\Closure::fromCallable($handler));
        $args = $this->resolveArgs($ref->getParameters(), $named);
        return $handler(...$args);
    }

    /**
     * Resolve PHP parameter list from the named params map.
     *
     * @param \ReflectionParameter[] $params
     * @param array<string|int, mixed> $provided
     * @return list<mixed>
     * @throws \ReflectionException
     */
    private function resolveArgs(array $params, array $provided): array
    {
        $args = [];
        foreach ($params as $i => $param) {
            $name = $param->getName();
            if (array_key_exists($name, $provided)) {
                $args[] = $this->coerce($provided[$name], $param);
            } elseif (array_key_exists($i, $provided)) {
                $args[] = $this->coerce($provided[$i], $param);
            } elseif ($param->isOptional()) {
                $args[] = $param->getDefaultValue();
            } else {
                throw new \InvalidArgumentException(
                    "Missing required parameter: \${$name}",
                    JsonRpcError::INVALID_PARAMS,
                );
            }
        }
        return $args;
    }

    /**
     * Coerce a raw JSON value to the expected PHP type.
     */
    private function coerce(mixed $value, \ReflectionParameter $param): mixed
    {
        $type = $param->getType();
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin() === false) {
            return $value;
        }

        return match ($type->getName()) {
            'int'    => (int) $value,
            'float'  => (float) $value,
            'string' => (string) $value,
            'bool'   => (bool) $value,
            'array'  => (array) $value,
            default  => $value,
        };
    }

    /**
     * @param  int|string|null         $id
     * @param  array{code: int, message: string} $error
     * @return array<string, mixed>
     */
    private function errorArray(int|string|null $id, array $error): array
    {
        return ['jsonrpc' => '2.0', 'error' => $error, 'id' => $id];
    }

    private function errorResponse(int|string|null $id, array $error): Response
    {
        return Response::json($this->errorArray($id, $error));
    }
}
