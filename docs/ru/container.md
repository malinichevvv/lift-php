---
layout: page
title: DI-контейнер
nav_order: 5
---

# DI-контейнер

Контейнер — это мозг приложения Lift. Он знает, как **конструировать ваши сервисы**, так что вам никогда не приходится писать `new` для чего-либо, имеющего зависимости. Он также позволяет подменять реализации в тестах, не трогая продакшен-код.

> Ментальная модель: контейнер — это Map<имя-класса, фабрика>. Вы запрашиваете `get(MyService::class)`, он выясняет, что нужно `MyService`, строит это сначала, затем строит `MyService` и отдаёт вам. Если вы не сказали ему как, он использует **автосвязывание** — рефлексию конструктора класса.

## Простейшее возможное применение

```php
class Mailer
{
    public function __construct(private readonly string $host = 'smtp.example.com') {}
}

class WelcomeService
{
    public function __construct(private readonly Mailer $mailer) {}
}

// Просто запросите его — контейнер построит дерево зависимостей за вас.
$svc = $app->make(WelcomeService::class);
//   ↑ Lift видит, что WelcomeService нужен Mailer.
//     Mailer'у не нужны другие классы, только строка со значением по умолчанию.
//     Lift конструирует Mailer, затем WelcomeService(mailer) и возвращает его.
```

Вам не нужно регистрировать ни один из классов. Оба *конкретны* и имеют конструкторы, которые контейнер может удовлетворить → их обрабатывает **автосвязывание**.

## Когда нужно регистрировать класс?

Три ситуации:

| Ситуация                                                  | Что делать                              |
|-----------------------------------------------------------|-----------------------------------------|
| Привязка интерфейс → конкретный класс                     | `$app->bind(I::class, Concrete::class)` |
| Конструктору нужны значения конфигурации (DSN, секрет и т. д.) | `$app->bind(X::class, fn() => new X(...))` |
| Экземпляр дорогой — строить только один раз за запрос      | `$app->singleton(X::class, ...)`        |
| У вас уже есть построенный экземпляр                       | `$app->instance(X::class, $obj)`        |

## `bind()` — фабрика вызывается каждый раз

```php
// Интерфейс → класс
$app->bind(LoggerInterface::class, FileLogger::class);

// Фабрика-замыкание (с аргументами)
$app->bind(Mailer::class, fn() => new Mailer(
    host: $_ENV['MAIL_HOST'],
    port: (int) $_ENV['MAIL_PORT'],
));

// Фабрика, использующая сам контейнер
$app->bind(UserRepository::class, function (Container $c) {
    return new UserRepository($c->get(Database::class));
});
```

Каждый `$app->make(Mailer::class)` запускает фабрику заново, давая вам свежий экземпляр.

## `singleton()` — разрешить один раз, переиспользовать

```php
$app->singleton(Database::class, fn() => new Database($_ENV['DB_DSN']));

// Автосвязываемый синглтон (без фабрики) — Lift всё равно кэширует его
$app->singleton(UserRepository::class);
```

`$app->make(Database::class)` возвращает один и тот же экземпляр на каждый вызов, пока запрос не завершится.

> Синглтон в Lift — **на процесс** при работе в долгоживущем SAPI (RoadRunner, Swoole, ReactPHP) и **на запрос** под PHP-FPM. Не храните состояние уровня запроса внутри синглтона.

## `instance()` — уже построенный объект

```php
$config = new Config(['debug' => true]);
$app->instance(Config::class, $config);

$app->make(Config::class) === $config;   // true, всегда
```

Полезно для: конфигов, собранных при загрузке, моков в тестах, сторонних объектов, которые вы сконструировали вне Lift.

## Автосвязывание — магия в деталях

Для каждого параметра конструктора контейнер:

1. Проверяет, совпадает ли **явное переопределение** по имени (`$app->make(X::class, ['port' => 8080])`).
2. Смотрит на **подсказку типа** параметра. Если это не встроенный класс/интерфейс:
   - Привязан ли он в контейнере? Использовать это.
   - Иначе, конкретен ли класс и его можно создать? Рекурсивно автосвязать.
3. Если тип **nullable**, откатиться к `null`.
4. Если параметр **необязателен** (имеет значение по умолчанию), использовать значение по умолчанию.
5. Иначе выбросить `ContainerException` с точным указанием параметра и класса.

Конкретный пример:

```php
class OrderService
{
    public function __construct(
        private readonly OrderRepository $orders,    // автосвязан (или привязан)
        private readonly Mailer $mailer,             // автосвязан (или привязан)
        private readonly int $maxItems = 100,        // примитив со значением по умолчанию → 100
    ) {}
}

// Просто работает. Регистрация не нужна, если только OrderRepository не интерфейс.
$svc = $app->make(OrderService::class);
```

Переопределить один конкретный параметр в месте вызова:

```php
$svc = $app->make(OrderService::class, ['maxItems' => 50]);
```

> **Примитивные параметры без значения по умолчанию** фатальны — у контейнера нет способа угадать `string $dsn`. Либо привяжите фабрику, либо предоставьте переопределение.

## Внедрение в обработчиках маршрутов

Укажите тип чего угодно, что контейнер может разрешить, наряду с `Request`:

```php
$app->get('/orders', function (Request $req, OrderService $svc) {
    return $svc->all();
});
```

`Request` доступен **всегда** — Lift внедряет текущий объект запроса, даже если он не «зарегистрирован».

Работает и в методах контроллеров:

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

Автосвязываются и сам класс контроллера, **и** параметры метода.

## `make()` — прямое разрешение

```php
$repo = $app->make(UserRepository::class);

// С именованными переопределениями
$svc  = $app->make(ReportService::class, ['month' => 5]);
```

`make()` — это API самого низкого уровня; под капотом `$app->get(...)`, обработчики `[Class::class, 'method']` и разрешение middleware все проходят через него.

## `call()` — вызвать любой callable с внедрением

Иногда у вас есть существующий callable и вы просто хотите, чтобы контейнер заполнил его параметры:

```php
$container = $app->container();

// Замыкание
$result = $container->call(fn(Database $db) => $db->query('SELECT 1'));

// [Class, 'method']
$result = $container->call([ReportGenerator::class, 'monthly'], ['month' => 5]);

// Уже построенный экземпляр
$result = $container->call([$generator, 'monthly'], ['month' => 5]);
```

## `has()` — проверить, разрешимо ли что-то

```php
$c = $app->container();
$c->has(LoggerInterface::class);    // true, если привязан
$c->has(NotRegistered::class);      // true, если класс существует и автосвязываем; иначе false
```

Полезно в библиотеках, которые хотят опционально использовать сервис, если пользователь его предоставил.

## Соответствие PSR-11

`Container` реализует `Psr\Container\ContainerInterface`. Его можно передать любой PSR-11-совместимой библиотеке:

```php
$psr11 = $app->container();      // Psr\Container\ContainerInterface
$svc   = $psr11->get(MyThing::class);
```

Он выбрасывает правильные типы исключений PSR-11:

- `Lift\Exception\ContainerNotFoundException` (`Psr\Container\NotFoundExceptionInterface`)
- `Lift\Exception\ContainerException` (`Psr\Container\ContainerExceptionInterface`)

## Циклические зависимости

Если `A` зависит от `B`, а `B` зависит от `A`, контейнер обнаруживает это и выбрасывает:

```
Lift\Exception\ContainerException:
  Circular dependency detected while resolving [App\A]
```

Авторазрешения нет (вы не можете разорвать цикл, не выбрав сторону). Исправление архитектурное — разорвите цикл, выделив третий класс, или используя сеттер вместо конструктора.

## Замена сервисов в тестах

```php
$app = new App();

// Настоящие привязки:
$app->singleton(Mailer::class, fn() => new SmtpMailer($_ENV['MAIL_DSN']));

// В настройке теста:
$app->instance(Mailer::class, new InMemoryMailer());

$response = $app->handle($request);
```

`instance()` и `bind()` молча перезаписывают друг друга — побеждает *последняя* регистрация.

## Заметки о производительности

- **Рефлексия кэшируется** — каждый класс рефлексируется ровно один раз на процесс (кэш `static`). Под OPcache + персистентным SAPI вы платите стоимость рефлексии один раз при загрузке и больше никогда.
- **Синглтоны** экономят работу конструктора на каждом последующем разрешении.
- Контейнер не делает разбора аннотаций, не генерирует прокси, не компилирует. Всё — обычный рантайм-PHP. Компромисс: чуть медленнее, чем компилируемый контейнер вроде Symfony, но **ноль шагов сборки**.

Хотите ещё более быстрый старт? Заранее «зажгите» синглтоны, которые точно будут затронуты:

```php
$app->container()->get(Database::class);
$app->container()->get(Logger::class);
```

(Теперь они построены один раз при загрузке, а не на критическом пути первого запроса, которому они нужны.)

## Частые подводные камни

| Симптом | Причина | Исправление |
|---|---|---|
| `Cannot resolve parameter $foo of type [App\X]` | `App\X` — интерфейс без привязки | `$app->bind(X::class, ConcreteX::class)`. |
| `Cannot resolve untyped required parameter $dsn` | Конструктор принимает `string` без значения по умолчанию | Привяжите фабрику: `$app->bind(X::class, fn() => new X(dsn: ...))`. |
| Синглтон видит старое состояние | Вы сохранили в нём изменяемое состояние (плохая практика под FPM) | Перенесите состояние уровня запроса в атрибуты Request. |
| Тестовый мок не используется | Зарегистрирован через `bind()` *после* того, как что-то уже его разрешило (например, глобальный middleware в `$app->use()`) | Используйте `instance()` до любого разрешения или `singleton()` (зарегистрирован → разрешается заново). |

## Шпаргалка

```php
// Регистрация
$app->bind     ($abstract, $concrete|$factory);     // свежий каждый вызов
$app->singleton($abstract, $concrete|$factory|null); // разрешить один раз
$app->instance ($abstract, $object);                 // готовый

// Разрешение
$x = $app->make ($abstract, $overrides = []);
$x = $app->container()->get($abstract);             // PSR-11
$ok = $app->container()->has($abstract);

// Вызвать callable с внедрением
$app->container()->call($callable, $overrides = []);

// Большинство применений: просто укажите тип и дайте Lift разобраться
$app->get('/x', function (Request $req, MyService $svc) { /* … */ });
```

[Middleware →](middleware)
