<?php

declare(strict_types=1);

namespace Lift\Database\Events;

use Lift\Database\Model;

/** Fired after a new model row has been successfully inserted. */
final class ModelCreated
{
    public function __construct(public readonly Model $model) {}
}
