<?php

declare(strict_types=1);

namespace Lift\Tests;

use Lift\App;
use Lift\Console\Commands\RouteCacheCommand;
use Lift\Console\Input;
use Lift\Console\Output;
use Lift\Exception\PayloadTooLargeException;
use Lift\Http\Request;
use Lift\Http\Response;
use Lift\Http\Session\Session;
use Lift\Http\Uri;
use Lift\Jwt\Jwt;
use Lift\Jwt\JwtException;
use Lift\Routing\Route;
use Lift\Validation\Validator;
use PHPUnit\Framework\TestCase;

final class NextVersionFeaturesTest extends TestCase
{
    public function testRequestFilterPipelineCanValidateTransformedInput(): void
    {
        $request = new Request('POST', new Uri('/'), parsedBody: ['email' => ' USER@EXAMPLE.COM ', 'price' => '12.50']);

        $data = $request->filter([
            'email' => 'trim|lowercase',
            'price' => 'numeric_string_to_float',
        ])->validate([
            'email' => 'required|email',
            'price' => 'required|numeric|min:1',
        ]);

        self::assertSame('user@example.com', $data['email']);
        self::assertSame(12.5, $data['price']);
    }

    public function testValidatorSupportsNestedWildcardPathsAndNewRules(): void
    {
        $validator = new Validator([
            'items' => [
                ['slug' => 'first-item', 'port' => 443],
                ['slug' => 'second-item', 'port' => 8080],
            ],
            'timezone' => 'Europe/Kyiv',
            'color' => '#aabbcc',
            'country' => 'UA',
            'currency' => 'USD',
            'lat' => '50.45',
            'lng' => '30.52',
            'password' => 'S3cret!Pass',
        ], [
            'items.*.slug' => 'required|slug',
            'items.*.port' => 'required|port',
            'timezone' => 'timezone',
            'color' => 'hex_color',
            'country' => 'country_code',
            'currency' => 'currency_code',
            'lat' => 'latitude',
            'lng' => 'longitude',
            'password' => 'strong_password',
        ]);

        self::assertTrue($validator->passes());
    }

    public function testJwtRejectsMismatchedAlgorithmHeader(): void
    {
        $jwt = new Jwt(secret: 'secret-for-hs256-tests');
        $token = $jwt->encode(['sub' => 'u1']);
        $parts = explode('.', $token);
        $parts[0] = rtrim(strtr(base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS512'])), '+/', '-_'), '=');
        $tampered = implode('.', $parts);

        $this->expectException(JwtException::class);
        $jwt->decode($tampered);
    }

    public function testSessionCookieOptionsAreConfigurable(): void
    {
        $session = new Session(
            id: 'abc',
            cookieName: 'sid',
            cookiePath: '/app',
            cookieDomain: 'example.com',
            sameSite: 'None',
            secure: true,
            partitioned: true,
        );

        $cookie = $session->toCookieHeader(false);
        self::assertStringContainsString('sid=abc', $cookie);
        self::assertStringContainsString('Path=/app', $cookie);
        self::assertStringContainsString('Domain=example.com', $cookie);
        self::assertStringContainsString('SameSite=None', $cookie);
        self::assertStringContainsString('Secure', $cookie);
        self::assertStringContainsString('Partitioned', $cookie);
    }

    public function testLifecycleHooksAndBootstrapStepsRun(): void
    {
        $events = [];
        $app = new App();
        $app->bootstrap([
            static function (App $app): void {
                $app->get('/ping', static fn() => Response::text('pong'));
            },
        ]);
        $app->on('request.received', static function () use (&$events): void { $events[] = 'request'; });
        $app->on('route.matched', static function () use (&$events): void { $events[] = 'route'; });
        $app->on('response.sending', static function () use (&$events): void { $events[] = 'response'; });

        $response = $app->handle(new Request('GET', new Uri('/ping')));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['request', 'route', 'response'], $events);
    }

    public function testRouteCompileFailsForInvalidRegex(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Route(['GET'], '/x/{id:[}', static fn() => Response::text('bad')))->compile();
    }

    public function testRouteCacheCommandWritesCacheFile(): void
    {
        $dir = sys_get_temp_dir() . '/lift_route_cache_' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        $bootstrap = $dir . '/app.php';
        $cache = $dir . '/routes.cache.php';
        file_put_contents($bootstrap, <<<'PHPBOOT'
<?php
require __DIR__ . '/autoload.php';
$app = new \Lift\App();
$app->get('/cached', [\Lift\Tests\NextVersionRouteHandler::class, 'handle']);
return $app;
PHPBOOT);
        file_put_contents($dir . '/autoload.php', "<?php require '" . addslashes(dirname(__DIR__) . '/vendor/autoload.php') . "';\n");

        $cmd = new RouteCacheCommand();
        $code = $cmd->execute(new Input(['route:cache', '--bootstrap=' . $bootstrap, '--path=' . $cache]), new Output(fopen('php://memory', 'w')));

        self::assertSame(0, $code);
        self::assertFileExists($cache);

        unlink($cache);
        unlink($bootstrap);
        unlink($dir . '/autoload.php');
        rmdir($dir);
    }
}

final class NextVersionRouteHandler
{
    public function handle(): Response
    {
        return Response::text('ok');
    }
}
