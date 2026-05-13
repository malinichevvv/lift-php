<?php

declare(strict_types=1);

namespace Lift\Log\Handler;

/** Discards all log records. Useful in tests. */
final class NullHandler extends AbstractHandler
{
    public function __construct()
    {
        parent::__construct('debug');
    }

    protected function write(string $formatted): void {}
}
