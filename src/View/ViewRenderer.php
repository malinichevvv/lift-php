<?php

declare(strict_types=1);

namespace Lift\View;

use Lift\Translation\Translator;

/**
 * Stateful PHP template renderer.
 *
 * ViewRenderer is created by {@see ViewFactory} per render call and holds the
 * mutable section stack and layout pointer. Template files should not instantiate
 * this class directly — they receive it through a {@see ViewContext} instance.
 *
 * Lifecycle per `render()` call:
 * 1. The child view is executed with output buffering active.
 * 2. If a layout was declared (or passed explicitly), the layout is executed with
 *    the buffered child output available via `$view->content()`.
 * 3. Named sections captured inside the child are available in the layout via
 *    `$view->yield('sectionName')`.
 */
final class ViewRenderer
{
    /** @var array<string, string> Named sections captured during rendering. */
    private array $sections = [];

    /** @var list<string> Stack of currently open (not yet ended) sections. */
    private array $sectionStack = [];

    private ?string $layout;

    /** Extra variables merged into the layout context (set via layout($name, $data)). */
    private array $layoutData = [];

    /** Rendered output of the child view before layout wrapping. */
    private string $content = '';

    /**
     * @param ViewFactory          $factory  Factory used to resolve view file paths.
     * @param array<string, mixed> $data     Variables available inside templates.
     * @param string|null          $layout   Layout to wrap the rendered view, if any.
     */
    public function __construct(
        private readonly ViewFactory $factory,
        private array $data = [],
        ?string $layout = null,
        private readonly ?Translator $translator = null,
    ) {
        $this->layout = $layout;
    }

    /**
     * Render the given view, optionally through a layout, and return the HTML string.
     *
     * If a layout was set (either in the constructor or called from the view via
     * `$view->layout()`) the layout file is rendered after the child view,
     * wrapping it via `$view->content()`.
     */
    public function render(string $view): string
    {
        $this->content = $this->include($view, $this->data);
        if ($this->layout === null) {
            return $this->content;
        }

        $layoutData = $this->layoutData !== [] ? array_replace($this->data, $this->layoutData) : $this->data;
        return $this->include($this->layout, $layoutData);
    }

    /**
     * Declare the layout template for the current view.
     *
     * Typically called at the very beginning of a child view file:
     * ```php
     * <?php $view->layout('layouts.app', ['title' => 'My Page', 'canonical' => '/about']) ?>
     * ```
     *
     * @param array<string, mixed> $data Extra variables merged over view data for the layout only.
     */
    public function layout(string $layout, array $data = []): void
    {
        $this->layout     = $layout;
        $this->layoutData = $data;
    }

    /**
     * Begin capturing output for a named section.
     *
     * Pairs with {@see end()}. Sections may be nested.
     */
    public function section(string $name): void
    {
        $this->sectionStack[] = $name;
        ob_start();
    }

    /**
     * End the innermost open section and store the buffered content.
     *
     * @throws \RuntimeException If called with no active section.
     */
    public function end(): void
    {
        $name = array_pop($this->sectionStack);
        if ($name === null) {
            ob_end_clean();
            throw new \RuntimeException('No active view section to end');
        }
        $this->sections[$name] = (string) ob_get_clean();
    }

    /**
     * Close any output buffers that were opened by {@see section()} calls
     * but never closed — called automatically by {@see include()} on error.
     *
     * @internal
     */
    public function discardOpenSections(): void
    {
        while ($this->sectionStack !== []) {
            array_pop($this->sectionStack);
            ob_end_clean();
        }
    }

    /**
     * Return the content of a named section, or a default value.
     *
     * Used inside layout files to output content defined in child views:
     * ```php
     * <title><?= $view->yield('title', 'My App') ?></title>
     * ```
     */
    public function yield(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }

    /**
     * Return the fully rendered child view content.
     *
     * In a layout template this outputs everything the child view produced
     * outside of named sections:
     * ```php
     * <main><?= $view->content() ?></main>
     * ```
     */
    public function content(): string
    {
        return $this->sections['content'] ?? $this->content;
    }

    /**
     * Render a partial or sub-view and return its output as a string.
     *
     * Extra `$data` is merged over the current view context:
     * ```php
     * <?= $view->include('partials.card', ['title' => 'Hello']) ?>
     * ```
     *
     * @param array<string, mixed> $data Additional variables for the included view.
     */
    public function include(string $view, array $data = []): string
    {
        $file    = $this->factory->resolve($view);
        $context = new ViewContext($this, $this->factory, array_replace($this->data, $data));
        $view    = $context;
        extract($context->data(), EXTR_SKIP);
        ob_start();
        try {
            require $file;
            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            $this->discardOpenSections();
            throw $e;
        }
    }

    /**
     * Translate a key using the factory's translator.
     *
     * Returns the key unchanged when no translator is configured.
     *
     * @param array<string, string|int|float> $replace
     */
    public function t(string $key, array $replace = []): string
    {
        return $this->translator?->get($key, $replace) ?? $key;
    }

    /**
     * Translate a key selecting the correct plural form for $count.
     *
     * @param array<string, string|int|float> $replace
     */
    public function tc(string $key, int $count, array $replace = []): string
    {
        return $this->translator?->choice($key, $count, $replace) ?? $key;
    }

    /**
     * HTML-escape a value for safe inline output.
     *
     * Equivalent to `htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`.
     * Always use this for user-supplied content.
     */
    public function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Delegate to {@see ViewFactory::asset()} for asset URL building.
     */
    public function asset(string $path): string
    {
        return $this->factory->asset($path);
    }
}
