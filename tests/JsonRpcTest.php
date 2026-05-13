<?php

declare(strict_types=1);

namespace Lift\Tests;

use Lift\Http\Request;
use Lift\Http\Stream;
use Lift\Http\Uri;
use Lift\JsonRpc\Attribute\RpcMethod;
use Lift\JsonRpc\JsonRpcError;
use Lift\JsonRpc\JsonRpcServer;
use PHPUnit\Framework\TestCase;

class JsonRpcTest extends TestCase
{
    private JsonRpcServer $server;

    protected function setUp(): void
    {
        $this->server = new JsonRpcServer();
        $this->server->register('math.add', fn(int $a, int $b): int => $a + $b);
        $this->server->register('echo',     fn(string $msg): string => $msg);
        $this->server->register('null',     fn(): mixed => null);
    }

    private function rpcRequest(mixed $payload): Request
    {
        $body = Stream::fromString(json_encode($payload));
        return new Request('POST', new Uri('http://localhost/rpc'), body: $body);
    }

    private function decode(Request $req): array
    {
        $res  = ($this->server)($req);
        $json = json_decode((string) $res->getBody(), true);
        self::assertIsArray($json);
        return $json;
    }

    public function testSuccessfulCall(): void
    {
        $data = $this->decode($this->rpcRequest(['jsonrpc' => '2.0', 'method' => 'math.add', 'params' => ['a' => 3, 'b' => 4], 'id' => 1]));
        self::assertSame('2.0', $data['jsonrpc']);
        self::assertSame(7, $data['result']);
        self::assertSame(1, $data['id']);
    }

    public function testPositionalParams(): void
    {
        $data = $this->decode($this->rpcRequest(['jsonrpc' => '2.0', 'method' => 'math.add', 'params' => [10, 5], 'id' => 2]));
        self::assertSame(15, $data['result']);
    }

    public function testMethodNotFound(): void
    {
        $data = $this->decode($this->rpcRequest(['jsonrpc' => '2.0', 'method' => 'nope', 'id' => 3]));
        self::assertSame(JsonRpcError::METHOD_NOT_FOUND, $data['error']['code']);
    }

    public function testParseError(): void
    {
        $body = Stream::fromString('{invalid json');
        $req  = new Request('POST', new Uri('http://localhost/rpc'), body: $body);
        $res  = ($this->server)($req);
        $data = json_decode((string) $res->getBody(), true);
        self::assertSame(JsonRpcError::PARSE_ERROR, $data['error']['code']);
    }

    public function testInvalidRequest(): void
    {
        $data = $this->decode($this->rpcRequest(['jsonrpc' => '1.0', 'method' => 'echo', 'id' => 4]));
        self::assertSame(JsonRpcError::INVALID_REQUEST, $data['error']['code']);
    }

    public function testNotificationReturnsNoContent(): void
    {
        $req = $this->rpcRequest(['jsonrpc' => '2.0', 'method' => 'echo', 'params' => ['msg' => 'hi']]);
        $res = ($this->server)($req);
        self::assertSame(204, $res->getStatusCode());
    }

    public function testBatchRequest(): void
    {
        $req = $this->rpcRequest([
            ['jsonrpc' => '2.0', 'method' => 'math.add', 'params' => ['a' => 1, 'b' => 2], 'id' => 1],
            ['jsonrpc' => '2.0', 'method' => 'echo', 'params' => ['msg' => 'hello'], 'id' => 2],
        ]);
        $res  = ($this->server)($req);
        $data = json_decode((string) $res->getBody(), true);
        self::assertCount(2, $data);
        self::assertSame(3, $data[0]['result']);
        self::assertSame('hello', $data[1]['result']);
    }

    public function testNullResultIncluded(): void
    {
        // null is a valid result in JSON-RPC, must not be omitted
        $data = $this->decode($this->rpcRequest(['jsonrpc' => '2.0', 'method' => 'null', 'id' => 5]));
        self::assertArrayHasKey('result', $data);
        self::assertNull($data['result']);
    }

    public function testRpcMethodAttribute(): void
    {
        $server = new JsonRpcServer();
        $server->registerService(RpcMathService::class);

        self::assertContains('calc.multiply', $server->methods());

        $req  = $this->rpcRequest(['jsonrpc' => '2.0', 'method' => 'calc.multiply', 'params' => ['a' => 3, 'b' => 4], 'id' => 1]);
        $res  = ($server)($req);
        $data = json_decode((string) $res->getBody(), true);
        self::assertSame(12, $data['result']);
    }

    public function testEmptyBatchReturnsError(): void
    {
        $data = $this->decode($this->rpcRequest([]));
        self::assertSame(JsonRpcError::INVALID_REQUEST, $data['error']['code']);
    }
}

// ---- Fixtures --------------------------------------------------------

class RpcMathService
{
    #[RpcMethod('calc.multiply')]
    public function multiply(int $a, int $b): int
    {
        return $a * $b;
    }
}
