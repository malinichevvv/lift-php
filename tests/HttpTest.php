<?php

declare(strict_types=1);

namespace Lift\Tests;

use Lift\Http\Request;
use Lift\Http\Response;
use Lift\Http\Stream;
use Lift\Http\Uri;
use PHPUnit\Framework\TestCase;

class HttpTest extends TestCase
{
    // -----------------------------------------------------------------
    // Stream
    // -----------------------------------------------------------------

    public function testStreamFromString(): void
    {
        $stream = Stream::fromString('hello');
        self::assertSame('hello', (string) $stream);
        self::assertSame(5, $stream->getSize());
    }

    public function testStreamReadWrite(): void
    {
        $stream = Stream::empty();
        $stream->write('test');
        $stream->rewind();
        self::assertSame('test', $stream->read(4));
    }

    // -----------------------------------------------------------------
    // Uri
    // -----------------------------------------------------------------

    public function testUriParsing(): void
    {
        $uri = new Uri('https://example.com/path?q=1#frag');
        self::assertSame('https', $uri->getScheme());
        self::assertSame('example.com', $uri->getHost());
        self::assertSame('/path', $uri->getPath());
        self::assertSame('q=1', $uri->getQuery());
        self::assertSame('frag', $uri->getFragment());
    }

    public function testUriStandardPortOmitted(): void
    {
        $uri = new Uri('http://example.com:80/');
        self::assertNull($uri->getPort());
    }

    public function testUriNonStandardPortKept(): void
    {
        $uri = new Uri('http://example.com:8080/');
        self::assertSame(8080, $uri->getPort());
    }

    public function testUriImmutableWith(): void
    {
        $uri  = new Uri('https://example.com/');
        $uri2 = $uri->withPath('/new');
        self::assertSame('/new', $uri2->getPath());
        self::assertSame('/', $uri->getPath());
    }

    // -----------------------------------------------------------------
    // Response
    // -----------------------------------------------------------------

    public function testJsonResponse(): void
    {
        $res = Response::json(['key' => 'value']);
        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('application/json', $res->getHeaderLine('Content-Type'));
        self::assertStringContainsString('"key":"value"', (string) $res->getBody());
    }

    public function testRedirectResponse(): void
    {
        $res = Response::redirect('/new-path', 301);
        self::assertSame(301, $res->getStatusCode());
        self::assertSame('/new-path', $res->getHeaderLine('Location'));
    }

    public function testResponseImmutability(): void
    {
        $r1 = new Response(200);
        $r2 = $r1->withStatus(404);
        self::assertSame(200, $r1->getStatusCode());
        self::assertSame(404, $r2->getStatusCode());
    }

    public function testResponseWithHeader(): void
    {
        $res = (new Response())->withHeader('X-Foo', 'bar');
        self::assertSame('bar', $res->getHeaderLine('X-Foo'));
        self::assertSame('bar', $res->getHeaderLine('x-foo'));
    }

    // -----------------------------------------------------------------
    // Request
    // -----------------------------------------------------------------

    public function testRequestMethod(): void
    {
        $req = new Request('get', new Uri('http://localhost/'));
        self::assertSame('GET', $req->getMethod());
    }

    public function testRequestParam(): void
    {
        $req = (new Request('GET', new Uri('http://localhost/users/5')))
            ->withRouteParams(['id' => '5']);
        self::assertSame('5', $req->param('id'));
        self::assertNull($req->param('missing'));
    }

    public function testRequestQuery(): void
    {
        $req = new Request('GET', new Uri('http://localhost/?page=3'), queryParams: ['page' => '3']);
        self::assertSame('3', $req->query('page'));
    }

    public function testRequestIsMethod(): void
    {
        $req = new Request('POST', new Uri('http://localhost/'));
        self::assertTrue($req->isMethod('POST'));
        self::assertFalse($req->isMethod('GET'));
    }

    public function testRequestJsonDetection(): void
    {
        $req = new Request('POST', new Uri('http://localhost/'), headers: ['Content-Type' => 'application/json']);
        self::assertTrue($req->isJson());
    }

    public function testRequestImmutability(): void
    {
        $r1 = new Request('GET', new Uri('http://localhost/'));
        $r2 = $r1->withMethod('POST');
        self::assertSame('GET', $r1->getMethod());
        self::assertSame('POST', $r2->getMethod());
    }
}
