---
layout: page
title: JSON-ресурсы
nav_order: 17
---

# JSON-ресурсы

**JSON-ресурс** — это тонкий класс, который решает, *какие именно поля модели покидают ваш сервер* и как они выглядят. Контроллеры остаются сосредоточенными на потоке; ресурсы владеют проводным форматом.

Используйте их, когда:

- Форма ответа отличается от строки базы данных (переименовать `created_at` → `createdAt`, опустить `password` и т. д.).
- Та же модель рендерится одинаково во многих местах.
- Вам нужны согласованные конверты коллекций (`{"data": [...]}`).

> Ментальная модель: думайте о ресурсе как об ответе на вопрос *«как выглядит `User` в JSON?»* — объявлено один раз, переиспользуется везде.

## Простейший пример

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

Использование из обработчика:

```php
$app->get('/users/{id:\d+}', function (Request $req) use ($repo) {
    return new UserResource($repo->find((int) $req->param('id')));
});
```

Две вещи, на которые стоит обратить внимание:

1. Обработчик вернул объект ресурса **напрямую** — маршрутизатор вызывает на нём `jsonSerialize()`, так что он автоматически становится JSON.
2. `$this->value('field')` читает из того, что вы обернули (массив, объект `ArrayAccess` или публичное свойство).

Тело ответа:

```json
{ "id": 1, "email": "alice@example.com", "createdAt": "2025-..." }
```

## Что принимает `value()`

`$this->resource` может быть:

| Обёрнутый тип            | Читается через                                    |
|--------------------------|---------------------------------------------------|
| `array`                  | `$resource[$key]`                                 |
| `ArrayAccess`            | `$resource[$key]`                                 |
| `object` (любой класс)   | `$resource->$key`                                 |

Так что один и тот же ресурс работает для строки-массива, `stdClass` из PDO или собственной модели.

```php
new UserResource(['id' => 1, 'email' => '...']);
new UserResource($model);          // любой объект с публичными свойствами
new UserResource(json_decode($json));
```

## Установка кода состояния

```php
$app->post('/users', function (Request $req) use ($repo) {
    $user = $repo->create($req->json());
    return (new UserResource($user))->response(201);
});
```

`->response($status)` возвращает `Lift\Http\Response` с телом JSON и заданным статусом.

## Коллекции

Верните список ресурсов через статический помощник:

```php
$app->get('/users', function () use ($repo) {
    return UserResource::collection($repo->all());
});
```

`collection()` обходит любой `iterable` (массив, генератор, результат запроса) и оборачивает каждый элемент в `new static(...)`. Маршрутизатор сериализует получившийся массив ресурсов в:

```json
[
  { "id": 1, "email": "..." },
  { "id": 2, "email": "..." }
]
```

### Оборачивание в конверт

Многие API предпочитают `{"data": [...]}`. Оберните это явно:

```php
$app->get('/users', function () use ($repo) {
    return Response::json([
        'data' => UserResource::collection($repo->all()),
        'meta' => ['count' => count($repo->all())],
    ]);
});
```

…или постройте собственный подкласс `UserCollection`:

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

(Любой объект, чья возвращаемая обработчиком форма — `array`, становится JSON — Lift не важно, что это не `JsonResource`.)

## Условные поля

Показывайте поля только для админов, но только админам:

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

// Использование:
return new UserResource($user, includeAdminFields: $currentUser->isAdmin());
```

## Вложенные ресурсы

У пользователя есть профиль? Включите его через другой ресурс:

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

Внешний вызов `jsonSerialize()` рекурсивно сериализует каждый вложенный ресурс — каждый из них является `JsonSerializable`.

## Использование базового форматтера дат

Вам быстро захочется согласованного форматирования дат во всех ресурсах. Выделите базу:

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

## Генерация через CLI

```bash
vendor/bin/lift make:resource UserResource
```

Создаёт `src/Http/Resources/UserResource.php` с правильным скелетом. См. [Консоль](console).

## Сравнение с обычными массивами

Для разового `return ['id' => ..., 'email' => ...]` ресурс избыточен. Используйте его, когда у вас **два или более эндпоинтов** рендерят одно и то же, или есть **логика формирования полей**, заслуживающая имени.

## Частые подводные камни

| Симптом | Причина | Исправление |
|---|---|---|
| `Cannot access offset on object` | Обёрнутый ресурс не реализует `ArrayAccess`, но вы индексировали его напрямую | Всегда используйте `$this->value('key')`, никогда `$this->resource['key']`. |
| Отсутствующие поля в выводе | Забыли добавить их в `toArray()` | Редактируйте ресурс, не контроллер. |
| Та же форма, но разные имена полей | Два вызывающих хотят camelCase vs snake_case | Сделайте два ресурса (`UserResource`, `UserApiResource`) — композиция вместо условий. |
| Вложенная модель утекает все столбцы БД | Вы вернули `$this->value('profile')` напрямую | Оберните: `new ProfileResource($this->value('profile'))`. |
| `JsonException: malformed UTF-8` | Обёрнутые данные содержат не-UTF-8 байты (бинарный blob) | Не включайте blob или сначала сделайте `base64_encode`. |

## Шпаргалка

```php
// Определить
final class UserResource extends JsonResource
{
    public function toArray(): array
    {
        return ['id' => $this->value('id'), 'email' => $this->value('email')];
    }
}

// Использовать
return new UserResource($user);                   // → 200 JSON
return (new UserResource($user))->response(201);  // собственный статус
return UserResource::collection($users);          // массив ресурсов

// Чтение из обёрнутого значения
$this->value('field');
$this->value('field', $default);
```

[Валидация →](validation)
