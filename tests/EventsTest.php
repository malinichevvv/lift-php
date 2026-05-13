<?php

declare(strict_types=1);

namespace Lift\Tests;

use Lift\Events\EventDispatcher;
use Lift\Events\ListenerProvider;
use Lift\Events\StoppableEvent;
use PHPUnit\Framework\TestCase;

// --- Test event stubs ---

class OrderPlaced
{
    public function __construct(public readonly int $orderId) {}
}

class PaymentReceived extends OrderPlaced {}

class CancellableOrder extends StoppableEvent
{
    public function __construct(public bool $cancelled = false) {}
}

// --- Subscriber stub ---

class OrderSubscriber
{
    public array $handled = [];

    public static function getSubscribedEvents(): array
    {
        return [OrderPlaced::class => 'onOrder'];
    }

    public function onOrder(OrderPlaced $e): void
    {
        $this->handled[] = $e->orderId;
    }
}

class EventsTest extends TestCase
{
    // -----------------------------------------------------------------
    // ListenerProvider
    // -----------------------------------------------------------------

    public function testListenerProviderMatchesExactClass(): void
    {
        $provider = new ListenerProvider();
        $called   = false;
        $provider->addListener(OrderPlaced::class, function (OrderPlaced $e) use (&$called) {
            $called = true;
        });

        $listeners = iterator_to_array($provider->getListenersForEvent(new OrderPlaced(1)));
        self::assertCount(1, $listeners);
        ($listeners[0])(new OrderPlaced(1));
        self::assertTrue($called);
    }

    public function testListenerProviderMatchesSubclass(): void
    {
        $provider = new ListenerProvider();
        $received = [];
        $provider->addListener(OrderPlaced::class, function (OrderPlaced $e) use (&$received) {
            $received[] = $e->orderId;
        });

        $listeners = iterator_to_array($provider->getListenersForEvent(new PaymentReceived(42)));
        self::assertCount(1, $listeners);
        ($listeners[0])(new PaymentReceived(42));
        self::assertSame([42], $received);
    }

    public function testListenerProviderHasListeners(): void
    {
        $provider = new ListenerProvider();
        self::assertFalse($provider->hasListeners(OrderPlaced::class));
        $provider->addListener(OrderPlaced::class, fn() => null);
        self::assertTrue($provider->hasListeners(OrderPlaced::class));
    }

    public function testListenerProviderForget(): void
    {
        $provider = new ListenerProvider();
        $provider->addListener(OrderPlaced::class, fn() => null);
        $provider->forget(OrderPlaced::class);
        self::assertFalse($provider->hasListeners(OrderPlaced::class));
    }

    // -----------------------------------------------------------------
    // EventDispatcher — basic dispatch
    // -----------------------------------------------------------------

    public function testDispatchCallsListeners(): void
    {
        $dispatcher = new EventDispatcher();
        $collected  = [];
        $dispatcher->listen(OrderPlaced::class, function (OrderPlaced $e) use (&$collected) {
            $collected[] = $e->orderId;
        });

        $dispatcher->dispatch(new OrderPlaced(10));
        $dispatcher->dispatch(new OrderPlaced(20));

        self::assertSame([10, 20], $collected);
    }

    public function testDispatchReturnsEvent(): void
    {
        $dispatcher = new EventDispatcher();
        $event      = new OrderPlaced(99);
        $returned   = $dispatcher->dispatch($event);
        self::assertSame($event, $returned);
    }

    public function testMultipleListenersCalledInOrder(): void
    {
        $dispatcher = new EventDispatcher();
        $log        = [];
        $dispatcher->listen(OrderPlaced::class, function () use (&$log) { $log[] = 'first'; });
        $dispatcher->listen(OrderPlaced::class, function () use (&$log) { $log[] = 'second'; });
        $dispatcher->dispatch(new OrderPlaced(1));
        self::assertSame(['first', 'second'], $log);
    }

    // -----------------------------------------------------------------
    // Stoppable events
    // -----------------------------------------------------------------

    public function testPropagationStops(): void
    {
        $dispatcher = new EventDispatcher();
        $log        = [];

        $dispatcher->listen(CancellableOrder::class, function (CancellableOrder $e) use (&$log) {
            $log[]  = 'first';
            $e->stopPropagation();
        });
        $dispatcher->listen(CancellableOrder::class, function () use (&$log) {
            $log[] = 'second'; // should not run
        });

        $dispatcher->dispatch(new CancellableOrder());
        self::assertSame(['first'], $log);
    }

    public function testIsPropagationStopped(): void
    {
        $event = new CancellableOrder();
        self::assertFalse($event->isPropagationStopped());
        $event->stopPropagation();
        self::assertTrue($event->isPropagationStopped());
    }

    // -----------------------------------------------------------------
    // Subscriber
    // -----------------------------------------------------------------

    public function testSubscriberRegistration(): void
    {
        $dispatcher  = new EventDispatcher();
        $subscriber  = new OrderSubscriber();
        $dispatcher->subscribe($subscriber);

        $dispatcher->dispatch(new OrderPlaced(55));
        $dispatcher->dispatch(new OrderPlaced(66));

        self::assertSame([55, 66], $subscriber->handled);
    }

    public function testSubscribeThrowsWhenMethodMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new EventDispatcher())->subscribe(new \stdClass());
    }

    // -----------------------------------------------------------------
    // Fluent listen chaining
    // -----------------------------------------------------------------

    public function testListenReturnsDispatcher(): void
    {
        $dispatcher = new EventDispatcher();
        $result     = $dispatcher->listen(OrderPlaced::class, fn() => null);
        self::assertSame($dispatcher, $result);
    }

    // -----------------------------------------------------------------
    // Custom provider
    // -----------------------------------------------------------------

    public function testCustomProviderInjection(): void
    {
        $provider   = new ListenerProvider();
        $log        = [];
        $provider->addListener(OrderPlaced::class, function () use (&$log) { $log[] = 'custom'; });

        $dispatcher = new EventDispatcher($provider);
        $dispatcher->dispatch(new OrderPlaced(1));
        self::assertSame(['custom'], $log);
    }

    public function testGetProvider(): void
    {
        $provider   = new ListenerProvider();
        $dispatcher = new EventDispatcher($provider);
        self::assertSame($provider, $dispatcher->getProvider());
    }
}
