<?php

declare(strict_types=1);

namespace Lift\Events;

use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Base class for stoppable events.
 *
 * Extend this class when your event needs to stop propagation to remaining
 * listeners (e.g. on authentication failure, cancellable requests).
 *
 * ```php
 * class OrderPlaced extends StoppableEvent
 * {
 *     public function __construct(public readonly Order $order) {}
 * }
 *
 * $dispatcher->listen(OrderPlaced::class, function (OrderPlaced $e) {
 *     if ($blocked) {
 *         $e->stopPropagation();
 *     }
 * });
 * ```
 */
abstract class StoppableEvent implements StoppableEventInterface
{
    private bool $stopped = false;

    public function stopPropagation(): void
    {
        $this->stopped = true;
    }

    public function isPropagationStopped(): bool
    {
        return $this->stopped;
    }
}
