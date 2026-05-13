<?php

declare(strict_types=1);

namespace Lift\Tests;

use Lift\Console\Application;
use Lift\Console\Command;
use Lift\Console\Commands\KeyGenerateCommand;
use Lift\Console\Commands\MakeCommand;
use Lift\Console\Commands\MakeMigrationCommand;
use Lift\Console\Commands\MigrateFreshCommand;
use Lift\Console\Commands\MigrateResetCommand;
use Lift\Console\Commands\MigrateRollbackCommand;
use Lift\Console\Commands\MigrateStatusCommand;
use Lift\Console\Input;
use Lift\Console\Output;
use Lift\Database\Connection;
use PHPUnit\Framework\TestCase;

// --- Stub command for testing ---

final class HelloCommand extends Command
{
    public string $lastArg = '';

    public function getName(): string        { return 'hello'; }
    public function getDescription(): string { return 'Say hello'; }

    public function execute(Input $input, Output $output): int
    {
        $this->lastArg = $input->getArgument(0, 'World');
        $output->writeln("Hello, {$this->lastArg}!");
        return 0;
    }
}

final class FailingCommand extends Command
{
    public function getName(): string        { return 'fail'; }
    public function getDescription(): string { return 'Always fails'; }

    public function execute(Input $input, Output $output): int
    {
        return 1;
    }
}

class ConsoleTest extends TestCase
{
    // -----------------------------------------------------------------
    // Input parsing
    // -----------------------------------------------------------------

    public function testInputParsesCommand(): void
    {
        $input = new Input(['hello', 'Alice']);
        self::assertSame('hello', $input->getCommand());
        self::assertSame('Alice', $input->getArgument(0));
    }

    public function testInputParsesLongOption(): void
    {
        $input = new Input(['cmd', '--env=production']);
        self::assertSame('production', $input->getOption('env'));
    }

    public function testInputParsesLongFlag(): void
    {
        $input = new Input(['cmd', '--verbose']);
        self::assertTrue($input->getOption('verbose'));
    }

    public function testInputParsesShortFlag(): void
    {
        $input = new Input(['cmd', '-v']);
        self::assertTrue($input->getOption('v'));
    }

    public function testInputHasOption(): void
    {
        $input = new Input(['cmd', '--dry-run']);
        self::assertTrue($input->hasOption('dry-run'));
        self::assertFalse($input->hasOption('verbose'));
    }

    public function testInputDefaultOption(): void
    {
        $input = new Input(['cmd']);
        self::assertSame('3', $input->getOption('sleep', '3'));
        self::assertFalse($input->getOption('nonexistent'));
    }

    public function testInputMultipleArguments(): void
    {
        $input = new Input(['deploy', 'prod', 'v2']);
        self::assertSame('deploy', $input->getCommand());
        self::assertSame('prod',   $input->getArgument(0));
        self::assertSame('v2',     $input->getArgument(1));
        self::assertSame(['prod', 'v2'], $input->getArguments());
    }

    public function testEmptyInput(): void
    {
        $input = new Input([]);
        self::assertSame('', $input->getCommand());
        self::assertSame([], $input->getArguments());
    }

    // -----------------------------------------------------------------
    // Application dispatch
    // -----------------------------------------------------------------

    public function testApplicationRunsCommand(): void
    {
        $cmd = new HelloCommand();
        $cli = new Application('test', '1.0');
        $cli->register($cmd);

        $code = $cli->run(['hello', 'Alice']);
        self::assertSame(0, $code);
        self::assertSame('Alice', $cmd->lastArg);
    }

    public function testApplicationReturnsExitCode(): void
    {
        $cli = new Application('test', '1.0');
        $cli->register(new FailingCommand());

        self::assertSame(1, $cli->run(['fail']));
    }

    public function testApplicationUnknownCommandReturns1(): void
    {
        $cli  = new Application('test', '1.0');
        $code = $cli->run(['nonexistent']);
        self::assertSame(1, $code);
    }

    public function testApplicationListReturns0(): void
    {
        $cli = new Application('test', '1.0');
        $cli->register(new HelloCommand());
        self::assertSame(0, $cli->run(['list']));
    }

    public function testApplicationVersionReturns0(): void
    {
        $cli  = new Application('myapp', '2.5.0');
        $code = $cli->run(['version']);
        self::assertSame(0, $code);
    }

    public function testApplicationHelpReturns0(): void
    {
        $cli = new Application('test', '1.0');
        $cli->register(new HelloCommand());
        self::assertSame(0, $cli->run(['help', 'hello']));
    }

    public function testApplicationHelpUnknownCommandListsAll(): void
    {
        $cli  = new Application('test', '1.0');
        $code = $cli->run(['help']);
        self::assertSame(0, $code);
    }

    // -----------------------------------------------------------------
    // Output
    // -----------------------------------------------------------------

    private function captureOutput(callable $fn): string
    {
        $stream = fopen('php://memory', 'r+');
        $fn(new Output($stream));
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);
        return (string) $content;
    }

    public function testOutputWriteln(): void
    {
        $out = $this->captureOutput(fn(Output $o) => $o->writeln('test line'));
        self::assertStringContainsString('test line', $out);
    }

    public function testOutputTable(): void
    {
        $out = $this->captureOutput(function (Output $o) {
            $o->table(['Name', 'Age'], [
                ['Name' => 'Alice', 'Age' => '30'],
                ['Name' => 'Bob',   'Age' => '25'],
            ]);
        });
        self::assertStringContainsString('Alice', $out);
        self::assertStringContainsString('Bob',   $out);
        self::assertStringContainsString('Name',  $out);
    }

    public function testOutputColourTagsStripped(): void
    {
        $out = $this->captureOutput(fn(Output $o) => $o->success('ok'));
        // When not a tty (injected stream), colour tags must be stripped
        self::assertStringNotContainsString('<green>', $out);
        self::assertStringContainsString('ok', $out);
    }

    // -----------------------------------------------------------------
    // MakeMigrationCommand
    // -----------------------------------------------------------------

    public function testMakeMigrationCreatesTimestampedFile(): void
    {
        $dir = sys_get_temp_dir() . '/lift_mc_' . bin2hex(random_bytes(4));
        $cmd = new MakeMigrationCommand();
        $code = $cmd->execute(
            new Input(['make:migration', 'create_items_table', '--path=' . $dir]),
            new Output(fopen('php://memory', 'w'))
        );
        self::assertSame(0, $code);

        $files = glob($dir . '/*.php') ?: [];
        self::assertCount(1, $files);
        self::assertMatchesRegularExpression('/\d{4}_\d{2}_\d{2}_\d{6}_create_items_table\.php$/', $files[0]);

        $content = (string) file_get_contents($files[0]);
        self::assertStringContainsString("->create('items'", $content);
        self::assertStringContainsString('->id()', $content);

        unlink($files[0]);
        rmdir($dir);
    }

    public function testMakeMigrationAlterStub(): void
    {
        $dir = sys_get_temp_dir() . '/lift_mc_' . bin2hex(random_bytes(4));
        $cmd = new MakeMigrationCommand();
        $cmd->execute(
            new Input(['make:migration', 'add_email_to_users', '--path=' . $dir]),
            new Output(fopen('php://memory', 'w'))
        );

        $files = glob($dir . '/*.php') ?: [];
        $content = (string) file_get_contents($files[0]);
        self::assertStringContainsString("->alter('users'", $content);

        unlink($files[0]);
        rmdir($dir);
    }

    public function testMakeMigrationBlankStub(): void
    {
        $dir = sys_get_temp_dir() . '/lift_mc_' . bin2hex(random_bytes(4));
        $cmd = new MakeMigrationCommand();
        $cmd->execute(
            new Input(['make:migration', 'tweak_something', '--path=' . $dir]),
            new Output(fopen('php://memory', 'w'))
        );

        $files = glob($dir . '/*.php') ?: [];
        $content = (string) file_get_contents($files[0]);
        self::assertStringContainsString('extends Migration', $content);
        self::assertStringNotContainsString("->create(", $content);

        unlink($files[0]);
        rmdir($dir);
    }

    public function testMakeMigrationRequiresName(): void
    {
        $stderr = fopen('php://memory', 'r+');
        $code   = (new MakeMigrationCommand())->execute(
            new Input(['make:migration']),
            new Output(fopen('php://memory', 'w'), $stderr)
        );
        self::assertSame(1, $code);
    }

    // -----------------------------------------------------------------
    // KeyGenerateCommand
    // -----------------------------------------------------------------

    public function testKeyGenerateCreatesEnvFile(): void
    {
        $file = sys_get_temp_dir() . '/lift_env_' . bin2hex(random_bytes(4));
        $code = (new KeyGenerateCommand($file))->execute(
            new Input(['key:generate']),
            new Output(fopen('php://memory', 'w'))
        );
        self::assertSame(0, $code);
        self::assertFileExists($file);
        self::assertMatchesRegularExpression('/^APP_KEY=base64:.+$/m', (string) file_get_contents($file));
        unlink($file);
    }

    public function testKeyGenerateReplacesExistingKey(): void
    {
        $file = sys_get_temp_dir() . '/lift_env_' . bin2hex(random_bytes(4));
        file_put_contents($file, "APP_DEBUG=true\nAPP_KEY=base64:oldkey\nAPP_ENV=local\n");

        (new KeyGenerateCommand($file))->execute(
            new Input(['key:generate']),
            new Output(fopen('php://memory', 'w'))
        );

        $contents = (string) file_get_contents($file);
        self::assertStringNotContainsString('oldkey', $contents);
        self::assertStringContainsString('APP_KEY=base64:', $contents);
        self::assertStringContainsString('APP_DEBUG=true', $contents); // other lines preserved
        unlink($file);
    }

    public function testKeyGenerateAppendsWhenKeyMissing(): void
    {
        $file = sys_get_temp_dir() . '/lift_env_' . bin2hex(random_bytes(4));
        file_put_contents($file, "APP_DEBUG=true\n");

        (new KeyGenerateCommand($file))->execute(
            new Input(['key:generate']),
            new Output(fopen('php://memory', 'w'))
        );

        $contents = (string) file_get_contents($file);
        self::assertStringContainsString('APP_KEY=base64:', $contents);
        self::assertStringContainsString('APP_DEBUG=true', $contents);
        unlink($file);
    }

    // -----------------------------------------------------------------
    // MakeCommand — new types
    // -----------------------------------------------------------------

    public function testMakeCommandType(): void
    {
        foreach (['command', 'job', 'test', 'event'] as $type) {
            $dir  = sys_get_temp_dir() . '/lift_make_' . bin2hex(random_bytes(4));
            $cmd  = new MakeCommand($type);
            $code = $cmd->execute(
                new Input(['make:' . $type, 'Sample', '--path=' . $dir]),
                new Output(fopen('php://memory', 'w'))
            );
            self::assertSame(0, $code, "make:{$type} should exit 0");
            $files = glob($dir . '/**/*.php', GLOB_BRACE) ?: glob($dir . '/*.php') ?: [];
            // glob recursively to handle nested namespace dirs
            $all = [];
            $it  = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
            foreach ($it as $f) {
                if ($f instanceof \SplFileInfo && $f->isFile()) {
                    $all[] = $f->getPathname();
                }
            }
            self::assertCount(1, $all, "make:{$type} should create exactly one file");
            self::assertStringContainsString('Sample', (string) file_get_contents($all[0]));
        }
    }

    // -----------------------------------------------------------------
    // Migrate commands (integration with SQLite)
    // -----------------------------------------------------------------

    private function migrationDir(): string
    {
        $dir = sys_get_temp_dir() . '/lift_cmd_migr_' . bin2hex(random_bytes(4));
        mkdir($dir);

        file_put_contents($dir . '/2026_01_01_000000_create_cmd_a.php', <<<'PHP'
<?php
use Lift\Database\Migration;
return new class($db) extends Migration {
    public function up(): void   { $this->db->execute('CREATE TABLE cmd_a (id INTEGER PRIMARY KEY)'); }
    public function down(): void { $this->db->execute('DROP TABLE cmd_a'); }
};
PHP);

        return $dir;
    }

    public function testMigrateRollbackCommand(): void
    {
        $dir = $this->migrationDir();
        $db  = new Connection('sqlite::memory:');

        $status = new MigrateStatusCommand($db, $dir);
        $rollback = new MigrateRollbackCommand($db, $dir);

        // Nothing to roll back yet
        $code = $rollback->execute(new Input(['migrate:rollback']), new Output(fopen('php://memory', 'w')));
        self::assertSame(0, $code);

        // Migrate, then rollback
        $db->execute('CREATE TABLE IF NOT EXISTS migrations (migration VARCHAR(255) PRIMARY KEY, batch INTEGER NOT NULL)');
        $db->execute("INSERT INTO migrations (migration, batch) VALUES ('2026_01_01_000000_create_cmd_a', 1)");
        $db->execute('CREATE TABLE cmd_a (id INTEGER PRIMARY KEY)');

        $code = $rollback->execute(new Input(['migrate:rollback']), new Output(fopen('php://memory', 'w')));
        self::assertSame(0, $code);
    }

    public function testMigrateStatusCommand(): void
    {
        $dir = $this->migrationDir();
        $db  = new Connection('sqlite::memory:');
        $cmd = new MigrateStatusCommand($db, $dir);

        $out  = fopen('php://memory', 'r+');
        $code = $cmd->execute(new Input(['migrate:status']), new Output($out));
        self::assertSame(0, $code);

        rewind($out);
        $text = stream_get_contents($out);
        self::assertStringContainsString('create_cmd_a', (string) $text);
        self::assertStringContainsString('No', (string) $text); // not run yet
    }

    public function testMigrateResetCommandOnEmptyDb(): void
    {
        $dir = $this->migrationDir();
        $db  = new Connection('sqlite::memory:');
        $cmd = new MigrateResetCommand($db, $dir);

        $code = $cmd->execute(new Input(['migrate:reset']), new Output(fopen('php://memory', 'w')));
        self::assertSame(0, $code);
    }

    public function testMigrateFreshCommand(): void
    {
        $dir = $this->migrationDir();
        $db  = new Connection('sqlite::memory:');
        $cmd = new MigrateFreshCommand($db, $dir);

        $out  = fopen('php://memory', 'r+');
        $code = $cmd->execute(new Input(['migrate:fresh']), new Output($out));
        self::assertSame(0, $code);

        // After fresh on empty db, migration should have run
        $count = $db->table('cmd_a')->count();
        self::assertSame(0, $count); // table exists and is empty
    }
}
