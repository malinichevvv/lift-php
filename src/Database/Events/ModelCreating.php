<?php

declare(strict_types=1);

namespace Lift\Database\Events;

use Lift\Database\Model;
use Lift\Events\StoppableEvent;

/**
 * Fired before a new model row is inserted.
 *
 * This event is stoppable — call `stopPropagation()` inside a listener to cancel
 * the insert. `Model::save()` will return `false` when propagation is stopped.
 *
 * ```php
 * $dispatcher->listen(ModelCreating::class, function (ModelCreating $e) {
 *     $e->model->set('uuid', Uuid::v4());   // set a UUID before insert
 * });
 * ```
 */
final class ModelCreating extends StoppableEvent
{
    public function __construct(public readonly Model $model) {}
}
