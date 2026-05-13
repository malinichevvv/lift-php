<?php

declare(strict_types=1);

namespace Lift\Tests;

use Lift\Http\SseEmitter;
use Lift\Http\SseEvent;
use Lift\Http\SseResponse;
use PHPUnit\Framework\TestCase;

class SseTest extends TestCase
{
    // -----------------------------------------------------------------
    // SseEvent encoding
    // -----------------------------------------------------------------

    public function testBasicDataField(): void
    {
        $frame = SseEvent::create('Hello')->encode();
        self::assertSame("data: Hello\n\n", $frame);
    }

    public function testEventField(): void
    {
        $frame = SseEvent::create('payload')->event('update')->encode();
        self::assertStringContainsString("event: update\n", $frame);
        self::assertStringContainsString("data: payload\n", $frame);
    }

    public function testIdField(): void
    {
        $frame = SseEvent::create('x')->id('42')->encode();
        self::assertStringContainsString("id: 42\n", $frame);
    }

    public function testRetryField(): void
    {
        $frame = SseEvent::create('x')->retry(3000)->encode();
        self::assertStringContainsString("retry: 3000\n", $frame);
    }

    public function testMultilineData(): void
    {
        $frame = SseEvent::create("line1\nline2")->encode();
        self::assertStringContainsString("data: line1\n", $frame);
        self::assertStringContainsString("data: line2\n", $frame);
    }

    public function testJsonHelper(): void
    {
        $frame = SseEvent::json(['count' => 1], 'tick')->encode();
        self::assertStringContainsString('event: tick', $frame);
        self::assertStringContainsString('data: {"count":1}', $frame);
    }

    public function testJsonHelperWithoutEvent(): void
    {
        $frame = SseEvent::json(['ok' => true])->encode();
        self::assertStringNotContainsString('event:', $frame);
        self::assertStringContainsString('data: {"ok":true}', $frame);
    }

    public function testFrameEndsWithDoubleNewline(): void
    {
        $frame = SseEvent::create('test')->encode();
        self::assertStringEndsWith("\n\n", $frame);
    }

    public function testImmutability(): void
    {
        $base    = SseEvent::create('x');
        $withEvt = $base->event('foo');
        $withId  = $base->id('1');

        // Base should not have event or id
        self::assertStringNotContainsString('event:', $base->encode());
        self::assertStringNotContainsString('id:', $base->encode());

        // Derived variants are independent
        self::assertStringContainsString('event: foo', $withEvt->encode());
        self::assertStringContainsString('id: 1',      $withId->encode());
    }

    // -----------------------------------------------------------------
    // SseEmitter
    // -----------------------------------------------------------------

    public function testSseEmitterCollectsFrames(): void
    {
        $emitter = new SseEmitter();

        ob_start();
        $emitter(SseEvent::create('first'));
        $emitter(SseEvent::create('second'));
        ob_get_clean();

        $sent = $emitter->getSent();
        self::assertCount(2, $sent);
        self::assertStringContainsString('first',  $sent[0]);
        self::assertStringContainsString('second', $sent[1]);
    }

    // -----------------------------------------------------------------
    // SseResponse
    // -----------------------------------------------------------------

    public function testSseResponseHeaders(): void
    {
        $response = new SseResponse(function (SseEmitter $emit) {});
        self::assertSame('text/event-stream', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-cache',          $response->getHeaderLine('Cache-Control'));
    }

    public function testSseResponseStatusCode(): void
    {
        $response = new SseResponse(function (SseEmitter $emit) {});
        self::assertSame(200, $response->getStatusCode());
    }

    public function testSseResponseGeneratorIsCalled(): void
    {
        $called = false;
        $response = new SseResponse(function (SseEmitter $emit) use (&$called) {
            $called = true;
        });

        ob_start();
        $response->stream();
        ob_get_clean();

        self::assertTrue($called);
    }
}
