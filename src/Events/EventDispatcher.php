<?php

declare(strict_types=1);

namespace Lift\Events;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\StoppableEventInterface;

/**
 * PSR-14 event dispatcher.
 *
 * ```php
 * $dispatcher = new EventDispatcher();
 *
 * // Register listeners
 * $dispatcher->listen(UserRegistered::class, function (UserRegistered $e) {
 *     sendWelcomeEmail($e->user);
 * });
 *
 * // Dispatch
 * $dispatcher->dispatch(new UserRegistered($user));
 * ```
 *
 * Listeners are called in registration order. If an event implements
 * `StoppableEventInterface` and `isPropagationStopped()` returns true,
 * remaining listeners are skipped.
 */
final class EventDispatcher implements EventDispatcherInterface
{
    private readonly ListenerProvider $provider;

    public function __construct(?ListenerProvider $provider = null)
    {
        $this->provider = $provider ?? new ListenerProvider();
    }

    /**
     * Dispatch an event to all registered listeners.
     *
     * @template T of object
     * @param  T $event
     * @return T
     */
    public function dispatch(object $event): object
    {
        foreach ($this->provider->getListenersForEvent($event) as $listener) {
            if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                break;
            }
            $listener($event);
        }
        return $event;
    }

    /**
     * Register a listener for an event class or interface.
     *
     * @param  class-string $eventClass
     * @param  callable     $listener   Receives the event as its only argument.
     */
    public function listen(string $eventClass, callable $listener): self
    {
        $this->provider->addListener($eventClass, $listener);
        return $this;
    }

    /**
     * Register multiple listeners from a subscriber object.
     *
     * The subscriber must implement `getSubscribedEvents(): array<class-string, string>`,
     * where values are method names on the subscriber.
     *
     * ```php
     * class NotificationSubscriber {
     *     public static function getSubscribedEvents(): array {
     *         return [UserRegistered::class => 'onUserRegistered'];
     *     }
     *     public function onUserRegistered(UserRegistered $e): void { ... }
     * }
     * $dispatcher->subscribe(new NotificationSubscriber());
     * ```
     */
    public function subscribe(object $subscriber): self
    {
        if (!method_exists($subscriber, 'getSubscribedEvents')) {
            throw new \InvalidArgumentException(
                get_class($subscriber) . ' must implement getSubscribedEvents(): array'
            );
        }

        foreach ($subscriber->getSubscribedEvents() as $eventClass => $method) {
            $this->listen($eventClass, [$subscriber, $method]);
        }
        return $this;
    }

    /** Expose the underlying provider for inspection/manipulation. */
    public function getProvider(): ListenerProvider
    {
        return $this->provider;
    }
}
