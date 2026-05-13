<?php

declare(strict_types=1);

namespace Lift\Debug;

final class DebugToolbarRenderer
{
    public function __construct(private readonly DebugConfig $config) {}

    /** @param array<string, mixed> $data */
    public function render(array $data): string
    {
        $request = $data['request'] ?? [];
        $response = $data['response'] ?? [];
        $performance = $data['performance'] ?? [];
        $errors = array_merge($data['exceptions'] ?? [], $data['php_errors'] ?? []);
        $position = $this->config->position() === 'bottom-left' ? 'left:12px;right:auto;' : 'right:12px;left:auto;';

        return '<div id="lift-debug-toolbar" style="position:fixed;bottom:12px;' . $position . 'z-index:2147483647;font:13px/1.4 system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;color:#e5e7eb;background:#111827;border:1px solid #374151;border-radius:10px;box-shadow:0 12px 30px rgba(0,0,0,.35);max-width:720px;overflow:hidden">'
            . '<div style="display:flex;gap:10px;align-items:center;padding:8px 10px;background:#1f2937">'
            . '<strong style="color:#93c5fd">Lift Debug</strong>'
            . '<span>' . $this->e((string) ($request['method'] ?? '')) . ' ' . $this->e((string) ($request['uri'] ?? '')) . '</span>'
            . '<span>Status: ' . $this->e((string) ($response['status'] ?? 'n/a')) . '</span>'
            . '<span>' . $this->e((string) ($performance['duration_ms'] ?? 0)) . ' ms</span>'
            . '<span>' . $this->e((string) ($performance['memory_peak_mb'] ?? 0)) . ' MB</span>'
            . '<span style="color:' . (count($errors) > 0 ? '#fca5a5' : '#86efac') . '">Errors: ' . count($errors) . '</span>'
            . '</div>'
            . '<details style="padding:8px 10px"><summary style="cursor:pointer;color:#bfdbfe">Details</summary>'
            . '<pre style="white-space:pre-wrap;max-height:420px;overflow:auto;margin:8px 0 0;color:#d1d5db">' . $this->e(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}') . '</pre>'
            . '</details></div>';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
