<?php

declare(strict_types=1);

namespace Lift\Tests;

use Lift\App;
use Lift\Debug\DebugCollector;
use Lift\Exception\NotFoundException;
use Lift\Http\Request;
use Lift\Http\Response;
use Lift\Http\Stream;
use Lift\Http\Uri;
use PHPUnit\Framework\TestCase;

class DebugTest extends TestCase
{
    private function request(string $method, string $uri, array $headers = []): Request
    {
        return new Request(
            method: $method,
            uri: new Uri($uri),
            headers: $headers,
            body: Stream::empty(),
        );
    }

    public function testToolbarInjectsIntoHtml(): void
    {
        $app = new App();
        $app->debug(['enabled' => true]);
        $app->get('/page', fn() => Response::html('<html><body>Hello</body></html>'));

        $response = $app->handle($this->request('GET', 'http://localhost/page'));

        self::assertStringContainsString('Lift Debug', (string) $response->getBody());
        $app->debugErrorHandler()->restorePhpHandlers();
    }

    public function testToolbarDoesNotInjectIntoJson(): void
    {
        $app = new App();
        $app->debug(['enabled' => true]);
        $app->get('/api', fn() => Response::json(['ok' => true]));

        $response = $app->handle($this->request('GET', 'http://localhost/api'));

        self::assertStringNotContainsString('Lift Debug', (string) $response->getBody());
        $app->debugErrorHandler()->restorePhpHandlers();
    }

    public function testDisabledDebugLeavesHtmlUntouched(): void
    {
        $app = new App();
        $app->debug(false);
        $app->get('/page', fn() => Response::html('<h1>Hello</h1>'));

        $response = $app->handle($this->request('GET', 'http://localhost/page'));

        self::assertSame('<h1>Hello</h1>', (string) $response->getBody());
    }

    public function testExceptionOverrideWorks(): void
    {
        $app = new App();
        $app->debug(['enabled' => true]);
        $app->onException(NotFoundException::class, fn() => Response::text('missing', 404));

        $response = $app->handle($this->request('GET', 'http://localhost/missing'));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('missing', (string) $response->getBody());
        $app->debugErrorHandler()->restorePhpHandlers();
    }

    public function testConfigurationFromArray(): void
    {
        $app = new App();
        $app->config(['debug' => ['enabled' => true], 'app' => ['name' => 'Lift']]);

        self::assertTrue($app->configuration()->get('debug.enabled'));
        self::assertSame('Lift', $app->configuration()->get('app.name'));
    }

    public function testSensitiveValuesAreMasked(): void
    {
        $app = new App();
        $app->debug(['enabled' => true]);
        $app->get('/page', fn() => Response::html('<body>Hello</body>'));

        $app->handle($this->request('GET', 'http://localhost/page?token=secret', ['Authorization' => 'Bearer abc']));
        $data = $app->container()->make(DebugCollector::class)->data();

        self::assertSame(['***'], $data['request']['headers']['Authorization']);
        $app->debugErrorHandler()->restorePhpHandlers();
    }
}
