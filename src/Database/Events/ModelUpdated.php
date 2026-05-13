<?php

declare(strict_types=1);

namespace Lift\Database\Events;

use Lift\Database\Model;

/** Fired after an existing model row has been successfully updated. */
final class ModelUpdated
{
    public function __construct(public readonly Model $model) {}
}
