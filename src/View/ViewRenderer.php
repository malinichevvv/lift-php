<?php

declare(strict_types=1);

namespace Lift\View;

final class ViewRenderer
{
    /** @var array<string, string> */
    private array $sections = [];
    /** @var list<string> */
    private array $sectionStack = [];
    private ?string $layout;
    private string $content = '';

    public function __construct(
        private readonly ViewFactory $factory,
        private array $data = [],
        ?string $layout = null,
    ) {
        $this->layout = $layout;
    }

    public function render(string $view): string
    {
        $this->content = $this->include($view, $this->data);
        if ($this->layout === null) {
            return $this->content;
        }

        return $this->include($this->layout, $this->data);
    }

    public function layout(string $layout): void
    {
        $this->layout = $layout;
    }

    public function section(string $name): void
    {
        $this->sectionStack[] = $name;
        ob_start();
    }

    public function end(): void
    {
        $name = array_pop($this->sectionStack);
        if ($name === null) {
            throw new \RuntimeException('No active view section to end');
        }
        $this->sections[$name] = (string) ob_get_clean();
    }

    public function yield(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }

    public function content(): string
    {
        return $this->sections['content'] ?? $this->content;
    }

    public function include(string $view, array $data = []): string
    {
        $file = $this->factory->resolve($view);
        $context = new ViewContext($this, $this->factory, array_replace($this->data, $data));
        $view = $context;
        extract($context->data(), EXTR_SKIP);
        ob_start();
        require $file;
        return (string) ob_get_clean();
    }

    public function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function asset(string $path): string
    {
        return $this->factory->asset($path);
    }
}
