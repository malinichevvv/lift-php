<?php

declare(strict_types=1);

namespace Lift\Events;

use Psr\EventDispatcher\ListenerProviderInterface;

/**
 * PSR-14 listener registry.
 *
 * Matches events by exact class or any parent class / implemented interface,
 * so listeners registered on an interface receive all implementing events.
 */
final class ListenerProvider implements ListenerProviderInterface
{
    /** @var array<string, list<callable>> */
    private array $listeners = [];

    /**
     * Register a listener for a specific event class or interface.
     *
     * @param string $eventClass Event class or interface name.
     */
    public function addListener(string $eventClass, callable $listener): void
    {
        if (!class_exists($eventClass) && !interface_exists($eventClass)) {
            throw new \InvalidArgumentException("Event class or interface does not exist: [{$eventClass}]");
        }
        $this->listeners[$eventClass][] = $listener;
    }

    /** Alias for {@see addListener()}. */
    public function listen(string $eventClass, callable $listener): void
    {
        $this->addListener($eventClass, $listener);
    }

    /**
     * @return iterable<callable>
     */
    public function getListenersForEvent(object $event): iterable
    {
        foreach ($this->listeners as $type => $listeners) {
            if ($event instanceof $type) {
                yield from $listeners;
            }
        }
    }

    /** Remove all listeners for a given event class. */
    public function forget(string $eventClass): void
    {
        unset($this->listeners[$eventClass]);
    }

    /** Check if any listener is registered for a given event class. */
    public function hasListeners(string $eventClass): bool
    {
        return isset($this->listeners[$eventClass]) && !empty($this->listeners[$eventClass]);
    }
}
