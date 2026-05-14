<?php

declare(strict_types=1);

namespace Lift\Queue;

use Lift\Database\Connection;
use Lift\Database\Schema\Blueprint;
use Lift\Database\Schema\Schema;

/**
 * Queue driver backed by any PDO-compatible relational database.
 *
 * The table is created automatically on first use. Override the table name,
 * add application-specific columns via the `$extraColumns` callback, and let
 * jobs implement {@see HasDatabaseExtra} to persist values into those columns.
 *
 * ```php
 * // Minimal — uses the "jobs" table, auto-created
 * $queue = new DatabaseQueue($db);
 *
 * // Custom table + extra column
 * $queue = new DatabaseQueue(
 *     db: $db,
 *     table: 'queue_jobs',
 *     extraColumns: function (Blueprint $t): void {
 *         $t->string('tenant_id', 36)->nullable()->index();
 *     },
 * );
 *
 * // Job that writes into the extra column
 * class SendInvoice extends AbstractJob implements HasDatabaseExtra
 * {
 *     public function __construct(private string $tenantId, private int $id) {}
 *     public function handle(): void { ... }
 *     public function getDatabaseExtra(): array { return ['tenant_id' => $this->tenantId]; }
 * }
 * ```
 *
 * ### Table schema
 *
 * | Column         | Type         | Description                                      |
 * |----------------|--------------|--------------------------------------------------|
 * | id             | BIGINT PK    | Auto-increment row identifier.                   |
 * | queue          | VARCHAR(100) | Queue name (default: "default").                 |
 * | payload        | LONGTEXT     | Serialised job (JSON wrapper + PHP serialize).   |
 * | attempts       | SMALLINT     | Times this row has been reserved by a worker.    |
 * | available_at   | BIGINT       | Unix timestamp when the job becomes dispatchable.|
 * | reserved_at    | BIGINT NULL  | Set when a worker pops the job; cleared on retry.|
 * | failed_at      | BIGINT NULL  | Set when all retries are exhausted.              |
 * | error          | TEXT NULL    | Last exception message (on failure).             |
 * | created_at     | BIGINT       | Unix timestamp of insertion.                     |
 * | *(extra)*      | *any*        | Application columns via `$extraColumns`.         |
 *
 * ### Crash recovery
 *
 * If a worker process crashes while processing a job, the row stays reserved
 * indefinitely. `pop()` calls {@see pruneReserved()} automatically before each
 * fetch: any row reserved longer than `$reservedTimeout` seconds is released
 * back to the pending state so another worker can pick it up.
 *
 * ### Concurrent workers
 *
 * On MySQL and PostgreSQL, `pop()` uses `SELECT … FOR UPDATE SKIP LOCKED` inside
 * a transaction so that two workers polling simultaneously never receive the same
 * job. On SQLite the transaction alone prevents double-delivery.
 *
 * ### Failed job management
 *
 * ```php
 * $failed = $queue->listFailed();          // all failed rows
 * $queue->retry($rowId);                   // re-queue one failed job
 * $queue->retryAll();                      // re-queue every failed job
 * $queue->clearFailed();                   // delete all failed rows
 * $queue->failedCount();                   // count of failed rows
 * ```
 */
final class DatabaseQueue implements QueueInterface
{
    private bool $tableChecked = false;
    private readonly ?\Closure $extraColumns;

    /**
     * @param Connection    $db               PDO connection.
     * @param string        $table            Table name (created automatically if absent).
     * @param callable|null $extraColumns     `function (Blueprint $table): void` — add custom columns.
     * @param int           $reservedTimeout  Seconds after which a reserved-but-unfinished row is
     *                                        released back to the queue (crash recovery).
     */
    public function __construct(
        private readonly Connection $db,
        private readonly string $table = 'jobs',
        ?callable $extraColumns = null,
        private readonly int $reservedTimeout = 60,
    ) {
        $this->extraColumns = $extraColumns !== null ? \Closure::fromCallable($extraColumns) : null;
    }

    // -----------------------------------------------------------------
    // QueueInterface
    // -----------------------------------------------------------------

    /** {@inheritdoc} */
    public function push(JobInterface $job): string
    {
        $this->ensureTable();
        return $this->insertJob($job, $job->getDelay());
    }

    /** {@inheritdoc} */
    public function later(int $delay, JobInterface $job): string
    {
        $this->ensureTable();
        return $this->insertJob($job, $delay);
    }

    /**
     * {@inheritdoc}
     *
     * Atomically reserves the next available job. Returns a {@see DatabaseJobEnvelope}
     * that automatically deletes or marks the row failed once the worker finishes.
     */
    public function pop(string $queue = 'default'): ?JobInterface
    {
        $this->ensureTable();
        $this->pruneReserved();

        $now = time();
        $tbl = $this->table;

        $row = $this->db->transaction(function (Connection $db) use ($queue, $now, $tbl): ?array {
            $driver    = $db->getDriverName();
            $forUpdate = in_array($driver, ['mysql', 'pgsql'], true)
                ? ' FOR UPDATE SKIP LOCKED'
                : '';

            $row = $db->selectOne(
                "SELECT * FROM {$tbl}
                  WHERE queue = ? AND available_at <= ? AND reserved_at IS NULL AND failed_at IS NULL
                  ORDER BY available_at ASC, id ASC
                  LIMIT 1" . $forUpdate,
                [$queue, $now],
            );

            if ($row === null) {
                return null;
            }

            $affected = $db->execute(
                "UPDATE {$tbl} SET reserved_at = ?, attempts = attempts + 1 WHERE id = ? AND reserved_at IS NULL",
                [$now, $row['id']],
            );

            // Another worker grabbed this row between SELECT and UPDATE
            if ($affected === 0) {
                return null;
            }

            return $row;
        });

        if ($row === null) {
            return null;
        }

        $job = $this->deserialise((string) $row['payload']);
        return new DatabaseJobEnvelope($job, $this, (int) $row['id']);
    }

    /** {@inheritdoc} */
    public function size(string $queue = 'default'): int
    {
        $this->ensureTable();
        return (int) $this->db->value(
            "SELECT COUNT(*) FROM {$this->table}
              WHERE queue = ? AND available_at <= ? AND reserved_at IS NULL AND failed_at IS NULL",
            [$queue, time()],
        );
    }

    /** {@inheritdoc} */
    public function clear(string $queue = 'default'): void
    {
        $this->ensureTable();
        $this->db->execute("DELETE FROM {$this->table} WHERE queue = ?", [$queue]);
    }

    // -----------------------------------------------------------------
    // Row lifecycle — called by DatabaseJobEnvelope
    // -----------------------------------------------------------------

    /** Delete the row after successful processing. */
    public function acknowledge(int $rowId): void
    {
        $this->db->execute("DELETE FROM {$this->table} WHERE id = ?", [$rowId]);
    }

    /** Permanently mark a row as failed, storing the exception message. */
    public function markFailed(int $rowId, \Throwable $e): void
    {
        $this->db->execute(
            "UPDATE {$this->table} SET failed_at = ?, reserved_at = NULL, error = ? WHERE id = ?",
            [time(), substr($e->getMessage(), 0, 65535), $rowId],
        );
    }

    // -----------------------------------------------------------------
    // Failed job management
    // -----------------------------------------------------------------

    /**
     * Return all failed jobs for the given queue, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listFailed(string $queue = 'default'): array
    {
        $this->ensureTable();
        return $this->db->select(
            "SELECT * FROM {$this->table} WHERE queue = ? AND failed_at IS NOT NULL ORDER BY failed_at DESC",
            [$queue],
        );
    }

    /** Count failed jobs in the given queue. */
    public function failedCount(string $queue = 'default'): int
    {
        $this->ensureTable();
        return (int) $this->db->value(
            "SELECT COUNT(*) FROM {$this->table} WHERE queue = ? AND failed_at IS NOT NULL",
            [$queue],
        );
    }

    /**
     * Re-queue a single failed job by its row ID.
     *
     * Resets `failed_at`, `reserved_at`, and `attempts` so the worker
     * processes it fresh with the full `tries` budget.
     */
    public function retry(int $rowId): void
    {
        $this->db->execute(
            "UPDATE {$this->table}
               SET failed_at = NULL, reserved_at = NULL, attempts = 0, available_at = ?, error = NULL
             WHERE id = ?",
            [time(), $rowId],
        );
    }

    /**
     * Re-queue every failed job in the given queue.
     *
     * @return int Number of rows updated.
     */
    public function retryAll(string $queue = 'default'): int
    {
        $this->ensureTable();
        return $this->db->execute(
            "UPDATE {$this->table}
               SET failed_at = NULL, reserved_at = NULL, attempts = 0, available_at = ?, error = NULL
             WHERE queue = ? AND failed_at IS NOT NULL",
            [time(), $queue],
        );
    }

    /** Delete all permanently failed jobs for the given queue. */
    public function clearFailed(string $queue = 'default'): void
    {
        $this->ensureTable();
        $this->db->execute(
            "DELETE FROM {$this->table} WHERE queue = ? AND failed_at IS NOT NULL",
            [$queue],
        );
    }

    // -----------------------------------------------------------------
    // Crash recovery
    // -----------------------------------------------------------------

    /**
     * Release reserved rows that have been held longer than `$timeout` seconds.
     *
     * Call this periodically (or let `pop()` call it automatically) to recover
     * jobs from crashed worker processes. Released jobs become available for
     * the next `pop()` call.
     *
     * @param int|null $timeout Seconds — defaults to the `$reservedTimeout` constructor value.
     * @return int Number of rows released.
     */
    public function pruneReserved(?int $timeout = null): int
    {
        $deadline = time() - ($timeout ?? $this->reservedTimeout);
        return $this->db->execute(
            "UPDATE {$this->table}
               SET reserved_at = NULL
             WHERE reserved_at IS NOT NULL AND reserved_at < ? AND failed_at IS NULL",
            [$deadline],
        );
    }

    /**
     * Release a specific reserved row back to the pending state (optionally with a delay).
     *
     * Useful when custom worker code needs to explicitly re-queue a job before
     * the reserved timeout elapses.
     */
    public function release(int $rowId, int $delay = 0): void
    {
        $this->db->execute(
            "UPDATE {$this->table} SET reserved_at = NULL, available_at = ? WHERE id = ?",
            [time() + $delay, $rowId],
        );
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    private function insertJob(JobInterface $job, int $delay): string
    {
        $id  = $this->generateId();
        $now = time();

        $base = [
            'queue'        => $job->getQueue(),
            'payload'      => $this->serialise($job, $id),
            'attempts'     => 0,
            'available_at' => $now + $delay,
            'reserved_at'  => null,
            'failed_at'    => null,
            'error'        => null,
            'created_at'   => $now,
        ];

        $extra = $job instanceof HasDatabaseExtra ? $job->getDatabaseExtra() : [];
        $data  = array_merge($base, $extra);

        $cols         = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $this->db->execute(
            "INSERT INTO {$this->table} ({$cols}) VALUES ({$placeholders})",
            array_values($data),
        );

        return $id;
    }

    private function ensureTable(): void
    {
        if ($this->tableChecked) {
            return;
        }
        $this->tableChecked = true;

        $schema = new Schema($this->db);
        if ($schema->hasTable($this->table)) {
            return;
        }

        $extraCb = $this->extraColumns;

        $schema->create($this->table, static function (Blueprint $t) use ($extraCb): void {
            $t->id();
            $t->string('queue', 100)->default('default');
            $t->longText('payload');
            $t->smallInteger('attempts')->default(0);
            $t->bigInteger('available_at');
            $t->bigInteger('reserved_at')->nullable();
            $t->bigInteger('failed_at')->nullable();
            $t->text('error')->nullable();
            $t->bigInteger('created_at');

            if ($extraCb !== null) {
                $extraCb($t);
            }

            // Composite index that covers the hot pop() query path
            $t->index('queue');
        });
    }

    private function serialise(JobInterface $job, string $id): string
    {
        $data = json_encode([
            'id'       => $id,
            'class'    => $job::class,
            'payload'  => serialize($job),
            'tries'    => $job->getTries(),
            'pushedAt' => time(),
        ]);

        if ($data === false) {
            throw new \RuntimeException('Failed to serialise job: ' . json_last_error_msg());
        }

        return $data;
    }

    private function deserialise(string $raw): JobInterface
    {
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['payload'])) {
            throw new \RuntimeException("Corrupted queue payload: {$raw}");
        }

        $job = unserialize($data['payload']);
        if (!$job instanceof JobInterface) {
            throw new \RuntimeException("Deserialised payload is not a JobInterface: {$data['class']}");
        }

        return $job;
    }

    private function generateId(): string
    {
        return 'db_' . bin2hex(random_bytes(8));
    }
}