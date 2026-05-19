---
layout: page
title: События
nav_order: 28
---

# События

`Lift\Events\EventDispatcher` — это диспетчер по **PSR-14** — шина «публикация/подписка» для внутрипроцессных доменных событий. Код, который делает что-то интересное (пользователь регистрируется, размещён заказ), эмитит событие; один или несколько слушателей реагируют на него, при этом эмитент не знает, кто слушает.

> Ментальная модель: события позволяют **развязать** «что произошло» и «что должно произойти из-за этого». Обработчик регистрации не должен знать о приветственных письмах, аналитике, аудит-логах — он просто эмитит `UserRegistered($user)`, а слушатели делают работу.

## Когда использовать события

- **Побочные эффекты, которые не меняют результат исходного действия.** Приветственные письма, пинги аналитики, строки аудит-следа.
- **Позволить модулям общаться друг с другом, не завися друг от друга.** Ваш модуль `Order` эмитит `OrderPlaced`; модуль `Stock` уменьшает запас, `Email` отправляет чек, `Analytics` отслеживает конверсию — ни один из них не импортирует другие.
- **Хуки для тестов.** Слушайте `ModelCreated` в тестах, чтобы утверждать «создан ровно один пользователь».

Когда **не** использовать события:

- Для двусторонней коммуникации (запрос/ответ). Используйте прямые вызовы методов.
- Для потока данных, критичного для HTTP-ответа пользователю (запрос вернётся до того, как асинхронные слушатели завершатся — если только вы не сделаете слушателей синхронными, но тогда это просто вызовы функций с лишними шагами).
- Для замены очереди. События внутрипроцессны, выполняются синхронно и не персистентны. Если нужны гарантии доставки, используйте [Очереди](queues).

## Пример за 30 секунд

```php
use Lift\Events\EventDispatcher;

final class UserRegistered
{
    public function __construct(public readonly int $userId, public readonly string $email) {}
}

$events = new EventDispatcher();

// Зарегистрировать слушателя
$events->listen(UserRegistered::class, function (UserRegistered $e) {
    error_log("New user: {$e->email}");
});

// Эмитировать его
$events->dispatch(new UserRegistered(42, 'a@example.com'));
```

Вызов `dispatch()`:

1. Обходит всех зарегистрированных слушателей, совпадающих с классом события **или любым родителем / интерфейсом**.
2. Вызывает их в порядке регистрации, каждого с объектом события.
3. Возвращает то же событие (удобно для текучего кода).

## Подключение в Lift

`App` уже конструирует и регистрирует `EventDispatcher` за вас:

```php
$events = $app->events();          // Lift\Events\EventDispatcher
```

Регистрируйте слушателей при загрузке, обычно в `public/index.php` или файле начальной загрузки:

```php
$app->events()
    ->listen(UserRegistered::class, [EmailService::class, 'sendWelcome'])
    ->listen(UserRegistered::class, [AuditService::class, 'logSignup']);
```

Форма callable `[Class::class, 'method']` позволяет контейнеру разрешить зависимости — `EmailService` и `AuditService` создаются с внедрёнными зависимостями конструктора.

## Определение событий

Событие — это **любой объект**. Никакого интерфейса для реализации (если только вам не нужно прерываемое распространение, см. ниже). Большинство — крошечные неизменяемые классы данных:

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

Свойства только для чтения `public readonly` делают их простыми и безопасными для разделения между слушателями.

## Формы слушателей

```php
// Замыкание
$events->listen(OrderPlaced::class, function (OrderPlaced $e) { … });

// [Class, 'method'] — разрешается контейнером
$events->listen(OrderPlaced::class, [BillingService::class, 'charge']);

// [$instance, 'method'] — готовый
$events->listen(OrderPlaced::class, [$billing, 'charge']);

// Invokable-класс
$events->listen(OrderPlaced::class, new ChargeListener());
```

Слушатель ничего не возвращает. Выброшенное исключение распространяется из `dispatch()` — оберните его в `try/catch` выше по стеку, если один слушатель не должен ломать цепочку.

## Объекты-подписчики — много слушателей на класс

Для модулей, регистрирующих десятки слушателей, сгруппируйте их в *подписчика*:

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

// Один вызов регистрирует их все:
$app->events()->subscribe($app->make(OrderSubscriber::class));
```

`subscribe()` требует статический метод `getSubscribedEvents(): array<class-string, string>` на подписчике — значения это имена методов. Lift подключает каждый через `[$subscriber, $method]`.

## Наследование и интерфейсы

Слушатели, зарегистрированные на **родительском классе** или **интерфейсе**, получают каждое событие этого типа:

```php
interface DomainEvent {}

final class OrderPlaced implements DomainEvent { /* … */ }
final class UserBanned  implements DomainEvent { /* … */ }

$events->listen(DomainEvent::class, function (DomainEvent $e) {
    AuditLog::write($e);                  // срабатывает для обоих событий выше
});

$events->listen(OrderPlaced::class, function (OrderPlaced $e) { /* только это */ });
```

Именно так [модель базы данных](database#model-lifecycle-events) подключает `ModelCreating` один раз и получает уведомления для каждой модели.

## Прерываемые события

Иногда слушатель должен **прервать** цепочку — например, провалившаяся проверка прав. Унаследуйте `StoppableEvent`:

```php
use Lift\Events\StoppableEvent;

final class BeforeOrderPlaced extends StoppableEvent
{
    public function __construct(public readonly array $payload) {}
    public ?string $reason = null;
}

// Слушатель
$events->listen(BeforeOrderPlaced::class, function (BeforeOrderPlaced $e) use ($limits) {
    if ($e->payload['total'] > $limits->dailyMax) {
        $e->reason = 'Over daily limit';
        $e->stopPropagation();          // оставшиеся слушатели пропускаются
    }
});

// Эмитент
$event = $events->dispatch(new BeforeOrderPlaced(['total' => 99]));
if ($event->isPropagationStopped()) {
    return Response::json(['error' => $event->reason], 422);
}
```

`StoppableEvent` реализует `StoppableEventInterface` из PSR-14. Любой слушатель может замкнуть цепочку.

## Встроенные события

Lift эмитит несколько событий уровня фреймворка, которые можно подключить:

| Событие                                      | Когда                           | Прерываемо? |
|----------------------------------------------|---------------------------------|:-----------:|
| `Lift\Database\Events\ModelCreating`         | перед вставкой                  | ✅ — отменяет сохранение |
| `Lift\Database\Events\ModelCreated`          | после вставки                   | ❌          |
| `Lift\Database\Events\ModelUpdating`         | перед обновлением               | ✅          |
| `Lift\Database\Events\ModelUpdated`          | после обновления                | ❌          |
| `Lift\Database\Events\ModelDeleting`         | перед удалением (вкл. мягкое)   | ✅          |
| `Lift\Database\Events\ModelDeleted`          | после удаления                  | ❌          |

Подключите их один раз при загрузке, чтобы получить сквозное поведение:

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

Теперь каждая сохраняемая модель автоматически получает назначенный UUID.

## Паттерны

### Аудит-лог

```php
$events->listen(DomainEvent::class, function (DomainEvent $e) use ($db) {
    $db->table('audit_log')->insert([
        'event'   => $e::class,
        'payload' => json_encode($e),
        'at'      => date('Y-m-d H:i:s'),
    ]);
});
```

### Отправьте задачу, не выполняйте её

Не делайте медленную работу в слушателе — поместите задачу в очередь:

```php
$events->listen(UserRegistered::class, function (UserRegistered $e) use ($queue) {
    $queue->push(new SendWelcomeEmail($e->email));
});
```

Обработчик возвращается быстро; воркер отправляет письмо позже.

### Ленивый слушатель

Если конструирование слушателя дорого (запросы к БД, тяжёлые сервисы), оберните регистрацию в замыкание, делающее разрешение лениво:

```php
$events->listen(OrderPlaced::class, function (OrderPlaced $e) use ($app) {
    $app->make(BillingService::class)->charge($e);   // строится только при срабатывании
});
```

### Развязка модулей

Каждый модуль подписывается на события, которые ему важны; никаких прямых импортов:

```
src/
├── Order/       (эмитит OrderPlaced)
├── Stock/       (слушает OrderPlaced → уменьшить)
├── Email/       (слушает OrderPlaced → чек)
└── Analytics/   (слушает OrderPlaced → метрика)
```

Результат: удаление модуля Analytics меняет ноль строк в Order/Stock/Email.

## Тестирование

Диспетчер — это просто класс — создайте его в тесте, слушайте + утверждайте:

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

Для юнит-тестов слушателей постройте событие и вызовите слушателя напрямую — диспетчер не нужен.

## Производительность

- `dispatch()` — это O(L) по количеству слушателей для класса события плюс его предков. С < 1000 слушателей это неизмеримо.
- Все слушатели выполняются **синхронно в том же процессе**. Очереди событий нет. Для асинхронности поместите [задачу в очередь](queues) из слушателя.
- Порядок слушателей — **порядок регистрации** в рамках данного класса события. Порядок между слушателями родителя/интерфейса следует регистрации типа, на котором они были зарегистрированы.

## Частые подводные камни

| Симптом | Причина | Исправление |
|---|---|---|
| Слушатель никогда не выполняется | Зарегистрирован на неверном классе (опечатка, пространство имён) | Используйте константы `::class`, не строки. |
| `dispatch()` выбрасывает исключение из слушателя | Один слушатель выбросил исключение; последующие не выполнились | Оберните слушателей в собственный try/catch, если хотите изоляцию. |
| Слушатели выполняются на устаревшем запросе после переработки PHP-FPM | Проблема жизненного цикла приложения, не Lift | Регистрируйте слушателей на каждом запросе (в начальной загрузке), не в `static`-кэше. |
| `Cannot resolve parameter $foo` при срабатывании слушателя | Конструктору `[Class, 'method']` нужна привязка | Сначала `$app->bind(Foo::class, …)`. |
| Порядок событий важен между модулями | Слушатели зарегистрированы в разном порядке загрузки | Сделайте порядок явным — регистрируйте критичных слушателей первыми. |
| `getSubscribedEvents()` подписчика не подхватывается | Он должен быть **статическим** | `public static function getSubscribedEvents(): array`. |

## Шпаргалка

```php
// Определить
final class OrderPlaced { public function __construct(public readonly int $id) {} }

// Слушать
$events->listen(OrderPlaced::class, fn(OrderPlaced $e) => /* ... */);
$events->listen(OrderPlaced::class, [BillingService::class, 'charge']);

// Подписать (много слушателей из одного класса)
$events->subscribe($subscriber);   // реализует статический getSubscribedEvents()

// Прерываемое
class Event extends StoppableEvent { … }
$e->stopPropagation();
$e->isPropagationStopped();

// Встроенные
Model::setEventDispatcher($app->events());
$events->listen(ModelCreating::class, /* … */);
```

[Логирование →](logging)
