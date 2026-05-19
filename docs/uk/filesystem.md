---
layout: page
title: Файлова система
nav_order: 21
---

# Файлова система

`Lift\Filesystem\FilesystemInterface` — це невелика абстракція над файловим сховищем. Вона має один вбудований драйвер — `LocalFilesystem` — і реєстр кількох дисків (`Storage`), тож можна адресувати кілька коренів з одного місця.

> Ментальна модель: поводьтеся з файловою системою як із впровадженим сервісом. `$fs->put('uploads/a.png', $bytes)` — це той самий виклик, незалежно від того, локальний це диск, S3 чи будь-що інше, що ви під’єднаєте.

## Навіщо це існує

Прямі виклики `file_put_contents(__DIR__ . '/../storage/' . $userInput)`:

1. **Небезпечні** — `../` у `$userInput` може вийти за корінь сховища.
2. **Складно тестувати** — продакшен-шляхи відрізняються від шляхів CI / тестів.
3. **Складно замінити** — перенесення завантажень у S3 означає полювання за кожним `file_put_contents` у кодовій базі.

Інтерфейс виправляє всі три, даючи вам протестований API із захистом від path-traversal, зручним для моків впровадженням і адаптерами під оточення.

## Швидкий старт

```php
use Lift\Filesystem\LocalFilesystem;
use Lift\Filesystem\FilesystemInterface;

$app->singleton(FilesystemInterface::class, fn() => new LocalFilesystem(
    root: __DIR__ . '/../storage/app',     // кожен шлях відносний цього
    publicUrl: '/files',                   // опційно — для помічника url()
));

// У будь-якому сервісі / контролері:
$fs->put('uploads/avatar.png', file_get_contents($tmpFile));
$bytes = $fs->get('uploads/avatar.png');
$url   = $fs->url('uploads/avatar.png');   // '/files/uploads/avatar.png'
$fs->delete('uploads/avatar.png');
```

## Повний інтерфейс

```php
// Читання / запис
$fs->put($path, $contents);
$fs->append($path, $contents);
$fs->get($path);                  // викидає виняток, якщо відсутній
$fs->exists($path);               // bool

// Маніпуляції
$fs->delete($path);               // no-op, якщо відсутній
$fs->copy($source, $destination);
$fs->move($source, $destination);

// Метадані
$fs->size($path);                 // байти (викидає виняток, якщо відсутній)
$fs->lastModified($path);         // unix-мітка (викидає виняток, якщо відсутній)

// Каталоги
$fs->files($directory = '', $recursive = false);   // string[]
$fs->directories($directory = '');                 // string[]
$fs->makeDirectory($path, $mode = 0755);
$fs->deleteDirectory($path);                       // рекурсивно; no-op, якщо відсутній

// Публічний URL
$fs->url($path);                  // ?string (null, якщо в адаптера його немає)
```

Усі шляхи **відносні** налаштованому кореню. Абсолютні шляхи відхиляються з `InvalidArgumentException`. Спроби path-traversal (`../../etc/passwd`) викидають `RuntimeException`.

## Фасад Storage — кілька дисків

Більшість застосунків мають щонайменше два корені сховища: *приватний* для оброблених файлів і *публічний*, що віддається напряму вебсервером.

```php
use Lift\Filesystem\LocalFilesystem;
use Lift\Filesystem\Storage;

$storage = new Storage();
$storage
    ->addDisk('local',   new LocalFilesystem(__DIR__ . '/../storage/app'))
    ->addDisk('public',  new LocalFilesystem(__DIR__ . '/../public/uploads', '/uploads'))
    ->addDisk('reports', new LocalFilesystem(__DIR__ . '/../storage/reports'))
    ->setDefault('local');

// Зареєструйте в контейнері або зберігайте глобально:
Storage::setInstance($storage);
```

Потім будь-де в коді:

```php
Storage::disk()->put('cache/state.json', $json);         // диск за замовчуванням
Storage::disk('public')->put('avatars/1.jpg', $bytes);
Storage::disk('reports')->put('2026-05.csv', $csv);
```

Для чистоти DI впроваджуйте `Storage` замість використання статичного синглтона:

```php
class AvatarService
{
    public function __construct(private readonly Storage $storage) {}
    public function save(string $name, string $bytes): void
    {
        $this->storage->getDisk('public')->put("avatars/{$name}", $bytes);
    }
}
```

## Обробка HTTP-завантажень

```php
$app->post('/avatar', function (Request $req) use ($fs) {
    $file = $req->file('avatar');
    if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
        return Response::json(['error' => 'No file'], 422);
    }

    $name = sprintf('%s.%s',
        bin2hex(random_bytes(8)),
        pathinfo($file->getClientFilename(), PATHINFO_EXTENSION),
    );

    $fs->put("avatars/{$name}", (string) $file->getStream());

    return Response::json([
        'url' => $fs->url("avatars/{$name}"),
    ]);
});
```

Ключові моменти:

- **Ніколи** не довіряйте `getClientFilename()` як збережуваному імені файлу — генеруйте випадкове.
- Валідуйте розширення / MIME-тип перед збереженням. Валідатор може допомогти: `'avatar' => 'required'` — початок; для справжніх перевірок типу використовуйте `mime_content_type()` на завантаженому тимчасовому файлі після завантаження.

## Перелік файлів

```php
foreach ($fs->files('exports') as $absolutePath) {
    // Примітка: повертає АБСОЛЮТНІ шляхи, бо LocalFilesystem не може представити «відносно кореня» універсально
    $name  = basename($absolutePath);
    $size  = filesize($absolutePath);
    // …
}

foreach ($fs->files('exports', recursive: true) as $absolutePath) {
    // Обходить і підкаталоги
}

foreach ($fs->directories('exports') as $absolutePath) {
    // Лише безпосередні імена підпапок
}
```

## Поширені операції

### Атомарний запис (уникайте часткових файлів)

```php
$fs->put("data.json.tmp", $payload);
$fs->move("data.json.tmp", "data.json");   // перейменування атомарне в тій самій файловій системі
```

### Потокова віддача великого завантаження

```php
$app->get('/exports/{file}', function (Request $req) use ($fs) {
    $name = basename($req->param('file'));
    if (!$fs->exists("exports/{$name}")) {
        throw new \Lift\Exception\NotFoundException();
    }

    return (new \Lift\Http\Response())
        ->withHeader('Content-Type', 'application/octet-stream')
        ->withHeader('Content-Disposition', "attachment; filename=\"{$name}\"")
        ->withHeader('Content-Length', (string) $fs->size("exports/{$name}"))
        ->withBody(\Lift\Http\Stream::fromString($fs->get("exports/{$name}")));
});
```

Для *справді* великих файлів використовуйте `Stream::fromFile()`, щоб не завантажувати все в пам’ять.

### Періодичне очищення

```php
foreach ($fs->files('tmp', recursive: true) as $path) {
    if (filemtime($path) < time() - 86400) {
        unlink($path);
    }
}
```

## Власний адаптер (наприклад, S3)

Реалізуйте інтерфейс:

```php
use Lift\Filesystem\FilesystemInterface;

final class S3Filesystem implements FilesystemInterface
{
    public function __construct(
        private readonly \Aws\S3\S3Client $s3,
        private readonly string $bucket,
        private readonly ?string $publicUrl = null,
    ) {}

    public function put(string $path, string $contents): void
    {
        $this->s3->putObject([
            'Bucket' => $this->bucket,
            'Key'    => $path,
            'Body'   => $contents,
        ]);
    }

    // …реалізуйте решту…
}

$storage->addDisk('s3', new S3Filesystem($s3Client, 'my-bucket', 'https://cdn.example.com'));
```

Потім `Storage::disk('s3')->put(...)` — це заміна «на місці» — код застосунку не змінюється.

## Чек-лист безпеки

- ✅ Завжди зберігайте користувацькі завантаження під **виділеним коренем**, ніколи всередині `public/`.
- ✅ Відхиляйте абсолютні шляхи (локальний адаптер уже це робить).
- ✅ Генеруйте імена файлів самі; не виводьте надане клієнтом як збережуване ім’я.
- ✅ Валідуйте розмір файлу **до** читання завантаження (заголовок `Content-Length`) і після (`$file->getSize()`).
- ✅ Валідуйте **фактичний** тип файлу через `mime_content_type()` після завантаження — розширення тривіально підробити.
- ✅ Для зображень проганяйте їх через перекодувальник (наприклад, `intervention/image`), щоб прибрати експлойти.
- ❌ Ніколи не пишіть у `public/` із застосунку, якщо тільки не хочете, щоб це справді віддавалося — файли там доступні будь-кому, хто вгадає URL.

## Часті підводні камені

| Симптом | Причина | Виправлення |
|---|---|---|
| `Absolute paths are not allowed` | Ви передали `/var/www/…/storage/foo` у `put()` | Передавайте відносний шлях — корінь уже налаштовано. |
| `Access denied: path escapes the storage root` | Користувацький ввід містив `../` | Файлова система робить свою роботу — блокуйте завантаження вище по стеку. |
| `Storage root could not be created` | У користувача вебсервера немає прав на запис | `chown -R www-data:www-data storage/` (або еквівалент). |
| `url()` повертає `null` | Ви не передали `publicUrl` у конструктор | Надайте його: `new LocalFilesystem($root, '/uploads')`. |
| Порядок переліку файлів змінюється між системами | Різний порядок обходу файлової системи | Адаптер сортує результати — але не покладайтеся на порядок; сортуйте явно за потреби. |

## Шпаргалка

```php
$fs = new LocalFilesystem(__DIR__ . '/../storage/app', publicUrl: '/files');

$fs->put('a.txt', 'hello');
$fs->append('a.txt', ' world');
$fs->get('a.txt');                 // 'hello world'
$fs->exists('a.txt');              // true
$fs->size('a.txt');                // 11
$fs->lastModified('a.txt');
$fs->copy('a.txt', 'b.txt');
$fs->move('b.txt', 'sub/b.txt');
$fs->delete('sub/b.txt');

$fs->files('sub', recursive: true);
$fs->directories('sub');
$fs->makeDirectory('exports');
$fs->deleteDirectory('exports');
$fs->url('a.txt');                 // '/files/a.txt'

// Кілька дисків
Storage::setInstance(
    (new Storage())
        ->addDisk('local', $local)
        ->addDisk('public', $public)
        ->setDefault('local')
);
Storage::disk()->put(...);
Storage::disk('public')->put(...);
```

[Redis →](redis)
