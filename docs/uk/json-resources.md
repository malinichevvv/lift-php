---
layout: page
title: JSON-ресурси
nav_order: 17
---

# JSON-ресурси

**JSON-ресурс** — це тонкий клас, який вирішує, *які саме поля моделі покидають ваш сервер* і як вони виглядають. Контролери залишаються зосередженими на потоці; ресурси володіють провідним форматом.

Використовуйте їх, коли:

- Форма відповіді відрізняється від рядка бази даних (перейменувати `created_at` → `createdAt`, опустити `password` тощо).
- Та сама модель рендериться однаково в багатьох місцях.
- Вам потрібні узгоджені конверти колекцій (`{"data": [...]}`).

> Ментальна модель: думайте про ресурс як про відповідь на запитання *«як виглядає `User` у JSON?»* — оголошено один раз, повторно використовується всюди.

## Найпростіший приклад

```php
use Lift\Http\JsonResource;

final class UserResource extends JsonResource
{
    public function toArray(): array
    {
        return [
            'id'        => $this->value('id'),
            'email'     => $this->value('email'),
            'createdAt' => $this->value('created_at'),
        ];
    }
}
```

Використання з обробника:

```php
$app->get('/users/{id:\d+}', function (Request $req) use ($repo) {
    return new UserResource($repo->find((int) $req->param('id')));
});
```

Дві речі, на які варто звернути увагу:

1. Обробник повернув об’єкт ресурсу **напряму** — маршрутизатор викликає на ньому `jsonSerialize()`, тож він автоматично стає JSON.
2. `$this->value('field')` читає з того, що ви обгорнули (масив, об’єкт `ArrayAccess` чи публічна властивість).

Тіло відповіді:

```json
{ "id": 1, "email": "alice@example.com", "createdAt": "2025-..." }
```

## Що приймає `value()`

`$this->resource` може бути:

| Обгорнутий тип           | Читається через                                   |
|--------------------------|---------------------------------------------------|
| `array`                  | `$resource[$key]`                                 |
| `ArrayAccess`            | `$resource[$key]`                                 |
| `object` (будь-який клас) | `$resource->$key`                                |

Тож той самий ресурс працює для рядка-масиву, `stdClass` із PDO чи власної моделі.

```php
new UserResource(['id' => 1, 'email' => '...']);
new UserResource($model);          // будь-який об’єкт із публічними властивостями
new UserResource(json_decode($json));
```

## Встановлення коду стану

```php
$app->post('/users', function (Request $req) use ($repo) {
    $user = $repo->create($req->json());
    return (new UserResource($user))->response(201);
});
```

`->response($status)` повертає `Lift\Http\Response` із тілом JSON і заданим статусом.

## Колекції

Поверніть список ресурсів через статичний помічник:

```php
$app->get('/users', function () use ($repo) {
    return UserResource::collection($repo->all());
});
```

`collection()` обходить будь-який `iterable` (масив, генератор, результат запиту) й обгортає кожен елемент у `new static(...)`. Маршрутизатор серіалізує отриманий масив ресурсів у:

```json
[
  { "id": 1, "email": "..." },
  { "id": 2, "email": "..." }
]
```

### Обгортання у конверт

Багато API віддають перевагу `{"data": [...]}`. Обгорніть це явно:

```php
$app->get('/users', function () use ($repo) {
    return Response::json([
        'data' => UserResource::collection($repo->all()),
        'meta' => ['count' => count($repo->all())],
    ]);
});
```

…або побудуйте власний підклас `UserCollection`:

```php
final class UserCollection
{
    public function __construct(private readonly iterable $items) {}

    public function toArray(): array
    {
        return [
            'data' => UserResource::collection($this->items),
            'meta' => ['count' => count((array) $this->items)],
        ];
    }
}

return new UserCollection($repo->all());
```

(Будь-який об’єкт, чия повернена обробником форма — `array`, стає JSON — Lift не важливо, що це не `JsonResource`.)

## Умовні поля

Показуйте поля лише для адмінів, але лише адмінам:

```php
public function __construct(
    protected readonly mixed $resource,
    private readonly bool $includeAdminFields = false,
) {
    parent::__construct($resource);
}

public function toArray(): array
{
    $data = [
        'id'    => $this->value('id'),
        'email' => $this->value('email'),
    ];

    if ($this->includeAdminFields) {
        $data['isStaff']  = (bool) $this->value('is_staff');
        $data['lastIp']   = $this->value('last_login_ip');
    }

    return $data;
}

// Використання:
return new UserResource($user, includeAdminFields: $currentUser->isAdmin());
```

## Вкладені ресурси

У користувача є профіль? Включіть його через інший ресурс:

```php
public function toArray(): array
{
    return [
        'id'      => $this->value('id'),
        'email'   => $this->value('email'),
        'profile' => $this->value('profile') ? new ProfileResource($this->value('profile')) : null,
    ];
}
```

Зовнішній виклик `jsonSerialize()` рекурсивно серіалізує кожен вкладений ресурс — кожен із них є `JsonSerializable`.

## Використання базового форматувальника дат

Вам швидко захочеться узгодженого форматування дат у всіх ресурсах. Виділіть базу:

```php
abstract class BaseResource extends JsonResource
{
    protected function date(string $key): ?string
    {
        $value = $this->value($key);
        if ($value === null) return null;
        $dt = $value instanceof \DateTimeInterface ? $value : new \DateTimeImmutable($value);
        return $dt->format(\DateTimeInterface::ATOM);
    }
}

final class UserResource extends BaseResource
{
    public function toArray(): array {
        return [
            'id'        => $this->value('id'),
            'email'     => $this->value('email'),
            'createdAt' => $this->date('created_at'),
        ];
    }
}
```

## Генерація через CLI

```bash
vendor/bin/lift make:resource UserResource
```

Створює `src/Http/Resources/UserResource.php` із правильним скелетом. Див. [Консоль](console).

## Порівняння зі звичайними масивами

Для разового `return ['id' => ..., 'email' => ...]` ресурс надмірний. Використовуйте його, коли у вас **два або більше ендпоінтів** рендерять те саме, або є **логіка формування полів**, що заслуговує на ім’я.

## Часті підводні камені

| Симптом | Причина | Виправлення |
|---|---|---|
| `Cannot access offset on object` | Обгорнутий ресурс не реалізує `ArrayAccess`, але ви індексували його напряму | Завжди використовуйте `$this->value('key')`, ніколи `$this->resource['key']`. |
| Відсутні поля у виводі | Забули додати їх у `toArray()` | Редагуйте ресурс, не контролер. |
| Та сама форма, але різні імена полів | Два викликачі хочуть camelCase vs snake_case | Зробіть два ресурси (`UserResource`, `UserApiResource`) — композиція замість умов. |
| Вкладена модель витікає всі стовпці БД | Ви повернули `$this->value('profile')` напряму | Обгорніть: `new ProfileResource($this->value('profile'))`. |
| `JsonException: malformed UTF-8` | Обгорнуті дані містять не-UTF-8 байти (бінарний blob) | Не включайте blob або спершу зробіть `base64_encode`. |

## Шпаргалка

```php
// Визначити
final class UserResource extends JsonResource
{
    public function toArray(): array
    {
        return ['id' => $this->value('id'), 'email' => $this->value('email')];
    }
}

// Використати
return new UserResource($user);                   // → 200 JSON
return (new UserResource($user))->response(201);  // власний статус
return UserResource::collection($users);          // масив ресурсів

// Читання з обгорнутого значення
$this->value('field');
$this->value('field', $default);
```

[Валідація →](validation)
