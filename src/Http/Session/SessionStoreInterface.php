<?php

declare(strict_types=1);

namespace Lift\Http\Session;

/** Storage contract for server-side sessions. */
interface SessionStoreInterface
{
    public function read(string $id): ?string;
    public function write(string $id, string $payload, int $ttl): void;
    public function destroy(string $id): void;
    public function gc(int $maxLifetime): void;
}
