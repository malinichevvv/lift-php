---
layout: page
title: Події
nav_order: 28
---

# Події

`Lift\Events\EventDispatcher` — це диспетчер за **PSR-14** — шина «публікація/підписка» для внутрішньопроцесних доменних подій. Код, який робить щось цікаве (користувач реєструється, розміщено замовлення), емітить подію; один або кілька слухачів реагують на неї, при цьому емітент не знає, хто слухає.

> Ментальна модель: події дозволяють **розв’язати** «що сталося» і «що має статися через це». Обробник реєстрації не має знати про вітальні листи, аналітику, аудит-логи — він просто емітить `UserRegistered($user)`, а слухачі роблять роботу.

## Коли використовувати події

- **Побічні ефекти, які не змінюють результат вихідної дії.** Вітальні листи, пінги аналітики, рядки аудит-сліду.
- **Дозволити модулям спілкуватися один з одним, не залежачи один від одного.** Ваш модуль `Order` емітить `OrderPlaced`; модуль `Stock` зменшує запас, `Email` надсилає чек, `Analytics` відстежує конверсію — жоден із них не імпортує інші.
- **Хуки для тестів.** Слухайте `ModelCreated` у тестах, щоб стверджувати «створено рівно одного користувача».

Коли **не** використовувати події:

- Для двосторонньої комунікації (запит/відповідь). Використовуйте прямі виклики методів.
- Для потоку даних, критичного для HTTP-відповіді користувачу (запит повернеться до того, як асинхронні слухачі завершаться — якщо тільки ви не зробите слухачів синхронними, але тоді це просто виклики функцій із зайвими кроками).
- Для заміни черги. Події внутрішньопроцесні, виконуються синхронно й не персистентні. Якщо потрібні гарантії доставки, використовуйте [Черги](queues).

## Приклад за 30 секунд

```php
use Lift\Events\EventDispatcher;

final class UserRegistered
{
    public function __construct(public readonly int $userId, public readonly string $email) {}
}

$events = new EventDispatcher();

// Зареєструвати слухача
$events->listen(UserRegistered::class, function (UserRegistered $e) {
    error_log("New user: {$e->email}");
});

// Емітувати його
$events->dispatch(new UserRegistered(42, 'a@example.com'));
```

Виклик `dispatch()`:

1. Обходить усіх зареєстрованих слухачів, що збігаються з класом події **або будь-яким батьком / інтерфейсом**.
2. Викликає їх у порядку реєстрації, кожного з об’єктом події.
3. Повертає ту саму подію (зручно для плавного коду).

## Під’єднання в Lift

`App` уже конструює й реєструє `EventDispatcher` за вас:

```php
$events = $app->events();          // Lift\Events\EventDispatcher
```

Реєструйте слухачів під час завантаження, зазвичай у `public/index.php` або файлі початкового завантаження:

```php
$app->events()
    ->listen(UserRegistered::class, [EmailService::class, 'sendWelcome'])
    ->listen(UserRegistered::class, [AuditService::class, 'logSignup']);
```

Форма callable `[Class::class, 'method']` дозволяє контейнеру розв’язати залежності — `EmailService` і `AuditService` створюються із впровадженими залежностями конструктора.

## Визначення подій

Подія — це **будь-який об’єкт**. Жодного інтерфейсу для реалізації (якщо тільки вам не потрібне перерване поширення, див. нижче). Більшість — крихітні незмінні класи даних:

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

Властивості лише для читання `public readonly` роблять їх простими й безпечними для розділення між слухачами.

## Форми слухачів

```php
// Замикання
$events->listen(OrderPlaced::class, function (OrderPlaced $e) { … });

// [Class, 'method'] — розв’язується контейнером
$events->listen(OrderPlaced::class, [BillingService::class, 'charge']);

// [$instance, 'method'] — готовий
$events->listen(OrderPlaced::class, [$billing, 'charge']);

// Invokable-клас
$events->listen(OrderPlaced::class, new ChargeListener());
```

Слухач нічого не повертає. Викинутий виняток поширюється з `dispatch()` — загорніть його у `try/catch` вище по стеку, якщо один слухач не має ламати ланцюжок.

## Об’єкти-підписники — багато слухачів на клас

Для модулів, що реєструють десятки слухачів, згрупуйте їх у *підписника*:

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

// Один виклик реєструє їх усі:
$app->events()->subscribe($app->make(OrderSubscriber::class));
```

`subscribe()` вимагає статичний метод `getSubscribedEvents(): array<class-string, string>` на підписнику — значення це імена методів. Lift під’єднує кожен через `[$subscriber, $method]`.

## Спадкування та інтерфейси

Слухачі, зареєстровані на **батьківському класі** або **інтерфейсі**, отримують кожну подію цього типу:

```php
interface DomainEvent {}

final class OrderPlaced implements DomainEvent { /* … */ }
final class UserBanned  implements DomainEvent { /* … */ }

$events->listen(DomainEvent::class, function (DomainEvent $e) {
    AuditLog::write($e);                  // спрацьовує для обох подій вище
});

$events->listen(OrderPlaced::class, function (OrderPlaced $e) { /* лише це */ });
```

Саме так [модель бази даних](database#model-lifecycle-events) під’єднує `ModelCreating` один раз і отримує сповіщення для кожної моделі.

## Перервані події

Іноді слухач має **перервати** ланцюжок — наприклад, провалена перевірка прав. Успадкуйте `StoppableEvent`:

```php
use Lift\Events\StoppableEvent;

final class BeforeOrderPlaced extends StoppableEvent
{
    public function __construct(public readonly array $payload) {}
    public ?string $reason = null;
}

// Слухач
$events->listen(BeforeOrderPlaced::class, function (BeforeOrderPlaced $e) use ($limits) {
    if ($e->payload['total'] > $limits->dailyMax) {
        $e->reason = 'Over daily limit';
        $e->stopPropagation();          // решта слухачів пропускаються
    }
});

// Емітент
$event = $events->dispatch(new BeforeOrderPlaced(['total' => 99]));
if ($event->isPropagationStopped()) {
    return Response::json(['error' => $event->reason], 422);
}
```

`StoppableEvent` реалізує `StoppableEventInterface` із PSR-14. Будь-який слухач може замкнути ланцюжок.

## Вбудовані події

Lift емітить кілька подій рівня фреймворку, які можна під’єднати:

| Подія                                        | Коли                            | Перервна? |
|----------------------------------------------|---------------------------------|:---------:|
| `Lift\Database\Events\ModelCreating`         | перед вставкою                  | ✅ — скасовує збереження |
| `Lift\Database\Events\ModelCreated`          | після вставки                   | ❌        |
| `Lift\Database\Events\ModelUpdating`         | перед оновленням                | ✅        |
| `Lift\Database\Events\ModelUpdated`          | після оновлення                 | ❌        |
| `Lift\Database\Events\ModelDeleting`         | перед видаленням (вкл. м’яке)   | ✅        |
| `Lift\Database\Events\ModelDeleted`          | після видалення                 | ❌        |

Під’єднайте їх один раз під час завантаження, щоб отримати наскрізну поведінку:

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

Тепер кожна збережувана модель автоматично отримує призначений UUID.

## Патерни

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

### Надішліть задачу, не виконуйте її

Не робіть повільну роботу в слухачі — помістіть задачу в чергу:

```php
$events->listen(UserRegistered::class, function (UserRegistered $e) use ($queue) {
    $queue->push(new SendWelcomeEmail($e->email));
});
```

Обробник повертається швидко; воркер надсилає лист пізніше.

### Лінивий слухач

Якщо конструювання слухача дороге (запити до БД, важкі сервіси), загорніть реєстрацію в замикання, що робить розв’язання ліниво:

```php
$events->listen(OrderPlaced::class, function (OrderPlaced $e) use ($app) {
    $app->make(BillingService::class)->charge($e);   // будується лише за спрацювання
});
```

### Розв’язання модулів

Кожен модуль підписується на події, які йому важливі; жодних прямих імпортів:

```
src/
├── Order/       (емітить OrderPlaced)
├── Stock/       (слухає OrderPlaced → зменшити)
├── Email/       (слухає OrderPlaced → чек)
└── Analytics/   (слухає OrderPlaced → метрика)
```

Результат: видалення модуля Analytics змінює нуль рядків у Order/Stock/Email.

## Тестування

Диспетчер — це просто клас — створіть його в тесті, слухайте + стверджуйте:

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

Для юніт-тестів слухачів побудуйте подію й викличте слухача напряму — диспетчер не потрібен.

## Продуктивність

- `dispatch()` — це O(L) за кількістю слухачів для класу події плюс його предків. З < 1000 слухачів це невимірне.
- Усі слухачі виконуються **синхронно в тому самому процесі**. Черги подій немає. Для асинхронності помістіть [задачу в чергу](queues) зі слухача.
- Порядок слухачів — **порядок реєстрації** в межах даного класу події. Порядок між слухачами батька/інтерфейсу слідує реєстрації типу, на якому вони були зареєстровані.

## Часті підводні камені

| Симптом | Причина | Виправлення |
|---|---|---|
| Слухач ніколи не виконується | Зареєстрований на невірному класі (друкарська помилка, простір імен) | Використовуйте константи `::class`, не рядки. |
| `dispatch()` викидає виняток зі слухача | Один слухач викинув виняток; наступні не виконалися | Загорніть слухачів у власний try/catch, якщо хочете ізоляцію. |
| Слухачі виконуються на застарілому запиті після переробки PHP-FPM | Проблема життєвого циклу застосунку, не Lift | Реєструйте слухачів на кожному запиті (у початковому завантаженні), не в `static`-кеші. |
| `Cannot resolve parameter $foo` за спрацювання слухача | Конструктору `[Class, 'method']` потрібна прив’язка | Спершу `$app->bind(Foo::class, …)`. |
| Порядок подій важливий між модулями | Слухачі зареєстровані в різному порядку завантаження | Зробіть порядок явним — реєструйте критичних слухачів першими. |
| `getSubscribedEvents()` підписника не підхоплюється | Він має бути **статичним** | `public static function getSubscribedEvents(): array`. |

## Шпаргалка

```php
// Визначити
final class OrderPlaced { public function __construct(public readonly int $id) {} }

// Слухати
$events->listen(OrderPlaced::class, fn(OrderPlaced $e) => /* ... */);
$events->listen(OrderPlaced::class, [BillingService::class, 'charge']);

// Підписати (багато слухачів з одного класу)
$events->subscribe($subscriber);   // реалізує статичний getSubscribedEvents()

// Перервне
class Event extends StoppableEvent { … }
$e->stopPropagation();
$e->isPropagationStopped();

// Вбудовані
Model::setEventDispatcher($app->events());
$events->listen(ModelCreating::class, /* … */);
```

[Логування →](logging)
