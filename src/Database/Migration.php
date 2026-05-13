<?php

declare(strict_types=1);

namespace Lift\Database;

/**
 * Base class for database migrations.
 *
 * Migrations are plain PHP classes with `up()` and `down()` methods. They use
 * the existing PDO-backed {@see Connection}, avoiding a heavy schema builder in
 * the core while keeping migrations portable and explicit.
 */
abstract class Migration
{
    public function __construct(protected readonly Connection $db) {}

    /** Apply the migration. */
    abstract public function up(): void;

    /** Roll the migration back. */
    abstract public function down(): void;
}
