---
layout: page
title: Файловая система
nav_order: 21
---

# Файловая система

`Lift\Filesystem\FilesystemInterface` — это небольшая абстракция над файловым хранилищем. У неё один встроенный драйвер — `LocalFilesystem` — и реестр нескольких дисков (`Storage`), так что можно адресовать несколько корней из одного места.

> Ментальная модель: обращайтесь с файловой системой как с внедрённым сервисом. `$fs->put('uploads/a.png', $bytes)` — это один и тот же вызов, независимо от того, локальный диск это, S3 или что угодно ещё, что вы подключите.

## Зачем это существует

Прямые вызовы `file_put_contents(__DIR__ . '/../storage/' . $userInput)`:

1. **Небезопасны** — `../` в `$userInput` может выйти за корень хранилища.
2. **Сложно тестировать** — продакшен-пути отличаются от путей CI / тестов.
3. **Сложно заменить** — перенос загрузок в S3 означает охоту за каждым `file_put_contents` в кодовой базе.

Интерфейс исправляет все три, давая вам протестированный API с защитой от path-traversal, удобным для моков внедрением и адаптерами под окружение.

## Быстрый старт

```php
use Lift\Filesystem\LocalFilesystem;
use Lift\Filesystem\FilesystemInterface;

$app->singleton(FilesystemInterface::class, fn() => new LocalFilesystem(
    root: __DIR__ . '/../storage/app',     // каждый путь относителен этого
    publicUrl: '/files',                   // опционально — для помощника url()
));

// В любом сервисе / контроллере:
$fs->put('uploads/avatar.png', file_get_contents($tmpFile));
$bytes = $fs->get('uploads/avatar.png');
$url   = $fs->url('uploads/avatar.png');   // '/files/uploads/avatar.png'
$fs->delete('uploads/avatar.png');
```

## Полный интерфейс

```php
// Чтение / запись
$fs->put($path, $contents);
$fs->append($path, $contents);
$fs->get($path);                  // выбрасывает исключение, если отсутствует
$fs->exists($path);               // bool

// Манипуляции
$fs->delete($path);               // no-op, если отсутствует
$fs->copy($source, $destination);
$fs->move($source, $destination);

// Метаданные
$fs->size($path);                 // байты (выбрасывает исключение, если отсутствует)
$fs->lastModified($path);         // unix-метка (выбрасывает исключение, если отсутствует)

// Каталоги
$fs->files($directory = '', $recursive = false);   // string[]
$fs->directories($directory = '');                 // string[]
$fs->makeDirectory($path, $mode = 0755);
$fs->deleteDirectory($path);                       // рекурсивно; no-op, если отсутствует

// Публичный URL
$fs->url($path);                  // ?string (null, если у адаптера его нет)
```

Все пути **относительны** настроенному корню. Абсолютные пути отклоняются с `InvalidArgumentException`. Попытки path-traversal (`../../etc/passwd`) выбрасывают `RuntimeException`.

## Фасад Storage — несколько дисков

У большинства приложений есть как минимум два корня хранилища: *приватный* для обработанных файлов и *публичный*, отдаваемый напрямую веб-сервером.

```php
use Lift\Filesystem\LocalFilesystem;
use Lift\Filesystem\Storage;

$storage = new Storage();
$storage
    ->addDisk('local',   new LocalFilesystem(__DIR__ . '/../storage/app'))
    ->addDisk('public',  new LocalFilesystem(__DIR__ . '/../public/uploads', '/uploads'))
    ->addDisk('reports', new LocalFilesystem(__DIR__ . '/../storage/reports'))
    ->setDefault('local');

// Зарегистрируйте в контейнере или храните глобально:
Storage::setInstance($storage);
```

Затем где угодно в коде:

```php
Storage::disk()->put('cache/state.json', $json);         // диск по умолчанию
Storage::disk('public')->put('avatars/1.jpg', $bytes);
Storage::disk('reports')->put('2026-05.csv', $csv);
```

Для чистоты DI внедряйте `Storage` вместо использования статического синглтона:

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

## Обработка HTTP-загрузок

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

Ключевые моменты:

- **Никогда** не доверяйте `getClientFilename()` как сохраняемому имени файла — генерируйте случайное.
- Валидируйте расширение / MIME-тип перед сохранением. Валидатор может помочь: `'avatar' => 'required'` — начало; для настоящих проверок типа используйте `mime_content_type()` на загруженном временном файле после загрузки.

## Перечисление файлов

```php
foreach ($fs->files('exports') as $absolutePath) {
    // Примечание: возвращает АБСОЛЮТНЫЕ пути, потому что LocalFilesystem не может представить «относительно корня» универсально
    $name  = basename($absolutePath);
    $size  = filesize($absolutePath);
    // …
}

foreach ($fs->files('exports', recursive: true) as $absolutePath) {
    // Обходит и подкаталоги
}

foreach ($fs->directories('exports') as $absolutePath) {
    // Только непосредственные имена подпапок
}
```

## Распространённые операции

### Атомарная запись (избегайте частичных файлов)

```php
$fs->put("data.json.tmp", $payload);
$fs->move("data.json.tmp", "data.json");   // переименование атомарно в той же файловой системе
```

### Потоковая отдача большой загрузки

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

Для *действительно* больших файлов используйте `Stream::fromFile()`, чтобы не загружать всё в память.

### Периодическая очистка

```php
foreach ($fs->files('tmp', recursive: true) as $path) {
    if (filemtime($path) < time() - 86400) {
        unlink($path);
    }
}
```

## Собственный адаптер (например, S3)

Реализуйте интерфейс:

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

    // …реализуйте остальное…
}

$storage->addDisk('s3', new S3Filesystem($s3Client, 'my-bucket', 'https://cdn.example.com'));
```

Затем `Storage::disk('s3')->put(...)` — это замена «на месте» — код приложения не меняется.

## Чек-лист безопасности

- ✅ Всегда храните пользовательские загрузки под **выделенным корнем**, никогда внутри `public/`.
- ✅ Отклоняйте абсолютные пути (локальный адаптер уже это делает).
- ✅ Генерируйте имена файлов сами; не выводите предоставленное клиентом как сохраняемое имя.
- ✅ Валидируйте размер файла **до** чтения загрузки (заголовок `Content-Length`) и после (`$file->getSize()`).
- ✅ Валидируйте **фактический** тип файла через `mime_content_type()` после загрузки — расширение тривиально подделать.
- ✅ Для изображений прогоняйте их через перекодировщик (например, `intervention/image`), чтобы убрать эксплойты.
- ❌ Никогда не пишите в `public/` из приложения, если только не хотите, чтобы это действительно отдавалось — файлы там доступны любому, кто угадает URL.

## Частые подводные камни

| Симптом | Причина | Исправление |
|---|---|---|
| `Absolute paths are not allowed` | Вы передали `/var/www/…/storage/foo` в `put()` | Передавайте относительный путь — корень уже настроен. |
| `Access denied: path escapes the storage root` | Пользовательский ввод содержал `../` | Файловая система делает свою работу — блокируйте загрузку выше по стеку. |
| `Storage root could not be created` | У пользователя веб-сервера нет прав на запись | `chown -R www-data:www-data storage/` (или эквивалент). |
| `url()` возвращает `null` | Вы не передали `publicUrl` в конструктор | Предоставьте его: `new LocalFilesystem($root, '/uploads')`. |
| Порядок перечисления файлов меняется между системами | Разный порядок обхода файловой системы | Адаптер сортирует результаты — но не полагайтесь на порядок; сортируйте явно при необходимости. |

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

// Несколько дисков
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
