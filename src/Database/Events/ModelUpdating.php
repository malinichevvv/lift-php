<?php

declare(strict_types=1);

namespace Lift\Database\Events;

use Lift\Database\Model;
use Lift\Events\StoppableEvent;

/**
 * Fired before an existing model row is updated.
 *
 * Stoppable — call `stopPropagation()` to cancel the update.
 * `Model::save()` will return `false` when propagation is stopped.
 */
final class ModelUpdating extends StoppableEvent
{
    public function __construct(public readonly Model $model) {}
}
