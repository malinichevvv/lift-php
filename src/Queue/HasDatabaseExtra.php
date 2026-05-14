<?php

declare(strict_types=1);

namespace Lift\Queue;

/**
 * Optional interface for jobs that need to store extra data in the database queue table.
 *
 * Implement this on any job class to persist additional columns alongside the
 * standard queue payload. The column names must match columns added via the
 * `$extraColumns` callback passed to {@see DatabaseQueue}.
 *
 * ```php
 * class SendInvoice extends AbstractJob implements HasDatabaseExtra
 * {
 *     public function __construct(
 *         private readonly string $tenantId,
 *         private readonly int    $invoiceId,
 *     ) {}
 *
 *     public function handle(): void { ... }
 *
 *     public function getDatabaseExtra(): array
 *     {
 *         return ['tenant_id' => $this->tenantId];
 *     }
 * }
 * ```
 */
interface HasDatabaseExtra
{
    /**
     * Extra column values to INSERT alongside the standard queue columns.
     *
     * @return array<string, mixed>
     */
    public function getDatabaseExtra(): array;
}