<?php

declare(strict_types=1);

namespace Lift\Database\Events;

use Lift\Database\Model;

/** Fired after a model row has been successfully deleted. */
final class ModelDeleted
{
    public function __construct(public readonly Model $model) {}
}
