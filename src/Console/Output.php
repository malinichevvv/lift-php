<?php

declare(strict_types=1);

namespace Lift\Console;

/** Thin wrapper around stdout/stderr with colour helpers. */
final class Output
{
    private const COLOURS = [
        'reset'  => "\033[0m",
        'bold'   => "\033[1m",
        'green'  => "\033[32m",
        'yellow' => "\033[33m",
        'red'    => "\033[31m",
        'cyan'   => "\033[36m",
        'grey'   => "\033[90m",
    ];

    private bool $colours;

    /** @var resource */
    private $stdout;
    /** @var resource */
    private $stderr;

    /**
     * @param resource|null $stdout Defaults to STDOUT. Inject a writable stream in tests.
     * @param resource|null $stderr Defaults to STDERR.
     */
    public function __construct(mixed $stdout = null, mixed $stderr = null)
    {
        $this->stdout  = $stdout ?? STDOUT;
        $this->stderr  = $stderr ?? STDERR;
        $this->colours = ($stdout === null)
            && function_exists('posix_isatty')
            && posix_isatty(STDOUT);
    }

    public function writeln(string $message = ''): void
    {
        fwrite($this->stdout, $this->parse($message) . PHP_EOL);
    }

    public function write(string $message): void
    {
        fwrite($this->stdout, $this->parse($message));
    }

    public function error(string $message): void
    {
        fwrite($this->stderr, $this->parse("<red>{$message}</red>") . PHP_EOL);
    }

    public function success(string $message): void
    {
        $this->writeln("<green>{$message}</green>");
    }

    public function warn(string $message): void
    {
        $this->writeln("<yellow>{$message}</yellow>");
    }

    public function info(string $message): void
    {
        $this->writeln("<cyan>{$message}</cyan>");
    }

    public function table(array $headers, array $rows): void
    {
        $widths = array_map('strlen', $headers);
        foreach ($rows as $row) {
            foreach (array_values($row) as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, strlen((string) $cell));
            }
        }

        $sep = '+' . implode('+', array_map(fn($w) => str_repeat('-', $w + 2), $widths)) . '+';
        $this->writeln($sep);

        $headerLine = '|';
        foreach ($headers as $i => $h) {
            $headerLine .= ' ' . str_pad($h, $widths[$i]) . ' |';
        }
        $this->writeln("<bold>{$headerLine}</bold>");
        $this->writeln($sep);

        foreach ($rows as $row) {
            $line = '|';
            foreach (array_values($row) as $i => $cell) {
                $line .= ' ' . str_pad((string) $cell, $widths[$i] ?? 0) . ' |';
            }
            $this->writeln($line);
        }
        $this->writeln($sep);
    }

    private function parse(string $message): string
    {
        if (!$this->colours) {
            return preg_replace('/<[^>]+>/', '', $message) ?? $message;
        }
        $map = [
            '<bold>'    => self::COLOURS['bold'],   '</bold>'   => self::COLOURS['reset'],
            '<green>'   => self::COLOURS['green'],  '</green>'  => self::COLOURS['reset'],
            '<yellow>'  => self::COLOURS['yellow'], '</yellow>' => self::COLOURS['reset'],
            '<red>'     => self::COLOURS['red'],    '</red>'    => self::COLOURS['reset'],
            '<cyan>'    => self::COLOURS['cyan'],   '</cyan>'   => self::COLOURS['reset'],
            '<grey>'    => self::COLOURS['grey'],   '</grey>'   => self::COLOURS['reset'],
        ];
        return str_replace(array_keys($map), array_values($map), $message);
    }
}
