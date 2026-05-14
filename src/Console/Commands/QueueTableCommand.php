<?php

declare(strict_types=1);

namespace Lift\Console\Commands;

use Lift\Console\Command;
use Lift\Console\Input;
use Lift\Console\Output;

/**
 * Generates a migration file for the database queue jobs table.
 *
 * Usage:
 *   lift queue:table [--table=jobs] [--path=database/migrations]
 *
 * After running, execute `lift migrate` to create the table.
 * To add custom columns, edit the generated migration and update your
 * {@see \Lift\Queue\DatabaseQueue} constructor accordingly.
 */
final class QueueTableCommand extends Command
{
    public function getName(): string        { return 'queue:table'; }
    public function getDescription(): string { return 'Generate a migration for the database queue table'; }
    public function getHelp(): string
    {
        return <<<'HELP'
        Generates a ready-made migration that creates the queue jobs table.

        Options:
          --table=<name>   Table name (default: "jobs")
          --path=<dir>     Migrations directory (default: database/migrations)

        After generating, run 'lift migrate' to apply.
        HELP;
    }

    public function execute(Input $input, Output $output): int
    {
        $table    = (string) $input->getOption('table', 'jobs');
        $basePath = rtrim((string) $input->getOption('path', getcwd() . '/database/migrations'), '/');

        if (!is_dir($basePath) && !mkdir($basePath, 0775, true) && !is_dir($basePath)) {
            $output->error("Cannot create directory: {$basePath}");
            return 1;
        }

        $filename = date('Y_m_d_His') . '_create_' . $table . '_table.php';
        $target   = $basePath . '/' . $filename;

        if (file_exists($target)) {
            $output->error("File already exists: {$target}");
            return 1;
        }

        file_put_contents($target, $this->stub($table));
        $output->success("Migration created: {$target}");
        $output->info("Run 'lift migrate' to create the '{$table}' table.");
        return 0;
    }

    private function stub(string $table): string
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
            \$t->string('queue', 100)->default('default');
            \$t->longText('payload');
            \$t->smallInteger('attempts')->default(0);
            \$t->bigInteger('available_at');
            \$t->bigInteger('reserved_at')->nullable();
            \$t->bigInteger('failed_at')->nullable();
            \$t->text('error')->nullable();
            \$t->bigInteger('created_at');

            // Add custom application columns below this line.
            // Match them with the \$extraColumns callback on DatabaseQueue
            // and implement HasDatabaseExtra on your job classes.
            // Example:
            // \$t->string('tenant_id', 36)->nullable()->index();

            \$t->index('queue');
        });
    }

    public function down(): void
    {
        (new Schema(\$this->db))->dropIfExists('{$table}');
    }
};
PHP;
    }
}