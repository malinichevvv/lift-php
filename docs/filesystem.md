---
layout: page
title: Filesystem
nav_order: 21
---

# Filesystem

`Lift\Filesystem\FilesystemInterface` is a small abstraction over file storage. It has one built-in driver — `LocalFilesystem` — and a multi-disk registry (`Storage`) so you can address several roots from one place.

> Mental model: treat the filesystem like an injected service. `$fs->put('uploads/a.png', $bytes)` is the same call whether the backing store is local disk, S3, or anything else you wire up.

## Why this exists

Direct `file_put_contents(__DIR__ . '/../storage/' . $userInput)` calls are:

1. **Unsafe** — `../` in `$userInput` can escape your storage root.
2. **Hard to test** — production paths differ from CI / test paths.
3. **Hard to swap** — moving uploads to S3 means hunting every `file_put_contents` in the codebase.

The interface fixes all three by giving you a tested API with path-traversal protection, mock-friendly injection, and per-environment adapters.

## Quick start

```php
use Lift\Filesystem\LocalFilesystem;
use Lift\Filesystem\FilesystemInterface;

$app->singleton(FilesystemInterface::class, fn() => new LocalFilesystem(
    root: __DIR__ . '/../storage/app',     // every path is relative to this
    publicUrl: '/files',                   // optional — for url() helper
));

// In any service / controller:
$fs->put('uploads/avatar.png', file_get_contents($tmpFile));
$bytes = $fs->get('uploads/avatar.png');
$url   = $fs->url('uploads/avatar.png');   // '/files/uploads/avatar.png'
$fs->delete('uploads/avatar.png');
```

## The full interface

```php
// Read / write
$fs->put($path, $contents);
$fs->append($path, $contents);
$fs->get($path);                  // throws if missing
$fs->exists($path);               // bool

// Manipulate
$fs->delete($path);               // no-op if missing
$fs->copy($source, $destination);
$fs->move($source, $destination);

// Metadata
$fs->size($path);                 // bytes (throws if missing)
$fs->lastModified($path);         // unix ts (throws if missing)

// Directories
$fs->files($directory = '', $recursive = false);   // string[]
$fs->directories($directory = '');                 // string[]
$fs->makeDirectory($path, $mode = 0755);
$fs->deleteDirectory($path);                       // recursive; no-op if missing

// Public URL
$fs->url($path);                  // ?string (null if adapter has none)
```

All paths are **relative** to the configured root. Absolute paths are rejected with `InvalidArgumentException`. Path-traversal attempts (`../../etc/passwd`) throw `RuntimeException`.

## Storage facade — multiple disks

Most apps have at least two storage roots: a *private* one for processed files and a *public* one served directly by the web server.

```php
use Lift\Filesystem\LocalFilesystem;
use Lift\Filesystem\Storage;

$storage = new Storage();
$storage
    ->addDisk('local',   new LocalFilesystem(__DIR__ . '/../storage/app'))
    ->addDisk('public',  new LocalFilesystem(__DIR__ . '/../public/uploads', '/uploads'))
    ->addDisk('reports', new LocalFilesystem(__DIR__ . '/../storage/reports'))
    ->setDefault('local');

// Register in container, or store globally:
Storage::setInstance($storage);
```

Then anywhere in the code:

```php
Storage::disk()->put('cache/state.json', $json);         // default disk
Storage::disk('public')->put('avatars/1.jpg', $bytes);
Storage::disk('reports')->put('2026-05.csv', $csv);
```

For DI-purity, inject `Storage` instead of using the static singleton:

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

## Handling HTTP uploads

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

Key points:

- **Never** trust `getClientFilename()` as the saved filename — generate a random one.
- Validate the extension / MIME type before saving. The validator can help: `'avatar' => 'required'` is a start; for real type checks use `mime_content_type()` on the uploaded temp file after upload.

## Listing files

```php
foreach ($fs->files('exports') as $absolutePath) {
    // Note: returns ABSOLUTE paths because LocalFilesystem can't represent "relative to root" universally
    $name  = basename($absolutePath);
    $size  = filesize($absolutePath);
    // …
}

foreach ($fs->files('exports', recursive: true) as $absolutePath) {
    // Walks subdirectories too
}

foreach ($fs->directories('exports') as $absolutePath) {
    // Just the immediate subfolder names
}
```

## Common operations

### Atomic write (avoid partial files)

```php
$fs->put("data.json.tmp", $payload);
$fs->move("data.json.tmp", "data.json");   // rename is atomic on the same filesystem
```

### Stream a large download

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

For *really* big files use `Stream::fromFile()` so you don't load it all into memory.

### Periodic cleanup

```php
foreach ($fs->files('tmp', recursive: true) as $path) {
    if (filemtime($path) < time() - 86400) {
        unlink($path);
    }
}
```

## Custom adapter (e.g. S3)

Implement the interface:

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

    // …implement the rest…
}

$storage->addDisk('s3', new S3Filesystem($s3Client, 'my-bucket', 'https://cdn.example.com'));
```

Then `Storage::disk('s3')->put(...)` is a drop-in replacement — application code doesn't change.

## Security checklist

- ✅ Always store user uploads under a **dedicated root**, never inside `public/`.
- ✅ Reject absolute paths (the local adapter already does).
- ✅ Generate filenames yourself; do not echo the client-provided one as the saved name.
- ✅ Validate file size **before** reading the upload (`Content-Length` header) and after (`$file->getSize()`).
- ✅ Validate the **actual** file type with `mime_content_type()` after upload — extension is trivial to spoof.
- ✅ For images, run them through a re-encoder (e.g. `intervention/image`) to strip exploits.
- ❌ Never write to `public/` from the application unless you actually want it served — files in there are accessible to anyone who guesses the URL.

## Common pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `Absolute paths are not allowed` | You passed `/var/www/…/storage/foo` to `put()` | Pass a relative path — the root is already configured. |
| `Access denied: path escapes the storage root` | User input contained `../` | The filesystem is doing its job — block the upload upstream. |
| `Storage root could not be created` | Web-server user has no write permission | `chown -R www-data:www-data storage/` (or equivalent). |
| `url()` returns `null` | You didn't pass `publicUrl` to the constructor | Provide it: `new LocalFilesystem($root, '/uploads')`. |
| File listing changes order between systems | Different filesystem iteration order | The adapter sorts results — but don't rely on order; sort explicitly when needed. |

## Cheat sheet

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

// Multi-disk
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
