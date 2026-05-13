<?php

declare(strict_types=1);

namespace Lift\Database\Events;

use Lift\Database\Model;
use Lift\Events\StoppableEvent;

/**
 * Fired before a model row is deleted.
 *
 * Stoppable — call `stopPropagation()` to cancel the deletion.
 * `Model::delete()` will return `false` when propagation is stopped.
 */
final class ModelDeleting extends StoppableEvent
{
    public function __construct(public readonly Model $model) {}
}
