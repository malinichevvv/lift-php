<?php

declare(strict_types=1);

namespace Lift\Http\Session;

/** Driver-backed server-side session. */
class Session
{
    private string $id;
    private bool $started = false;
    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(
        private readonly ?SessionStoreInterface $store = null,
        ?string $id = null,
        private readonly int $lifetime = 7200,
        private readonly string $cookieName = 'lift_session',
    ) {
        $this->id = $id ?? ($_COOKIE[$this->cookieName] ?? bin2hex(random_bytes(20)));
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }

        $payload = $this->store?->read($this->id);
        $data = $payload === null ? [] : unserialize($payload, ['allowed_classes' => true]);
        $this->data = is_array($data) ? $data : [];
        $this->data['_flash_old'] ??= [];
        $this->data['_flash_new'] ??= [];
        $this->started = true;
    }

    public function save(): void
    {
        $this->ensureStarted();
        $this->store?->write($this->id, serialize($this->data), $this->lifetime);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->ensureStarted();
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): static
    {
        $this->ensureStarted();
        $this->data[$key] = $value;
        return $this;
    }

    public function has(string $key): bool
    {
        $this->ensureStarted();
        return array_key_exists($key, $this->data);
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->forget($key);
        return $value;
    }

    public function forget(string ...$keys): static
    {
        $this->ensureStarted();
        foreach ($keys as $key) {
            unset($this->data[$key]);
        }
        return $this;
    }

    public function flash(string $key, mixed $value): static
    {
        $this->set($key, $value);
        $this->data['_flash_new'][$key] = true;
        return $this;
    }

    public function ageFlashData(): void
    {
        $this->ensureStarted();
        foreach (array_keys($this->data['_flash_old'] ?? []) as $key) {
            unset($this->data[$key]);
        }
        $this->data['_flash_old'] = $this->data['_flash_new'] ?? [];
        $this->data['_flash_new'] = [];
    }

    public function regenerate(bool $deleteOldSession = true): void
    {
        $this->ensureStarted();
        $old = $this->id;
        $this->id = bin2hex(random_bytes(20));
        if ($deleteOldSession) {
            $this->store?->destroy($old);
        }
    }

    public function destroy(): void
    {
        $this->ensureStarted();
        $this->data = [];
        $this->store?->destroy($this->id);
        $this->started = false;
    }

    public function all(): array
    {
        $this->ensureStarted();
        return $this->data;
    }

    private function ensureStarted(): void
    {
        if (!$this->started) {
            $this->start();
        }
    }
}
