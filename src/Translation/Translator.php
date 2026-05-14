<?php

declare(strict_types=1);

namespace Lift\Translation;

/**
 * Loads locale message files and resolves plural forms.
 *
 * Plural syntax supports two styles that may be mixed in one message string:
 *
 *   Interval notation — explicit count ranges, separated by `|`:
 *     `{1} one item|[2,4] few items|[5,*] many items`
 *
 *   Simple two-form notation — first for count=1, second otherwise:
 *     `one character|many characters`
 *
 * Placeholders use a `:name` prefix: `:attribute`, `:min`, `:max`, `:count`, etc.
 *
 * The bundled translations live in `resources/lang/` at the package root and are
 * loaded first. Any paths added via {@see addPath()} are loaded afterwards, so
 * application messages override the defaults key-by-key.
 *
 * ```php
 * $t = new Translator('ru');
 * $t->addPath(base_path('lang'));           // override bundled translations
 * $t->addMessages('de', ['required' => 'Das Feld :attribute ist erforderlich.']);
 * echo $t->choice('min_length', 3, ['attribute' => 'Name', 'min' => '3']);
 * ```
 */
class Translator
{
    /** @var array<string, array<string, string>> locale → messages */
    private array $loaded = [];

    /** @var string[] User-supplied override paths (appended after the bundled path). */
    private array $paths;

    /** Absolute path to the bundled `resources/lang/` directory. */
    private static string $bundledPath = __DIR__ . '/../../resources/lang';

    public function __construct(
        private string $locale = 'en',
        private string $fallback = 'en',
        array $paths = [],
    ) {
        $this->paths = $paths;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setFallback(string $locale): void
    {
        $this->fallback = $locale;
    }

    /**
     * Append a directory containing `<locale>.php` files.
     *
     * Files in later-added paths override keys from earlier ones.
     * The cache is cleared so the new path takes effect immediately.
     */
    public function addPath(string $path): void
    {
        $this->paths[] = $path;
        $this->loaded  = [];
    }

    /**
     * Merge messages for a locale directly (highest priority, overrides files).
     *
     * @param array<string, string> $messages
     */
    public function addMessages(string $locale, array $messages): void
    {
        $this->ensureLoaded($locale);
        $this->loaded[$locale] = array_merge($this->loaded[$locale], $messages);
    }

    /**
     * Translate a key, optionally selecting a plural form.
     *
     * @param array<string, string|int|float> $replace
     * @param int|null $count  When non-null, picks the matching plural segment.
     */
    public function get(string $key, array $replace = [], ?int $count = null): string
    {
        $this->ensureLoaded($this->locale);
        $message = $this->loaded[$this->locale][$key] ?? null;

        if ($message === null && $this->fallback !== $this->locale) {
            $this->ensureLoaded($this->fallback);
            $message = $this->loaded[$this->fallback][$key] ?? null;
        }

        $message ??= $key;

        if ($count !== null && str_contains($message, '|')) {
            $message = $this->selectPlural($message, $count);
        }

        return $this->replacePlaceholders($message, $replace);
    }

    /**
     * Resolve a plural form by count and translate.
     *
     * Automatically merges `count` into `$replace`.
     *
     * @param array<string, string|int|float> $replace
     */
    public function choice(string $key, int $count, array $replace = []): string
    {
        return $this->get($key, array_merge(['count' => $count], $replace), $count);
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    private function ensureLoaded(string $locale): void
    {
        if (isset($this->loaded[$locale])) {
            return;
        }

        $this->loaded[$locale] = [];

        // Bundled translations are always loaded first so user paths can override them
        $allPaths = [self::$bundledPath, ...$this->paths];

        foreach ($allPaths as $path) {
            $file = rtrim($path, '/') . '/' . $locale . '.php';
            if (file_exists($file)) {
                $messages = require $file;
                if (is_array($messages)) {
                    $this->loaded[$locale] = array_merge($this->loaded[$locale], $messages);
                }
            }
        }
    }

    /**
     * Select the right plural segment for $count.
     *
     * Interval and exact-match segments are tried left-to-right.
     * Falls back to simple two-form split.
     */
    private function selectPlural(string $message, int $count): string
    {
        $segments = explode('|', $message);

        foreach ($segments as $segment) {
            $s = ltrim($segment);

            if (preg_match('/^\{(\d+)\}\s*(.*)/s', $s, $m)) {
                if ((int) $m[1] === $count) {
                    return trim($m[2]);
                }
                continue;
            }

            if (preg_match('/^\[(\d+|\*),(\d+|\*)\]\s*(.*)/s', $s, $m)) {
                $from = $m[1] === '*' ? PHP_INT_MIN : (int) $m[1];
                $to   = $m[2] === '*' ? PHP_INT_MAX : (int) $m[2];
                if ($count >= $from && $count <= $to) {
                    return trim($m[3]);
                }
                continue;
            }
        }

        if (count($segments) === 2) {
            return trim($count === 1 ? $segments[0] : $segments[1]);
        }

        return trim((string) end($segments));
    }

    /** @param array<string, string|int|float> $replace */
    private function replacePlaceholders(string $message, array $replace): string
    {
        foreach ($replace as $key => $value) {
            $message = str_replace(':' . $key, (string) $value, $message);
        }
        return $message;
    }
}
