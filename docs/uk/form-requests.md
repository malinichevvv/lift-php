---
layout: page
title: Form requests
nav_order: 16
---

# Form requests

**Form request** — це крихітний клас, який володіє правилами валідації та типізованими аксесорами для одного вхідного HTTP-запиту. Коли він побудований, контролер отримує *валідований, типобезпечний* об’єкт замість сирого `Request`.

Використовуйте form requests, коли:

- Ви хочете правила валідації **поряд із** маршрутом, до якого вони належать, а не в контролері.
- Та сама форма вводу повторно використовується в кількох контролерах.
- Вам потрібні типізовані аксесори (`->string('name')`, `->integer('age')`).
- Вам потрібен хук `authorize()` до валідації (наприклад, *«чи дозволено поточному користувачу це робити?»*).

> Ментальна модель: `FormRequest` — це те, що приходить у ваш контролер **після** того, як валідація вже пройшла успішно. Якщо валідація не пройшла, звичайна обробка 422 Lift спрацьовує до того, як ваш контролер узагалі буде викликано.

## Найпростіший можливий приклад

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

Використання з контролера:

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

Якщо тіло не відповідає правилам, `fromRequest()` викидає `Lift\Validation\ValidationException` → Lift повертає HTTP 422 з картою помилок. **`store()` вашого контролера ніколи не викликається.** `try/catch` не потрібен.

## Що можна перевизначити

`FormRequest` абстрактний; підкласи перевизначають це:

```php
abstract class FormRequest
{
    abstract public function rules(): array;        // обов’язково

    public function messages(): array { return []; }
    public function translator(): ?Translator { return null; }
    public function authorize(Request $request): void {}
    public function afterValidation(array $validated, Request $request): void {}
}
```

### `rules()`

Повертає ту саму форму масиву, що приймає [`Validator`](validation):

```php
public function rules(): array
{
    return [
        'name'           => 'required|string|min:2',
        'tags'           => 'array',
        'tags.*'         => 'string|distinct',          // кожен елемент у масиві
        'profile.bio'    => 'string|nullable|max:500',  // вкладений
        'profile.dob'    => 'date_format:Y-m-d',
    ];
}
```

### `messages()`

Перевизначте повідомлення про помилку за замовчуванням для конкретного поля/правила:

```php
public function messages(): array
{
    return [
        'email.required' => 'We need your email to send the welcome link.',
        'email.email'    => 'That doesn\'t look like a valid email address.',
        'required'       => 'This field is required.',  // глобальний запасний варіант
    ];
}
```

Ключі — `'field.rule'` (конкретний) або просто `'rule'` (для всього правила). Конкретний перемагає.

### `translator()`

Поверніть налаштований [Translator](localization) для локалізованих повідомлень:

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

(Для простішого застосунку прив’яжіть глобальний `Translator` один раз і пропустіть це.)

### `authorize(Request $req)`

Виконується *до* валідації. Викиньте виняток, щоб перервати:

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

Виняток стає правильним HTTP 403 через звичайний потік [обробки помилок](errors).

### `afterValidation(array $validated, Request $request)`

Виконується *після* того, як валідація пройшла успішно, до повернення незмінного об’єкта. Використовуйте для похідних даних або міжполевих перевірок, які DSL правил не може виразити:

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

(Або реалізуйте власне правило — див. [Валідацію](validation#custom-rules) — коли логіка повторно використовувана.)

## Читання валідованих даних

Вбудовані аксесори:

```php
$form->validated();                // увесь валідований масив
$form->input('key', $default);     // mixed
$form->string('key', '');          // string (приведено)
$form->integer('key', 0);          // int (приведено)
$form->request();                  // вихідний об’єкт Request
```

Для bool/float/array тощо — читайте з `validated()`:

```php
$age   = (int) $form->validated()['age'];
$tags  = $form->validated()['tags'] ?? [];
```

> Скорочення `string()` / `integer()` навмисно покривають лише два найчастіші випадки; ми тримаємо клас маленьким. Додайте власний помічник у підкласі для `bool()` / `float()`, якщо повторно використовуєте їх.

## Пряме впровадження form requests

У Lift контролери отримують **`Request`**, потім викликають `Form::fromRequest($req)`. Ми навмисно не автоматично впроваджуємо тип form request, бо:

- Автовпровадження означає, що *деякі* типи параметрів роблять валідацію як побічний ефект; інші ні. Ця магія збиває з пантелику.
- `fromRequest()` — один зайвий рядок — і *видимий*. Читаючи контролер, ви миттєво бачите «це спершу валідує».

Якщо хочете контролери без шаблонного коду, напишіть крихітний базовий метод:

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

## Повторне використання між ендпоінтами

Та сама форма годиться для `POST` (створення) і `PUT` (повна заміна):

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

Для `PATCH` (часткове оновлення) визначте окрему форму з правилами здебільшого `nullable`.

## Генерація через CLI

Бінарник `lift` генерує шаблонний код:

```bash
vendor/bin/lift make:request StoreUserRequest
```

Створює `src/Http/Requests/StoreUserRequest.php` із правильним скелетом — відредагуйте правила, і готово. Див. [Консоль](console).

## Порівняння із сирим `$req->validate(...)`

Обидва маршрути проходять через той самий `Validator`. Використовуйте сирий `validate()`, коли:

- Обробник разовий (невеликий адмін-ендпоінт).
- Вам не потрібні типізовані аксесори.
- Правила надто тривіальні, щоб заслуговувати на клас (1–2 поля, використовуються в одному місці).

Використовуйте `FormRequest`, коли:

- Той самий ввід повторно використовується в кількох контролерах.
- Ви хочете `authorize()` і `messages()` в одному місці.
- У формі 5+ правил / вкладені масиви.

## Часті підводні камені

| Симптом | Причина | Виправлення |
|---|---|---|
| `fromRequest` викидає виняток, хоча тіло виглядає правильним | Lift об’єднує тіло + query + параметри маршруту до валідації; колізії полів можуть затінити реальні значення | Використовуйте ключ, який не є також параметром маршруту/query, або перейменуйте. |
| `string('foo')` повертає `''` на валідному запиті | Ім’я поля в `rules()` відрізняється від того, що ви читаєте | Зіставляйте ключі точно. |
| `authorize()` виконується, але ніколи не блокує | Ви викинули звичайний `Exception` замість `HttpException` | Викиньте `ForbiddenException` (або будь-який підклас `HttpException`). |
| Власні повідомлення не застосовуються | Невірна форма ключа (`required.email` замість `email.required`) | Формат — `'field.rule'`. |
| Конструктору форми потрібні залежності, але `fromRequest` — `static` | Перевизначте конструктор *і* тримайте `$prototype = new $class($request, []);` задоволеним | Зробіть додаткові залежності необов’язковими або впроваджуйте через сетери; як варіант, викликайте власну фабрику. |

## Шпаргалка

```php
final class StoreUserRequest extends FormRequest
{
    public function rules(): array {
        return ['email' => 'required|email', 'name' => 'required|string'];
    }
    public function messages(): array { return ['email.email' => 'Bad email']; }
    public function authorize(Request $r): void { /* викинути за відмови */ }
    public function afterValidation(array $data, Request $r): void { /* … */ }
}

// У контролері:
$form = StoreUserRequest::fromRequest($req);
$email = $form->string('email');
$all   = $form->validated();
```

[JSON-ресурси →](json-resources)
