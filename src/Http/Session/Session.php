<?php

declare(strict_types=1);

namespace Lift\Http\Session;

use Random\RandomException;

/**
 * Driver-backed server-side session.
 *
 * Wraps any {@see SessionStoreInterface} implementation to provide a high-level
 * session API: flash messages, ID regeneration, lazy start, and serialised
 * PHP value storage.
 *
 * Lifecycle:
 * 1. {@see SessionMiddleware} instantiates a Session and calls `start()`.
 * 2. Route handlers read and write via `get()`, `set()`, `flash()`, etc.
 * 3. The middleware calls `ageFlashData()` then `save()` in a `finally` block.
 * 4. The cookie header is written via {@see toCookieHeader()} before the response goes out.
 */
class Session
{
    private string $id;
    private bool $started = false;
    /** Whether $id was taken from an untrusted client cookie (vs. explicit/auto-generated). */
    private bool $idFromCookie = false;
    /** @var array<string, mixed> */
    private array $data = [];

    /**
     * @param SessionStoreInterface|null    $store          Backing store; `null` = memory-only (no persistence).
     * @param string|null                   $id             Existing session ID (read from cookie). Auto-generated when `null`.
     * @param int                           $lifetime       Cookie and store TTL in seconds.
     * @param string                        $cookieName     Cookie name used to locate the session ID on incoming requests.
     * @param bool|string[]                 $allowedClasses Passed to `unserialize()` as `allowed_classes`.
     *                                                      `true` = allow all (default, backward-compatible).
     *                                                      `false` = no objects. Array = allowlist of class names.
     * @throws RandomException
     */
    public function __construct(
        private readonly ?SessionStoreInterface $store = null,
        ?string $id = null,
        private readonly int $lifetime = 7200,
        private readonly string $cookieName = 'lift_session',
        private readonly bool|array $allowedClasses = true,
    ) {
        if ($id !== null) {
            // Explicit ID supplied by application code — trusted.
            $this->id = $id;
        } elseif (isset($_COOKIE[$this->cookieName]) && is_string($_COOKIE[$this->cookieName])) {
            // ID came from an untrusted client cookie — flagged so start() can
            // reject a fixed/unknown value (session-fixation defence).
            $this->id           = $_COOKIE[$this->cookieName];
            $this->idFromCookie = true;
        } else {
            $this->id = bin2hex(random_bytes(20));
        }
    }

    /**
     * Adopt a session ID taken from the current request's cookies.
     *
     * {@see SessionMiddleware} calls this with the per-request cookie value so
     * the ID is read from the actual request rather than the `$_COOKIE`
     * superglobal — which is empty under persistent runtimes (RoadRunner,
     * Swoole). The ID is flagged as cookie-sourced so {@see start()} still
     * applies the session-fixation defence. No-op once the session has started.
     */
    public function setIdFromCookie(string $id): void
    {
        if ($this->started || $id === '') {
            return;
        }
        $this->id           = $id;
        $this->idFromCookie = true;
    }

    /**
     * Hydrate the session from the backing store.
     *
     * Calling `start()` more than once is safe — subsequent calls are no-ops.
     */
    public function start(): void
    {
        if ($this->started) {
            return;
        }

        $payload = $this->store?->read($this->id);

        // Session-fixation defence: when the ID came from an untrusted client
        // cookie and the store has no session under it, the value was never
        // issued by us (an attacker-fixed value or a stale/expired cookie) —
        // mint a fresh ID rather than adopting it. Genuine sessions resolve to
        // a stored payload and keep their ID. Explicit/auto-generated IDs and
        // memory-only sessions (no store) are unaffected.
        if ($this->idFromCookie && $this->store !== null && $payload === null) {
            $this->id           = bin2hex(random_bytes(20));
            $this->idFromCookie = false;
        }

        $data = $payload === null ? [] : unserialize($payload, ['allowed_classes' => $this->allowedClasses]);
        $this->data = is_array($data) ? $data : [];
        $this->data['_flash_old'] ??= [];
        $this->data['_flash_new'] ??= [];
        $this->started = true;
    }

    /** Flush the current session data to the backing store. */
    public function save(): void
    {
        $this->ensureStarted();
        $this->store?->write($this->id, serialize($this->data), $this->lifetime);
    }

    /** Return the current session ID. */
    public function id(): string
    {
        return $this->id;
    }

    /** Return the configured cookie name. */
    public function cookieName(): string
    {
        return $this->cookieName;
    }

    /** Return whether the session has been started. */
    public function isStarted(): bool
    {
        return $this->started;
    }

    /**
     * Read a value from the session, returning `$default` when the key is absent.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->ensureStarted();
        return $this->data[$key] ?? $default;
    }

    /**
     * Write a value to the session and return `$this` for chaining.
     */
    public function set(string $key, mixed $value): static
    {
        $this->ensureStarted();
        $this->data[$key] = $value;
        return $this;
    }

    /** Return `true` when the session contains the given key. */
    public function has(string $key): bool
    {
        $this->ensureStarted();
        return array_key_exists($key, $this->data);
    }

    /**
     * Read a value and immediately remove it from the session.
     */
    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->forget($key);
        return $value;
    }

    /**
     * Remove one or more keys from the session.
     */
    public function forget(string ...$keys): static
    {
        $this->ensureStarted();
        foreach ($keys as $key) {
            unset($this->data[$key]);
        }
        return $this;
    }

    /**
     * Store a value that will only survive one additional request (flash message).
     *
     * Flash values are available on the next request and then deleted automatically
     * by {@see ageFlashData()}.
     */
    public function flash(string $key, mixed $value): static
    {
        $this->set($key, $value);
        $this->data['_flash_new'][$key] = true;
        return $this;
    }

    /**
     * Promote new flash keys to old and discard expired flash values.
     *
     * Must be called once per request (by {@see SessionMiddleware}) after the
     * handler has run and before the session is saved.
     */
    public function ageFlashData(): void
    {
        $this->ensureStarted();
        foreach (array_keys($this->data['_flash_old'] ?? []) as $key) {
            unset($this->data[$key]);
        }
        $this->data['_flash_old'] = $this->data['_flash_new'] ?? [];
        $this->data['_flash_new'] = [];
    }

    /**
     * Assign a new session ID, optionally deleting the previous session from the store.
     *
     * Call this after a privilege change (e.g. login) to prevent session fixation.
     */
    public function regenerate(bool $deleteOldSession = true): void
    {
        $this->ensureStarted();
        $old = $this->id;
        $this->id = bin2hex(random_bytes(20));
        if ($deleteOldSession) {
            $this->store?->destroy($old);
        }
    }

    /**
     * Clear all session data and delete the session from the backing store.
     *
     * After calling `destroy()` the session is no longer considered started.
     */
    public function destroy(): void
    {
        $this->ensureStarted();
        $this->data = [];
        $this->store?->destroy($this->id);
        $this->started = false;
    }

    /**
     * Return all session data (excluding internal flash bookkeeping keys).
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $this->ensureStarted();
        return $this->data;
    }

    /**
     * Build the `Set-Cookie` header value that pins the session ID in the browser.
     *
     * @param bool $secure Emit the `Secure` flag (set to `true` on HTTPS).
     */
    public function toCookieHeader(bool $secure = false): string
    {
        $value = urlencode($this->id);
        $parts = [
            "{$this->cookieName}={$value}",
            'Path=/',
            'HttpOnly',
            'SameSite=Lax',
            "Max-Age={$this->lifetime}",
        ];
        if ($secure) {
            $parts[] = 'Secure';
        }
        return implode('; ', $parts);
    }

    private function ensureStarted(): void
    {
        if (!$this->started) {
            $this->start();
        }
    }
}
