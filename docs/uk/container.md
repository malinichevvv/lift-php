---
layout: page
title: DI-контейнер
nav_order: 5
---

# DI-контейнер

Контейнер — це мозок застосунку Lift. Він знає, як **конструювати ваші сервіси**, тож вам ніколи не доводиться писати `new` для чогось, що має залежності. Він також дозволяє підміняти реалізації в тестах, не торкаючись продакшен-коду.

> Ментальна модель: контейнер — це Map<ім’я-класу, фабрика>. Ви запитуєте `get(MyService::class)`, він з’ясовує, що потрібно `MyService`, будує це спершу, потім будує `MyService` і віддає вам. Якщо ви не сказали йому як, він використовує **автозв’язування** — рефлексію конструктора класу.

## Найпростіше можливе застосування

```php
class Mailer
{
    public function __construct(private readonly string $host = 'smtp.example.com') {}
}

class WelcomeService
{
    public function __construct(private readonly Mailer $mailer) {}
}

// Просто запитайте його — контейнер побудує дерево залежностей за вас.
$svc = $app->make(WelcomeService::class);
//   ↑ Lift бачить, що WelcomeService потрібен Mailer.
//     Mailer'у не потрібні інші класи, лише рядок зі значенням за замовчуванням.
//     Lift конструює Mailer, потім WelcomeService(mailer) і повертає його.
```

Вам не потрібно реєструвати жоден із класів. Обидва *конкретні* і мають конструктори, які контейнер може задовольнити → їх обробляє **автозв’язування**.

## Коли потрібно реєструвати клас?

Три ситуації:

| Ситуація                                                  | Що робити                               |
|-----------------------------------------------------------|-----------------------------------------|
| Прив’язка інтерфейс → конкретний клас                     | `$app->bind(I::class, Concrete::class)` |
| Конструктору потрібні значення конфігурації (DSN, секрет тощо) | `$app->bind(X::class, fn() => new X(...))` |
| Екземпляр дорогий — будувати лише раз за запит             | `$app->singleton(X::class, ...)`        |
| У вас уже є побудований екземпляр                          | `$app->instance(X::class, $obj)`        |

## `bind()` — фабрика викликається щоразу

```php
// Інтерфейс → клас
$app->bind(LoggerInterface::class, FileLogger::class);

// Фабрика-замикання (з аргументами)
$app->bind(Mailer::class, fn() => new Mailer(
    host: $_ENV['MAIL_HOST'],
    port: (int) $_ENV['MAIL_PORT'],
));

// Фабрика, що використовує сам контейнер
$app->bind(UserRepository::class, function (Container $c) {
    return new UserRepository($c->get(Database::class));
});
```

Кожен `$app->make(Mailer::class)` запускає фабрику заново, даючи вам свіжий екземпляр.

## `singleton()` — розв’язати раз, повторно використовувати

```php
$app->singleton(Database::class, fn() => new Database($_ENV['DB_DSN']));

// Автозв’язуваний синглтон (без фабрики) — Lift усе одно кешує його
$app->singleton(UserRepository::class);
```

`$app->make(Database::class)` повертає той самий екземпляр на кожен виклик, доки запит не завершиться.

> Синглтон у Lift — **на процес** під час роботи у довгоживучому SAPI (RoadRunner, Swoole, ReactPHP) і **на запит** під PHP-FPM. Не зберігайте стан рівня запиту всередині синглтона.

## `instance()` — уже побудований об’єкт

```php
$config = new Config(['debug' => true]);
$app->instance(Config::class, $config);

$app->make(Config::class) === $config;   // true, завжди
```

Корисно для: конфігів, зібраних під час завантаження, моків у тестах, сторонніх об’єктів, які ви сконструювали поза Lift.

## Автозв’язування — магія у деталях

Для кожного параметра конструктора контейнер:

1. Перевіряє, чи збігається **явне перевизначення** за іменем (`$app->make(X::class, ['port' => 8080])`).
2. Дивиться на **підказку типу** параметра. Якщо це не вбудований клас/інтерфейс:
   - Чи прив’язаний він у контейнері? Використати це.
   - Інакше, чи конкретний клас і його можна створити? Рекурсивно автозв’язати.
3. Якщо тип **nullable**, відкотитися до `null`.
4. Якщо параметр **необов’язковий** (має значення за замовчуванням), використати значення за замовчуванням.
5. Інакше викинути `ContainerException` із точним зазначенням параметра та класу.

Конкретний приклад:

```php
class OrderService
{
    public function __construct(
        private readonly OrderRepository $orders,    // автозв’язаний (або прив’язаний)
        private readonly Mailer $mailer,             // автозв’язаний (або прив’язаний)
        private readonly int $maxItems = 100,        // примітив зі значенням за замовчуванням → 100
    ) {}
}

// Просто працює. Реєстрація не потрібна, якщо тільки OrderRepository не інтерфейс.
$svc = $app->make(OrderService::class);
```

Перевизначити один конкретний параметр у місці виклику:

```php
$svc = $app->make(OrderService::class, ['maxItems' => 50]);
```

> **Примітивні параметри без значення за замовчуванням** фатальні — у контейнера немає способу вгадати `string $dsn`. Або прив’яжіть фабрику, або надайте перевизначення.

## Впровадження в обробниках маршрутів

Вкажіть тип будь-чого, що контейнер може розв’язати, поряд із `Request`:

```php
$app->get('/orders', function (Request $req, OrderService $svc) {
    return $svc->all();
});
```

`Request` доступний **завжди** — Lift впроваджує поточний об’єкт запиту, навіть якщо він не «зареєстрований».

Працює і в методах контролерів:

```php
class OrderController
{
    public function __construct(private readonly OrderService $svc) {}

    public function index(Request $req): array
    {
        return $this->svc->all();
    }
}

$app->get('/orders', [OrderController::class, 'index']);
```

Автозв’язуються і сам клас контролера, **і** параметри методу.

## `make()` — пряме розв’язання

```php
$repo = $app->make(UserRepository::class);

// З іменованими перевизначеннями
$svc  = $app->make(ReportService::class, ['month' => 5]);
```

`make()` — це API найнижчого рівня; під капотом `$app->get(...)`, обробники `[Class::class, 'method']` та розв’язання middleware усі проходять через нього.

## `call()` — викликати будь-який callable з впровадженням

Іноді у вас є наявний callable, і ви просто хочете, щоб контейнер заповнив його параметри:

```php
$container = $app->container();

// Замикання
$result = $container->call(fn(Database $db) => $db->query('SELECT 1'));

// [Class, 'method']
$result = $container->call([ReportGenerator::class, 'monthly'], ['month' => 5]);

// Уже побудований екземпляр
$result = $container->call([$generator, 'monthly'], ['month' => 5]);
```

## `has()` — перевірити, чи розв’язуване щось

```php
$c = $app->container();
$c->has(LoggerInterface::class);    // true, якщо прив’язаний
$c->has(NotRegistered::class);      // true, якщо клас існує і автозв’язуваний; інакше false
```

Корисно у бібліотеках, які хочуть опційно використовувати сервіс, якщо користувач його надав.

## Відповідність PSR-11

`Container` реалізує `Psr\Container\ContainerInterface`. Його можна передати будь-якій PSR-11-сумісній бібліотеці:

```php
$psr11 = $app->container();      // Psr\Container\ContainerInterface
$svc   = $psr11->get(MyThing::class);
```

Він викидає правильні типи винятків PSR-11:

- `Lift\Exception\ContainerNotFoundException` (`Psr\Container\NotFoundExceptionInterface`)
- `Lift\Exception\ContainerException` (`Psr\Container\ContainerExceptionInterface`)

## Циклічні залежності

Якщо `A` залежить від `B`, а `B` залежить від `A`, контейнер виявляє це й викидає:

```
Lift\Exception\ContainerException:
  Circular dependency detected while resolving [App\A]
```

Авторозв’язання немає (ви не можете розірвати цикл, не обравши сторону). Виправлення архітектурне — розірвіть цикл, виділивши третій клас, або використовуючи сетер замість конструктора.

## Заміна сервісів у тестах

```php
$app = new App();

// Справжні прив’язки:
$app->singleton(Mailer::class, fn() => new SmtpMailer($_ENV['MAIL_DSN']));

// У налаштуванні тесту:
$app->instance(Mailer::class, new InMemoryMailer());

$response = $app->handle($request);
```

`instance()` і `bind()` мовчки перезаписують одне одного — перемагає *остання* реєстрація.

## Нотатки про продуктивність

- **Рефлексія кешується** — кожен клас рефлексується рівно один раз на процес (кеш `static`). Під OPcache + персистентним SAPI ви платите вартість рефлексії один раз під час завантаження і більше ніколи.
- **Синглтони** заощаджують роботу конструктора на кожному наступному розв’язанні.
- Контейнер не робить розбору анотацій, не генерує проксі, не компілює. Усе — звичайний рантайм-PHP. Компроміс: трохи повільніше, ніж компільований контейнер на кшталт Symfony, але **нуль кроків збірки**.

Хочете ще швидший старт? Заздалегідь «запаліть» синглтони, які точно будуть зачеплені:

```php
$app->container()->get(Database::class);
$app->container()->get(Logger::class);
```

(Тепер вони побудовані один раз під час завантаження, а не на критичному шляху першого запиту, якому вони потрібні.)

## Часті підводні камені

| Симптом | Причина | Виправлення |
|---|---|---|
| `Cannot resolve parameter $foo of type [App\X]` | `App\X` — інтерфейс без прив’язки | `$app->bind(X::class, ConcreteX::class)`. |
| `Cannot resolve untyped required parameter $dsn` | Конструктор приймає `string` без значення за замовчуванням | Прив’яжіть фабрику: `$app->bind(X::class, fn() => new X(dsn: ...))`. |
| Синглтон бачить старий стан | Ви зберегли в ньому змінний стан (погана практика під FPM) | Перенесіть стан рівня запиту в атрибути Request. |
| Тестовий мок не використовується | Зареєстрований через `bind()` *після* того, як щось уже його розв’язало (наприклад, глобальний middleware у `$app->use()`) | Використовуйте `instance()` до будь-якого розв’язання або `singleton()` (зареєстрований → розв’язується заново). |

## Шпаргалка

```php
// Реєстрація
$app->bind     ($abstract, $concrete|$factory);     // свіжий кожен виклик
$app->singleton($abstract, $concrete|$factory|null); // розв’язати раз
$app->instance ($abstract, $object);                 // готовий

// Розв’язання
$x = $app->make ($abstract, $overrides = []);
$x = $app->container()->get($abstract);             // PSR-11
$ok = $app->container()->has($abstract);

// Викликати callable з впровадженням
$app->container()->call($callable, $overrides = []);

// Більшість застосувань: просто вкажіть тип і дайте Lift розібратися
$app->get('/x', function (Request $req, MyService $svc) { /* … */ });
```

[Middleware →](middleware)
