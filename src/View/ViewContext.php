<?php

declare(strict_types=1);

namespace Lift\View;

/**
 * Template context object injected as `$view` inside every rendered PHP template.
 *
 * ViewContext is the primary API surface for template authors. It wraps the
 * renderer and factory so that view files never touch internals directly.
 *
 * Typical template usage:
 * ```php
 * <?php $view->layout('layouts.app') ?>
 *
 * <?php $view->section('title') ?> My Page <?php $view->end() ?>
 *
 * <h1><?= $view->e($title) ?></h1>
 * <?= $view->include('partials.hero', ['text' => 'Hello']) ?>
 * <img src="<?= $view->asset('img/logo.png') ?>">
 * ```
 */
final class ViewContext
{
    public function __construct(
        private readonly ViewRenderer $renderer,
        private readonly ViewFactory $factory,
        private readonly array $data,
    ) {}

    /**
     * Return all variables that were passed to this view.
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->data;
    }

    /**
     * Set the layout that wraps this view's output.
     *
     * Call this at the top of a view file before any output is produced.
     * The layout receives this view's content via `$view->content()`.
     */
    public function layout(string $layout): void
    {
        $this->renderer->layout($layout);
    }

    /**
     * Begin capturing a named section.
     *
     * Must be paired with a corresponding {@see end()} call.
     * Sections are yielded in layout files via {@see yield()}.
     */
    public function section(string $name): void
    {
        $this->renderer->section($name);
    }

    /**
     * End the current section and store its captured content.
     *
     * @throws \RuntimeException If called with no active section.
     */
    public function end(): void
    {
        $this->renderer->end();
    }

    /**
     * Output a named section, or a default string when the section was not defined.
     *
     * Typically called inside a layout template:
     * ```php
     * <title><?= $view->yield('title', 'Default Title') ?></title>
     * ```
     */
    public function yield(string $name, string $default = ''): string
    {
        return $this->renderer->yield($name, $default);
    }

    /**
     * Return the inner view content for use inside a layout template.
     *
     * This is the full rendered output of the child view before the layout wraps it.
     */
    public function content(): string
    {
        return $this->renderer->content();
    }

    /**
     * Render and return a partial view, optionally passing extra variables.
     *
     * Variables passed here are merged over the current view's data:
     * ```php
     * <?= $view->include('partials.card', ['post' => $post]) ?>
     * ```
     *
     * @param array<string, mixed> $data
     */
    public function include(string $view, array $data = []): string
    {
        return $this->renderer->include($view, $data);
    }

    /**
     * HTML-escape a value for safe output inside HTML attributes or text nodes.
     *
     * Uses `ENT_QUOTES | ENT_SUBSTITUTE` with UTF-8 encoding.
     * Always prefer this over raw `<?= $var ?>` for user-supplied content.
     */
    public function e(mixed $value): string
    {
        return $this->renderer->e($value);
    }

    /**
     * Build a URL for a static asset.
     *
     * Absolute URLs and data URIs are returned unchanged. Relative paths are
     * prefixed with the configured asset base (e.g. `/assets`).
     *
     * ```php
     * <script src="<?= $view->asset('js/app.js') ?>"></script>
     * ```
     */
    public function asset(string $path): string
    {
        return $this->factory->asset($path);
    }

    /**
     * Translate a key using the factory's translator.
     *
     * Falls back to the key itself when no translator is configured.
     *
     * ```php
     * <?= $view->t('welcome_message') ?>
     * <?= $view->t('greeting', ['name' => $user->name]) ?>
     * ```
     *
     * @param array<string, string|int|float> $replace
     */
    public function t(string $key, array $replace = []): string
    {
        return $this->renderer->t($key, $replace);
    }

    /**
     * Translate a key selecting the correct plural form for $count.
     *
     * ```php
     * <?= $view->tc('comments_count', $count, ['count' => $count]) ?>
     * ```
     *
     * @param array<string, string|int|float> $replace
     */
    public function tc(string $key, int $count, array $replace = []): string
    {
        return $this->renderer->tc($key, $count, $replace);
    }
}
