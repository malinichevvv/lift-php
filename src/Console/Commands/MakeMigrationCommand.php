<?php

declare(strict_types=1);

namespace Lift\Console\Commands;

use Lift\Console\Command;
use Lift\Console\Input;
use Lift\Console\Output;

/**
 * Generates a timestamped migration file.
 *
 * Naming conventions drive the generated stub:
 *  - `create_<table>_table`  → CREATE TABLE stub with `id()` + `timestamps()`
 *  - `add_<cols>_to_<table>` → ALTER TABLE stub
 *  - anything else           → blank up/down stub
 */
final class MakeMigrationCommand extends Command
{
    public function getName(): string        { return 'make:migration'; }
    public function getDescription(): string { return 'Create a new database migration file'; }
    public function getHelp(): string        { return 'Usage: lift make:migration create_users_table [--path=database/migrations]'; }

    public function execute(Input $input, Output $output): int
    {
        $name = $input->getArgument(0);
        if ($name === '') {
            $output->error('Migration name is required.');
            return 1;
        }

        $basePath = rtrim((string) $input->getOption('path', getcwd() . '/database/migrations'), '/');

        if (!is_dir($basePath) && !mkdir($basePath, 0775, true) && !is_dir($basePath)) {
            $output->error("Unable to create directory: {$basePath}");
            return 1;
        }

        $slug   = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '_', trim($name, '_')));
        $target = $basePath . '/' . date('Y_m_d_His') . '_' . $slug . '.php';

        if (file_exists($target)) {
            $output->error("File already exists: {$target}");
            return 1;
        }

        file_put_contents($target, $this->stub($slug));
        $output->success("Created: {$target}");
        return 0;
    }

    private function stub(string $name): string
    {
        if (preg_match('/^create_(.+)_table$/', $name, $m)) {
            return $this->createStub($m[1]);
        }
        if (preg_match('/^add_.+_to_(.+)$/', $name, $m)) {
            return $this->alterStub($m[1]);
        }
        return $this->blankStub();
    }

    private function createStub(string $table): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

use Lift\Database\Migration;
use Lift\Database\Schema\Blueprint;
use Lift\Database\Schema\Schema;

return new class(\$db) extends Migration {
    public function up(): void
    {
        (new Schema(\$this->db))->create('{$table}', function (Blueprint \$t) {
            \$t->id();
            \$t->timestamps();
        });
    }

    public function down(): void
    {
        (new Schema(\$this->db))->dropIfExists('{$table}');
    }
};
PHP;
    }

    private function alterStub(string $table): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

use Lift\Database\Migration;
use Lift\Database\Schema\Blueprint;
use Lift\Database\Schema\Schema;

return new class(\$db) extends Migration {
    public function up(): void
    {
        (new Schema(\$this->db))->alter('{$table}', function (Blueprint \$t) {
            // \$t->string('column');
        });
    }

    public function down(): void
    {
        (new Schema(\$this->db))->alter('{$table}', function (Blueprint \$t) {
            // \$t->dropColumn('column');
        });
    }
};
PHP;
    }

    private function blankStub(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

use Lift\Database\Migration;

return new class($db) extends Migration {
    public function up(): void
    {
        //
    }

    public function down(): void
    {
        //
    }
};
PHP;
    }
}
