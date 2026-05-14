---
layout: page
title: Events
nav_order: 28
---

# Events

`Lift\Events\EventDispatcher` is a **PSR-14** dispatcher — a publish/subscribe bus for in-process domain events. Code that does something interesting (a user signs up, an order is placed) emits an event; one or more listeners react to it without the emitter knowing who's listening.

> Mental model: events let you **decouple** "what happened" from "what should happen because of it". The signup handler doesn't need to know about welcome emails, analytics, audit logs — it just fires `UserRegistered($user)` and the listeners do the work.

## When to use events

- **Side effects that don't change the outcome of the original action.** Welcome emails, analytics pings, audit trail rows.
- **Letting modules talk to each other without depending on each other.** Your `Order` module fires `OrderPlaced`; the `Stock` module decrements inventory, `Email` sends a receipt, `Analytics` tracks the conversion — none of them imports the others.
- **Hooks for tests.** Listen to `ModelCreated` in tests to assert "exactly one user was created".

When **not** to use events:

- For two-way communication (a request/response). Use direct method calls.
- For data flow critical to the user's HTTP response (the request will return before async listeners finish — unless you make listeners synchronous, but then they're just function calls with extra steps).
- For replacing a queue. Events are in-process, run synchronously, and don't persist. If you need delivery guarantees, use [Queues](queues).

## 30-second example

```php
use Lift\Events\EventDispatcher;

final class UserRegistered
{
    public function __construct(public readonly int $userId, public readonly string $email) {}
}

$events = new EventDispatcher();

// Register a listener
$events->listen(UserRegistered::class, function (UserRegistered $e) {
    error_log("New user: {$e->email}");
});

// Fire it
$events->dispatch(new UserRegistered(42, 'a@example.com'));
```

The `dispatch()` call:

1. Walks all registered listeners that match the event's class **or any parent / interface**.
2. Calls them in registration order, each with the event object.
3. Returns the same event (handy for fluent code).

## Wiring into Lift

`App` already constructs and registers an `EventDispatcher` for you:

```php
$events = $app->events();          // Lift\Events\EventDispatcher
```

Register listeners at boot, typically in `public/index.php` or a bootstrap file:

```php
$app->events()
    ->listen(UserRegistered::class, [EmailService::class, 'sendWelcome'])
    ->listen(UserRegistered::class, [AuditService::class, 'logSignup']);
```

`[Class::class, 'method']` callable form lets the container resolve dependencies — `EmailService` and `AuditService` are instantiated with their constructor deps injected.

## Defining events

An event is **any object**. No interface to implement (unless you want stoppable propagation, see below). Most are tiny immutable data classes:

```php
final class OrderPlaced
{
    public function __construct(
        public readonly int $orderId,
        public readonly int $userId,
        public readonly float $total,
    ) {}
}
```

Read-only `public readonly` properties keep them simple and safe to share between listeners.

## Listener forms

```php
// Closure
$events->listen(OrderPlaced::class, function (OrderPlaced $e) { … });

// [Class, 'method'] — container-resolved
$events->listen(OrderPlaced::class, [BillingService::class, 'charge']);

// [$instance, 'method'] — pre-built
$events->listen(OrderPlaced::class, [$billing, 'charge']);

// Invokable class
$events->listen(OrderPlaced::class, new ChargeListener());
```

A listener returns nothing. Throwing propagates out of `dispatch()` — wrap it in `try/catch` upstream if a single listener mustn't break the chain.

## Subscriber objects — many listeners per class

For modules that register dozens of listeners, group them into a *subscriber*:

```php
final class OrderSubscriber
{
    public function __construct(private readonly Mailer $mailer) {}

    public static function getSubscribedEvents(): array
    {
        return [
            OrderPlaced::class    => 'onOrderPlaced',
            OrderCancelled::class => 'onOrderCancelled',
            OrderShipped::class   => 'onOrderShipped',
        ];
    }

    public function onOrderPlaced(OrderPlaced $e): void    { … }
    public function onOrderCancelled(OrderCancelled $e): void { … }
    public function onOrderShipped(OrderShipped $e): void  { … }
}

// One call registers all of them:
$app->events()->subscribe($app->make(OrderSubscriber::class));
```

`subscribe()` requires a static `getSubscribedEvents(): array<class-string, string>` method on the subscriber — values are method names. Lift wires each one up via `[$subscriber, $method]`.

## Inheritance & interfaces

Listeners registered on a **parent class** or **interface** receive every event of that type:

```php
interface DomainEvent {}

final class OrderPlaced implements DomainEvent { /* … */ }
final class UserBanned  implements DomainEvent { /* … */ }

$events->listen(DomainEvent::class, function (DomainEvent $e) {
    AuditLog::write($e);                  // fires for both events above
});

$events->listen(OrderPlaced::class, function (OrderPlaced $e) { /* only this one */ });
```

This is how the [Database model](database#model-lifecycle-events) hooks `ModelCreating` once and gets notifications for every model.

## Stoppable events

Sometimes a listener should **abort** the chain — e.g. a permission check that fails. Subclass `StoppableEvent`:

```php
use Lift\Events\StoppableEvent;

final class BeforeOrderPlaced extends StoppableEvent
{
    public function __construct(public readonly array $payload) {}
    public ?string $reason = null;
}

// Listener
$events->listen(BeforeOrderPlaced::class, function (BeforeOrderPlaced $e) use ($limits) {
    if ($e->payload['total'] > $limits->dailyMax) {
        $e->reason = 'Over daily limit';
        $e->stopPropagation();          // remaining listeners are skipped
    }
});

// Emitter
$event = $events->dispatch(new BeforeOrderPlaced(['total' => 99]));
if ($event->isPropagationStopped()) {
    return Response::json(['error' => $event->reason], 422);
}
```

`StoppableEvent` implements PSR-14's `StoppableEventInterface`. Any listener can short-circuit the chain.

## Built-in events

Lift fires a few framework-level events you can hook:

| Event                                        | When                            | Stoppable? |
|----------------------------------------------|---------------------------------|:----------:|
| `Lift\Database\Events\ModelCreating`         | before insert                   | ✅ — cancels the save |
| `Lift\Database\Events\ModelCreated`          | after insert                    | ❌         |
| `Lift\Database\Events\ModelUpdating`         | before update                   | ✅         |
| `Lift\Database\Events\ModelUpdated`          | after update                    | ❌         |
| `Lift\Database\Events\ModelDeleting`         | before delete (incl. soft)      | ✅         |
| `Lift\Database\Events\ModelDeleted`          | after delete                    | ❌         |

Hook them once at boot to get cross-cutting behaviour:

```php
use Lift\Database\Events\ModelCreating;
use Lift\Database\Model;
use Lift\Support\Uuid;

Model::setEventDispatcher($app->events());

$app->events()->listen(ModelCreating::class, function (ModelCreating $e) {
    if ($e->model->get('uuid') === null) {
        $e->model->set('uuid', Uuid::v7());
    }
});
```

Every model that gets saved now has a UUID assigned automatically.

## Patterns

### Audit log

```php
$events->listen(DomainEvent::class, function (DomainEvent $e) use ($db) {
    $db->table('audit_log')->insert([
        'event'   => $e::class,
        'payload' => json_encode($e),
        'at'      => date('Y-m-d H:i:s'),
    ]);
});
```

### Send a job, don't run it

Don't do the slow work in a listener — push a queue job:

```php
$events->listen(UserRegistered::class, function (UserRegistered $e) use ($queue) {
    $queue->push(new SendWelcomeEmail($e->email));
});
```

The handler returns fast; the worker sends the email later.

### Lazy listener

If constructing the listener is expensive (DB queries, heavy services), wrap the registration in a closure that does the resolve lazily:

```php
$events->listen(OrderPlaced::class, function (OrderPlaced $e) use ($app) {
    $app->make(BillingService::class)->charge($e);   // built only when fired
});
```

### Cross-module decoupling

Each module subscribes to the events it cares about; no direct imports:

```
src/
├── Order/       (fires OrderPlaced)
├── Stock/       (listens for OrderPlaced → decrement)
├── Email/       (listens for OrderPlaced → receipt)
└── Analytics/   (listens for OrderPlaced → metric)
```

Result: deleting the Analytics module changes zero lines in Order/Stock/Email.

## Testing

The dispatcher is just a class — instantiate it in your test, listen + assert:

```php
public function testSignupFiresEvent(): void
{
    $fired = [];
    $this->app->events()->listen(UserRegistered::class, function (UserRegistered $e) use (&$fired) {
        $fired[] = $e;
    });

    $this->post('/signup', ['email' => 'a@b.c', 'password' => 'hunter2hunter2'])
         ->assertCreated();

    self::assertCount(1, $fired);
    self::assertSame('a@b.c', $fired[0]->email);
}
```

For unit tests of listeners, build the event and call the listener directly — no dispatcher needed.

## Performance

- `dispatch()` is O(L) over the listener count for the event class plus its ancestors. With < 1000 listeners this is unmeasurable.
- All listeners run **synchronously in the same process**. There's no event queue. For async, push a [queue job](queues) from a listener.
- Listener order is **registration order** within a given event class. Order across parent/interface listeners follows the registration of the type they were registered on.

## Common pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| Listener never runs | Registered on wrong class (typo, namespace) | Use `::class` constants, not strings. |
| `dispatch()` throws from a listener | One listener threw; subsequent ones didn't run | Wrap listeners in their own try/catch if you want isolation. |
| Listeners run on a stale request after PHP-FPM recycles | App lifecycle issue, not Lift's | Register listeners on every request (in your bootstrap), not in a `static` cache. |
| `Cannot resolve parameter $foo` when listener fires | `[Class, 'method']` constructor needs a binding | `$app->bind(Foo::class, …)` first. |
| Event order matters between modules | Listeners registered in different boot orders | Make order explicit — register critical listeners first. |
| Subscriber's `getSubscribedEvents()` not picked up | It must be **static** | `public static function getSubscribedEvents(): array`. |

## Cheat sheet

```php
// Define
final class OrderPlaced { public function __construct(public readonly int $id) {} }

// Listen
$events->listen(OrderPlaced::class, fn(OrderPlaced $e) => /* ... */);
$events->listen(OrderPlaced::class, [BillingService::class, 'charge']);

// Subscribe (many listeners from one class)
$events->subscribe($subscriber);   // implements static getSubscribedEvents()

// Stoppable
class Event extends StoppableEvent { … }
$e->stopPropagation();
$e->isPropagationStopped();

// Built-in
Model::setEventDispatcher($app->events());
$events->listen(ModelCreating::class, /* … */);
```

[Logging →](logging)
