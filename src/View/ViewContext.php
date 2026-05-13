<?php

declare(strict_types=1);

namespace Lift\View;

final class ViewContext
{
    public function __construct(
        private readonly ViewRenderer $renderer,
        private readonly ViewFactory $factory,
        private readonly array $data,
    ) {}

    public function data(): array
    {
        return $this->data;
    }

    public function layout(string $layout): void
    {
        $this->renderer->layout($layout);
    }

    public function section(string $name): void
    {
        $this->renderer->section($name);
    }

    public function end(): void
    {
        $this->renderer->end();
    }

    public function yield(string $name, string $default = ''): string
    {
        return $this->renderer->yield($name, $default);
    }

    public function content(): string
    {
        return $this->renderer->content();
    }

    public function include(string $view, array $data = []): string
    {
        return $this->renderer->include($view, $data);
    }

    public function e(mixed $value): string
    {
        return $this->renderer->e($value);
    }

    public function asset(string $path): string
    {
        return $this->factory->asset($path);
    }
}
