<?php

declare(strict_types=1);

namespace Lift\Tests;

use Lift\App;
use Lift\Debug\DebugCollector;
use Lift\Debug\DebugConfig;
use Lift\Debug\DebugLogHandler;
use Lift\Exception\NotFoundException;
use Lift\Http\Request;
use Lift\Http\Response;
use Lift\Http\Stream;
use Lift\Http\Uri;
use Lift\Log\Logger;
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

        self::assertStringContainsString('ldbg-bar', (string) $response->getBody());
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

    // -----------------------------------------------------------------
    // DebugCollector — query and log recording
    // -----------------------------------------------------------------

    public function testCollectorRecordsQueries(): void
    {
        $collector = new DebugCollector(new DebugConfig([]));
        $req = $this->request('GET', 'http://localhost/');
        $collector->start($req);

        $collector->recordQuery('SELECT * FROM users WHERE id = ?', [1], 4.5);
        $collector->recordQuery('INSERT INTO logs (msg) VALUES (?)', ['hello'], 1.2);

        $data = $collector->data();
        self::assertCount(2, $data['queries']);
        self::assertSame('SELECT * FROM users WHERE id = ?', $data['queries'][0]['sql']);
        self::assertSame([1], $data['queries'][0]['bindings']);
        self::assertSame(4.5, $data['queries'][0]['time_ms']);
        self::assertSame('INSERT INTO logs (msg) VALUES (?)', $data['queries'][1]['sql']);
    }

    public function testCollectorRecordsLogs(): void
    {
        $collector = new DebugCollector(new DebugConfig([]));
        $collector->start($this->request('GET', 'http://localhost/'));

        $collector->recordLog('info', 'User logged in', ['user_id' => 42]);
        $collector->recordLog('error', 'Payment failed', ['order_id' => 7]);

        $data = $collector->data();
        self::assertCount(2, $data['logs']);
        self::assertSame('info', $data['logs'][0]['level']);
        self::assertSame('User logged in', $data['logs'][0]['message']);
        self::assertSame(['user_id' => 42], $data['logs'][0]['context']);
        self::assertSame('error', $data['logs'][1]['level']);
    }

    public function testCollectorResetsQueriesAndLogsOnStart(): void
    {
        $collector = new DebugCollector(new DebugConfig([]));
        $collector->start($this->request('GET', 'http://localhost/'));
        $collector->recordQuery('SELECT 1', [], 0.1);
        $collector->recordLog('debug', 'msg', []);

        // Second request resets state
        $collector->start($this->request('GET', 'http://localhost/'));

        $data = $collector->data();
        self::assertCount(0, $data['queries']);
        self::assertCount(0, $data['logs']);
    }

    public function testDebugLogHandlerForwardsToCollector(): void
    {
        $collector = new DebugCollector(new DebugConfig([]));
        $collector->start($this->request('GET', 'http://localhost/'));

        $handler = new DebugLogHandler($collector);
        $logger  = new Logger([$handler]);

        $logger->info('test message', ['key' => 'value']);
        $logger->debug('debug msg', []);

        $data = $collector->data();
        self::assertCount(2, $data['logs']);
        self::assertSame('info', $data['logs'][0]['level']);
        self::assertSame('test message', $data['logs'][0]['message']);
        self::assertSame(['key' => 'value'], $data['logs'][0]['context']);
        self::assertSame('debug', $data['logs'][1]['level']);
    }

    public function testDebugLogHandlerRespectsMinLevel(): void
    {
        $collector = new DebugCollector(new DebugConfig([]));
        $collector->start($this->request('GET', 'http://localhost/'));

        $handler = new DebugLogHandler($collector, \Psr\Log\LogLevel::WARNING);
        $logger  = new Logger([$handler]);

        $logger->debug('ignored');
        $logger->info('also ignored');
        $logger->warning('recorded');
        $logger->error('also recorded');

        $data = $collector->data();
        self::assertCount(2, $data['logs']);
        self::assertSame('warning', $data['logs'][0]['level']);
        self::assertSame('error', $data['logs'][1]['level']);
    }

    public function testConnectionQueryListenerIntegration(): void
    {
        $db = new \Lift\Database\Connection('sqlite::memory:');
        $db->execute('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');

        $collector = new DebugCollector(new DebugConfig([]));
        $collector->start($this->request('GET', 'http://localhost/'));

        $db->onQuery([$collector, 'recordQuery']);

        $db->execute('INSERT INTO t (v) VALUES (?)', ['hello']);
        $db->select('SELECT * FROM t');

        $data = $collector->data();
        self::assertCount(2, $data['queries']);
        self::assertStringContainsString('INSERT INTO t', $data['queries'][0]['sql']);
        self::assertStringContainsString('SELECT * FROM t', $data['queries'][1]['sql']);
        self::assertGreaterThanOrEqual(0.0, $data['queries'][0]['time_ms']);
    }
}
