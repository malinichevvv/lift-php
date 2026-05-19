---
layout: page
title: Form requests
nav_order: 16
---

# Form requests

**Form request** — это крошечный класс, который владеет правилами валидации и типизированными аксессорами для одного входящего HTTP-запроса. Когда он построен, контроллер получает *валидированный, типобезопасный* объект вместо сырого `Request`.

Используйте form requests, когда:

- Вы хотите правила валидации **рядом с** маршрутом, к которому они относятся, а не в контроллере.
- Та же форма ввода переиспользуется в нескольких контроллерах.
- Вам нужны типизированные аксессоры (`->string('name')`, `->integer('age')`).
- Вам нужен хук `authorize()` до валидации (например, *«разрешено ли текущему пользователю это делать?»*).

> Ментальная модель: `FormRequest` — это то, что приходит в ваш контроллер **после** того, как валидация уже прошла успешно. Если валидация не прошла, обычная обработка 422 Lift срабатывает до того, как ваш контроллер вообще будет вызван.

## Простейший возможный пример

```php
use Lift\Http\FormRequest;

final class StoreUserRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name'     => 'required|string|min:2|max:255',
            'email'    => 'required|email',
            'age'      => 'integer|min:13',
        ];
    }
}
```

Использование из контроллера:

```php
use Lift\Http\Request;
use Lift\Http\Response;

final class UserController
{
    public function __construct(private readonly UserRepository $users) {}

    public function store(Request $req): Response
    {
        $form = StoreUserRequest::fromRequest($req);

        return Response::json($this->users->create([
            'name'  => $form->string('name'),
            'email' => $form->string('email'),
            'age'   => $form->integer('age', 0),
        ]), 201);
    }
}
```

Если тело не соответствует правилам, `fromRequest()` выбрасывает `Lift\Validation\ValidationException` → Lift возвращает HTTP 422 с картой ошибок. **`store()` вашего контроллера никогда не вызывается.** `try/catch` не нужен.

## Что можно переопределить

`FormRequest` абстрактен; подклассы переопределяют это:

```php
abstract class FormRequest
{
    abstract public function rules(): array;        // обязательно

    public function messages(): array { return []; }
    public function translator(): ?Translator { return null; }
    public function authorize(Request $request): void {}
    public function afterValidation(array $validated, Request $request): void {}
}
```

### `rules()`

Возвращает ту же форму массива, что принимает [`Validator`](validation):

```php
public function rules(): array
{
    return [
        'name'           => 'required|string|min:2',
        'tags'           => 'array',
        'tags.*'         => 'string|distinct',          // каждый элемент в массиве
        'profile.bio'    => 'string|nullable|max:500',  // вложенный
        'profile.dob'    => 'date_format:Y-m-d',
    ];
}
```

### `messages()`

Переопределите сообщение об ошибке по умолчанию для конкретного поля/правила:

```php
public function messages(): array
{
    return [
        'email.required' => 'We need your email to send the welcome link.',
        'email.email'    => 'That doesn\'t look like a valid email address.',
        'required'       => 'This field is required.',  // глобальный запасной вариант
    ];
}
```

Ключи — `'field.rule'` (конкретный) или просто `'rule'` (для всего правила). Конкретный побеждает.

### `translator()`

Верните настроенный [Translator](localization) для локализованных сообщений:

```php
public function __construct(
    Request $request,
    array $validated,
    private readonly Translator $t,
) {
    parent::__construct($request, $validated);
}

public function translator(): ?Translator { return $this->t; }
```

(Для более простого приложения привяжите глобальный `Translator` один раз и пропустите это.)

### `authorize(Request $req)`

Выполняется *до* валидации. Выбросьте исключение, чтобы прервать:

```php
use Lift\Exception\ForbiddenException;

public function authorize(Request $request): void
{
    $user = $request->getAttribute('user');
    if (!$user?->canCreatePosts()) {
        throw new ForbiddenException("You are not allowed to create posts.");
    }
}
```

Исключение становится правильным HTTP 403 через обычный поток [обработки ошибок](errors).

### `afterValidation(array $validated, Request $request)`

Выполняется *после* того, как валидация прошла успешно, до возврата неизменяемого объекта. Используйте для производных данных или межполевых проверок, которые DSL правил не может выразить:

```php
public function afterValidation(array $validated, Request $request): void
{
    if ($validated['start_date'] >= $validated['end_date']) {
        throw new \Lift\Validation\ValidationException([
            'end_date' => ['End date must be after start date.'],
        ]);
    }
}
```

(Или реализуйте собственное правило — см. [Валидацию](validation#custom-rules) — когда логика переиспользуема.)

## Чтение валидированных данных

Встроенные аксессоры:

```php
$form->validated();                // весь валидированный массив
$form->input('key', $default);     // mixed
$form->string('key', '');          // string (приведено)
$form->integer('key', 0);          // int (приведено)
$form->request();                  // исходный объект Request
```

Для bool/float/array и т. д. — читайте из `validated()`:

```php
$age   = (int) $form->validated()['age'];
$tags  = $form->validated()['tags'] ?? [];
```

> Сокращения `string()` / `integer()` намеренно покрывают только два самых частых случая; мы держим класс маленьким. Добавьте собственный помощник в подклассе для `bool()` / `float()`, если переиспользуете их.

## Прямое внедрение form requests

В Lift контроллеры получают **`Request`**, затем вызывают `Form::fromRequest($req)`. Мы намеренно не автоматически внедряем тип form request, потому что:

- Автовнедрение означает, что *некоторые* типы параметров делают валидацию как побочный эффект; другие нет. Эта магия сбивает с толку.
- `fromRequest()` — одна лишняя строка — и *видимая*. Читая контроллер, вы мгновенно видите «это сначала валидирует».

Если хотите контроллеры без шаблонного кода, напишите крошечный базовый метод:

```php
abstract class BaseController
{
    /** @template T of FormRequest @param class-string<T> $cls @return T */
    protected function form(string $cls, Request $req): FormRequest
    {
        return $cls::fromRequest($req);
    }
}

final class UserController extends BaseController
{
    public function store(Request $req): Response
    {
        $form = $this->form(StoreUserRequest::class, $req);
        // …
    }
}
```

## Переиспользование между эндпоинтами

Та же форма годится для `POST` (создание) и `PUT` (полная замена):

```php
$app->post('/users',          [UserController::class, 'store']);
$app->put ('/users/{id:\d+}', [UserController::class, 'update']);

class UserController
{
    public function store(Request $req): Response
    {
        return $this->save(StoreUserRequest::fromRequest($req));
    }
    public function update(Request $req): Response
    {
        return $this->save(StoreUserRequest::fromRequest($req), (int) $req->param('id'));
    }
}
```

Для `PATCH` (частичное обновление) определите отдельную форму с правилами в основном `nullable`.

## Генерация через CLI

Бинарник `lift` генерирует шаблонный код:

```bash
vendor/bin/lift make:request StoreUserRequest
```

Создаёт `src/Http/Requests/StoreUserRequest.php` с правильным скелетом — отредактируйте правила, и готово. См. [Консоль](console).

## Сравнение с сырым `$req->validate(...)`

Оба маршрута проходят через тот же `Validator`. Используйте сырой `validate()`, когда:

- Обработчик разовый (небольшой админ-эндпоинт).
- Вам не нужны типизированные аксессоры.
- Правила слишком тривиальны, чтобы заслуживать класс (1–2 поля, используются в одном месте).

Используйте `FormRequest`, когда:

- Тот же ввод переиспользуется в нескольких контроллерах.
- Вы хотите `authorize()` и `messages()` в одном месте.
- В форме 5+ правил / вложенные массивы.

## Частые подводные камни

| Симптом | Причина | Исправление |
|---|---|---|
| `fromRequest` выбрасывает исключение, хотя тело выглядит правильным | Lift объединяет тело + query + параметры маршрута до валидации; коллизии полей могут затенить реальные значения | Используйте ключ, который не является также параметром маршрута/query, или переименуйте. |
| `string('foo')` возвращает `''` на валидном запросе | Имя поля в `rules()` отличается от того, что вы читаете | Сопоставляйте ключи точно. |
| `authorize()` выполняется, но никогда не блокирует | Вы выбросили обычное `Exception` вместо `HttpException` | Выбросьте `ForbiddenException` (или любой подкласс `HttpException`). |
| Собственные сообщения не применяются | Неверная форма ключа (`required.email` вместо `email.required`) | Формат — `'field.rule'`. |
| Конструктору формы нужны зависимости, но `fromRequest` — `static` | Переопределите конструктор *и* держите `$prototype = new $class($request, []);` довольным | Сделайте дополнительные зависимости необязательными или внедряйте через сеттеры; как вариант, вызывайте собственную фабрику. |

## Шпаргалка

```php
final class StoreUserRequest extends FormRequest
{
    public function rules(): array {
        return ['email' => 'required|email', 'name' => 'required|string'];
    }
    public function messages(): array { return ['email.email' => 'Bad email']; }
    public function authorize(Request $r): void { /* выбросить при отказе */ }
    public function afterValidation(array $data, Request $r): void { /* … */ }
}

// В контроллере:
$form = StoreUserRequest::fromRequest($req);
$email = $form->string('email');
$all   = $form->validated();
```

[JSON-ресурсы →](json-resources)
