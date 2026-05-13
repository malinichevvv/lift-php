<?php

declare(strict_types=1);

namespace Lift\JsonRpc\Attribute;

use Attribute;

/**
 * Marks a public method as a JSON-RPC 2.0 callable procedure.
 *
 * When a class is registered with {@see \Lift\JsonRpc\JsonRpcServer::registerService()},
 * the server scans all methods annotated with this attribute and exposes them
 * under the given name (or `ClassName.methodName` if omitted).
 *
 * ```php
 * class MathService
 * {
 *     #[RpcMethod('math.add')]
 *     public function add(int $a, int $b): int
 *     {
 *         return $a + $b;
 *     }
 *
 *     #[RpcMethod('math.divide')]
 *     public function divide(float $a, float $b): float
 *     {
 *         if ($b === 0.0) throw new \InvalidArgumentException('Division by zero');
 *         return $a / $b;
 *     }
 * }
 * ```
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class RpcMethod
{
    /**
     * @param string|null $name  Public method name in the JSON-RPC namespace,
     *                           e.g. "math.add". Defaults to "{Class}.{method}".
     * @param string      $description  Human-readable description for introspection.
     */
    public function __construct(
        public readonly ?string $name = null,
        public readonly string $description = '',
    ) {}
}
