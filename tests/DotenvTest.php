<?php

declare(strict_types=1);

namespace Lift\Tests;

use Lift\App;
use Lift\Config\Dotenv;
use Lift\Config\Env;
use PHPUnit\Framework\TestCase;

final class DotenvTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach (['APP_ENV', 'APP_DEBUG', 'APP_PORT', 'APP_NAME', 'APP_EMPTY', 'EXPORTED_VALUE'] as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }
    }

    public function testDotenvLoadsValuesAndCastsThroughEnvHelper(): void
    {
        $file = sys_get_temp_dir() . '/lift_env_' . bin2hex(random_bytes(4));
        file_put_contents($file, "APP_ENV=testing\nAPP_DEBUG=true\nAPP_PORT=8080\nAPP_NAME=\"Lift App\"\nAPP_EMPTY=empty\nexport EXPORTED_VALUE='ok'\n");

        $loaded = Dotenv::load($file);

        self::assertSame('testing', $loaded['APP_ENV']);
        self::assertSame('testing', Env::string('APP_ENV'));
        self::assertTrue(Env::bool('APP_DEBUG'));
        self::assertSame(8080, Env::int('APP_PORT'));
        self::assertSame('Lift App', Env::string('APP_NAME'));
        self::assertSame('', Env::get('APP_EMPTY'));
        self::assertSame('ok', Env::string('EXPORTED_VALUE'));
    }

    public function testDotenvDoesNotOverwriteExistingValuesByDefault(): void
    {
        $_ENV['APP_ENV'] = 'production';
        $file = sys_get_temp_dir() . '/lift_env_' . bin2hex(random_bytes(4));
        file_put_contents($file, "APP_ENV=local\n");

        Dotenv::load($file);

        self::assertSame('production', Env::string('APP_ENV'));
    }

    public function testDotenvCanOverwriteExistingValues(): void
    {
        $_ENV['APP_ENV'] = 'production';
        $file = sys_get_temp_dir() . '/lift_env_' . bin2hex(random_bytes(4));
        file_put_contents($file, "APP_ENV=local\n");

        Dotenv::load($file, overwrite: true);

        self::assertSame('local', Env::string('APP_ENV'));
    }

    public function testAppLoadsEnvironment(): void
    {
        $file = sys_get_temp_dir() . '/lift_env_' . bin2hex(random_bytes(4));
        file_put_contents($file, "APP_ENV=staging\n");

        $app = new App();
        $app->loadEnv($file);

        self::assertSame('staging', $app->environment());
    }
}
