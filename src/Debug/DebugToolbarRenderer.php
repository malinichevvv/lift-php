<?php

declare(strict_types=1);

namespace Lift\Debug;

/**
 * Renders the inline HTML debug toolbar injected before `</body>`.
 *
 * The toolbar consists of two layers:
 *  - A **mini-bar** fixed at the bottom right — always visible, shows key stats at a glance.
 *  - A **full panel** that expands on click — tabbed interface with Request, Response,
 *    SQL, Logs, Performance, and Errors sections.
 *
 * SQL slow-query thresholds: > 50 ms → orange, > 200 ms → red. Duplicate queries are flagged.
 * Log level colours: DEBUG grey, INFO blue, WARNING orange, ERROR/above red.
 * Errors tab: full collapsible stack trace with per-frame source code preview.
 *
 * All user-supplied values are HTML-escaped via {@see e()} to prevent XSS.
 */
final class DebugToolbarRenderer
{
    public function __construct(private readonly DebugConfig $config) {}

    /** @param array<string, mixed> $data Data produced by {@see DebugCollector::data()}. */
    public function render(array $data): string
    {
        $queries  = (array) ($data['queries'] ?? []);
        $logs     = (array) ($data['logs'] ?? []);
        $errors   = array_merge((array) ($data['exceptions'] ?? []), (array) ($data['php_errors'] ?? []));
        $perf     = (array) ($data['performance'] ?? []);
        $req      = (array) ($data['request'] ?? []);
        $resp     = (array) ($data['response'] ?? []);

        $ms       = $this->e((string) ($perf['duration_ms'] ?? 0));
        $mb       = $this->e((string) ($perf['memory_peak_mb'] ?? 0));
        $status   = (int) ($resp['status'] ?? 0);
        $method   = $this->e((string) ($req['method'] ?? ''));
        $uri      = $this->e((string) ($req['uri'] ?? ''));
        $errCount = count($errors);
        $pos      = $this->config->position() === 'bottom-left' ? 'left:12px;right:auto;' : 'right:12px;left:auto;';

        $statusColour = match (true) {
            $status >= 500 => '#fca5a5',
            $status >= 400 => '#fde68a',
            $status >= 300 => '#93c5fd',
            default        => '#86efac',
        };

        $bar = '<div id="ldbg-bar" onclick="ldbgToggle()" style="'
            . 'cursor:pointer;display:flex;gap:10px;align-items:center;padding:7px 12px;'
            . 'background:#1f2937;flex-wrap:wrap">'
            . '<strong style="color:#60a5fa;font-size:13px">⚡&nbsp;Lift</strong>'
            . "<span style='color:#d1d5db'>{$method} {$uri}</span>"
            . "<span style='color:{$statusColour}'>{$status}</span>"
            . "<span style='color:#9ca3af'>{$ms}&thinsp;ms &middot; {$mb}&thinsp;MB</span>"
            . (count($queries) > 0
                ? "<span style='color:#a5f3fc'>SQL&nbsp;" . count($queries) . '</span>'
                : "<span style='color:#6b7280'>SQL&nbsp;0</span>")
            . (count($logs) > 0
                ? "<span style='color:#c4b5fd'>Logs&nbsp;" . count($logs) . '</span>'
                : "<span style='color:#6b7280'>Logs&nbsp;0</span>")
            . ($errCount > 0
                ? "<span style='color:#fca5a5'>Errors&nbsp;{$errCount}</span>"
                : "<span style='color:#86efac'>Errors&nbsp;0</span>")
            . '</div>';

        $panel = '<div id="ldbg-panel" style="display:none">'
            . $this->renderTabs($queries, $logs, $errors)
            . $this->renderTabContents($data, $queries, $logs, $errors)
            . '</div>';

        $css = $this->css();
        $js  = $this->js();

        return "<div id='ldbg' style='position:fixed;bottom:0;{$pos}z-index:2147483647;"
            . "font:13px/1.5 system-ui,-apple-system,sans-serif;color:#e5e7eb;"
            . "background:#111827;border:1px solid #374151;border-bottom:none;"
            . "border-radius:10px 10px 0 0;box-shadow:0 -4px 20px rgba(0,0,0,.4);"
            . "max-width:min(900px,96vw);width:min(900px,96vw)'>"
            . $bar
            . $panel
            . "</div>{$css}{$js}";
    }

    // -----------------------------------------------------------------
    // Tab nav
    // -----------------------------------------------------------------

    private function renderTabs(array $queries, array $logs, array $errors): string
    {
        $tabs = [
            ['id' => 'request',     'label' => 'Request'],
            ['id' => 'response',    'label' => 'Response'],
            ['id' => 'sql',         'label' => 'SQL&nbsp;(' . count($queries) . ')'],
            ['id' => 'logs',        'label' => 'Logs&nbsp;(' . count($logs) . ')'],
            ['id' => 'performance', 'label' => 'Performance'],
        ];
        if ($errors !== []) {
            $tabs[] = ['id' => 'errors', 'label' => '<span style="color:#fca5a5">Errors&nbsp;(' . count($errors) . ')</span>'];
        }

        $html = '<div id="ldbg-tabs" style="display:flex;gap:2px;background:#0f172a;padding:4px 8px;align-items:center;border-top:1px solid #374151">';
        foreach ($tabs as $i => $tab) {
            $active = $i === 0 ? 'background:#1f2937;color:#60a5fa;' : '';
            $html .= "<button onclick=\"ldbgTab(this,'{$tab['id']}')\" "
                . "style='border:none;cursor:pointer;padding:4px 10px;border-radius:5px;font-size:12px;"
                . "color:#9ca3af;background:transparent;{$active}'>"
                . $tab['label'] . '</button>';
        }
        $html .= '<button onclick="ldbgToggle()" style="margin-left:auto;border:none;cursor:pointer;'
            . 'background:transparent;color:#6b7280;font-size:16px;line-height:1;padding:2px 6px" '
            . 'title="Close">×</button>';
        $html .= '</div>';
        return $html;
    }

    // -----------------------------------------------------------------
    // Tab content panes
    // -----------------------------------------------------------------

    private function renderTabContents(array $data, array $queries, array $logs, array $errors): string
    {
        $wrap = '<div class="ldbg-scroll">';

        $panes = [
            'request'     => $wrap . $this->renderRequest((array) ($data['request'] ?? [])) . '</div>',
            'response'    => $wrap . $this->renderResponse((array) ($data['response'] ?? [])) . '</div>',
            'sql'         => $wrap . $this->renderSql($queries) . '</div>',
            'logs'        => $wrap . $this->renderLogs($logs) . '</div>',
            'performance' => $wrap . $this->renderPerformance((array) ($data['performance'] ?? [])) . '</div>',
        ];
        if ($errors !== []) {
            $panes['errors'] = $wrap . $this->renderErrors($errors) . '</div>';
        }

        $html = '';
        foreach ($panes as $id => $content) {
            $display = $id === 'request' ? 'block' : 'none';
            $html .= "<div id='ldbg-pane-{$id}' style='display:{$display}'>{$content}</div>";
        }
        return $html;
    }

    // -----------------------------------------------------------------
    // Individual pane renderers
    // -----------------------------------------------------------------

    private function renderRequest(array $req): string
    {
        if ($req === []) {
            return '<p style="color:#6b7280">No request data.</p>';
        }

        $html  = $this->kv('Method', $req['method'] ?? '');
        $html .= $this->kv('URI',    $req['uri']    ?? '');

        if (!empty($req['query'])) {
            $html .= $this->sectionHeader('Query Parameters');
            $html .= $this->arrayTable((array) $req['query']);
        }
        if (!empty($req['body'])) {
            $html .= $this->sectionHeader('Request Body');
            $html .= $this->arrayTable((array) $req['body']);
        }
        if (!empty($req['params'])) {
            $html .= $this->sectionHeader('Route Parameters');
            $html .= $this->arrayTable((array) $req['params']);
        }
        if (!empty($req['cookies'])) {
            $html .= $this->sectionHeader('Cookies');
            $html .= $this->arrayTable((array) $req['cookies']);
        }
        if (!empty($req['headers'])) {
            $html .= $this->sectionHeader('Headers');
            $html .= $this->headerTable((array) $req['headers']);
        }
        return $html;
    }

    private function renderResponse(array $resp): string
    {
        if ($resp === []) {
            return '<p style="color:#6b7280">No response data.</p>';
        }

        $html  = $this->kv('Status', (string) ($resp['status'] ?? ''));
        if (!empty($resp['headers'])) {
            $html .= $this->sectionHeader('Headers');
            $html .= $this->headerTable((array) $resp['headers']);
        }
        return $html;
    }

    private function renderSql(array $queries): string
    {
        if ($queries === []) {
            return '<p style="color:#6b7280">No SQL queries recorded.<br>'
                . '<small>Wire <code>$db->onQuery([$collector, \'recordQuery\'])</code> at bootstrap.</small></p>';
        }

        $totalMs = array_sum(array_column($queries, 'time_ms'));
        $maxMs   = max(array_column($queries, 'time_ms') ?: [1]);

        // Count duplicates
        $sqlCounts = [];
        foreach ($queries as $q) {
            $sql = (string) ($q['sql'] ?? '');
            $sqlCounts[$sql] = ($sqlCounts[$sql] ?? 0) + 1;
        }
        $dupCount = count(array_filter($sqlCounts, fn($c) => $c > 1));

        $html = "<div style='display:flex;gap:16px;align-items:baseline;margin-bottom:10px'>"
            . "<span style='color:#9ca3af'>"
            . count($queries) . ' quer' . (count($queries) === 1 ? 'y' : 'ies')
            . ', total <strong style="color:#e5e7eb">' . number_format($totalMs, 2) . ' ms</strong></span>'
            . ($dupCount > 0
                ? "<span style='color:#fde68a;font-size:12px'>⚠ {$dupCount} duplicate SQL " . ($dupCount === 1 ? 'group' : 'groups') . '</span>'
                : '')
            . '</div>';

        $seenSql = [];
        foreach ($queries as $i => $q) {
            $ms     = (float) ($q['time_ms'] ?? 0);
            $sql    = (string) ($q['sql'] ?? '');
            $isDup  = ($sqlCounts[$sql] ?? 1) > 1;
            $seen   = isset($seenSql[$sql]);
            $seenSql[$sql] = true;

            $msColour = match (true) {
                $ms > 200 => '#fca5a5',
                $ms > 50  => '#fde68a',
                default   => '#86efac',
            };
            $barPct  = $maxMs > 0 ? min(100, ($ms / $maxMs) * 100) : 0;
            $barW    = number_format($barPct, 1);

            $dupBadge = $isDup
                ? "<span style='background:#422006;color:#fde68a;font-size:10px;font-weight:700;"
                  . "padding:1px 6px;border-radius:3px;margin-left:6px'>"
                  . ($seen ? 'DUP' : 'DUP×' . $sqlCounts[$sql])
                  . '</span>'
                : '';

            $bindingsJson = (string) (json_encode($q['bindings'] ?? [], JSON_UNESCAPED_UNICODE) ?: '[]');
            $bindings     = $q['bindings'] ?? [];

            $html .= "<div style='border:1px solid #1e293b;border-radius:5px;margin-bottom:6px;overflow:hidden'>"
                . "<div style='display:flex;align-items:center;gap:8px;padding:6px 10px;background:#0d1524;cursor:pointer' "
                . "onclick=\"ldbgToggle(this.nextElementSibling)\">"
                . "<span style='color:#6b7280;font-size:10px;font-weight:700;background:#1e293b;"
                . "padding:2px 6px;border-radius:3px;min-width:24px;text-align:center'>" . ($i + 1) . '</span>'
                . "<code style='flex:1;color:#bfdbfe;font-size:12px;white-space:nowrap;overflow:hidden;"
                . "text-overflow:ellipsis'>" . $this->e($this->sqlKeywords($sql)) . '</code>'
                . $dupBadge
                . "<span style='color:{$msColour};font-weight:600;font-size:12px;white-space:nowrap'>"
                . number_format($ms, 3) . ' ms</span>'
                . "<span style='color:#374151;font-size:10px'>▶</span>"
                . '</div>'
                . "<div style='display:none;padding:10px;background:#060b18'>"
                . "<div style='margin-bottom:8px'>"
                . "<div style='height:4px;background:#1e293b;border-radius:2px;margin-bottom:8px'>"
                . "<div style='height:4px;background:{$msColour};width:{$barW}%;border-radius:2px'></div></div>"
                . "<pre style='color:#e2e8f0;font-size:12px;white-space:pre-wrap;word-break:break-all;"
                . "margin:0'>" . $this->e($sql) . '</pre></div>'
                . ($bindings !== []
                    ? "<div style='border-top:1px solid #1e293b;padding-top:8px'>"
                      . "<span style='color:#6b7280;font-size:11px;text-transform:uppercase;letter-spacing:.05em'>Bindings</span>"
                      . "<code style='display:block;color:#a5f3fc;font-size:12px;margin-top:4px'>"
                      . $this->e($bindingsJson) . '</code></div>'
                    : '')
                . '</div></div>';
        }

        return $html;
    }

    private function renderLogs(array $logs): string
    {
        if ($logs === []) {
            return '<p style="color:#6b7280">No log entries recorded.<br>'
                . '<small>Add <code>DebugLogHandler</code> to your logger at bootstrap.</small></p>';
        }

        $html  = '<table style="width:100%;border-collapse:collapse;font-size:12px">';
        $html .= '<thead><tr style="color:#6b7280;text-align:left">'
            . '<th style="padding:4px 8px;border-bottom:1px solid #374151">Level</th>'
            . '<th style="padding:4px 8px;border-bottom:1px solid #374151">Message</th>'
            . '<th style="padding:4px 8px;border-bottom:1px solid #374151">Context</th>'
            . '</tr></thead><tbody>';

        foreach ($logs as $i => $entry) {
            $level   = strtoupper((string) ($entry['level'] ?? 'DEBUG'));
            $colour  = match (strtolower($level)) {
                'error', 'critical', 'alert', 'emergency' => '#fca5a5',
                'warning'                                  => '#fde68a',
                'notice', 'info'                           => '#93c5fd',
                default                                    => '#9ca3af',
            };
            $bg     = ($i % 2 === 0) ? '#1a2234' : 'transparent';
            $ctx    = $entry['context'] ?? [];
            $html  .= "<tr style='background:{$bg}'>"
                . "<td style='padding:4px 8px'><span style='color:{$colour};font-weight:600;font-size:11px'>{$level}</span></td>"
                . '<td style="padding:4px 8px">' . $this->e((string) ($entry['message'] ?? '')) . '</td>'
                . '<td style="padding:4px 8px;color:#9ca3af">'
                . ($ctx !== [] ? '<code>' . $this->e((string) (json_encode($ctx, JSON_UNESCAPED_UNICODE) ?: '{}')) . '</code>' : '')
                . '</td></tr>';
        }

        $html .= '</tbody></table>';
        return $html;
    }

    private function renderPerformance(array $perf): string
    {
        $ms  = (float) ($perf['duration_ms'] ?? 0);
        $mb  = (float) ($perf['memory_peak_mb'] ?? 0);

        $msColour = match (true) {
            $ms > 1000 => '#fca5a5',
            $ms > 300  => '#fde68a',
            default    => '#86efac',
        };
        $mbColour = match (true) {
            $mb > 128 => '#fca5a5',
            $mb > 32  => '#fde68a',
            default   => '#86efac',
        };

        $html  = $this->kvColoured('Duration',    number_format($ms, 2) . ' ms', $msColour);
        $html .= $this->kvColoured('Peak Memory', number_format($mb, 2) . ' MB', $mbColour);
        return $html;
    }

    private function renderErrors(array $errors): string
    {
        $html = '';

        foreach ($errors as $idx => $err) {
            $isPhpError = isset($err['severity']) && !isset($err['trace']);

            if ($isPhpError) {
                $severityName = $this->phpErrorName((int) ($err['severity'] ?? 0));
                $msg  = $this->e((string) ($err['message'] ?? ''));
                $file = $this->e((string) ($err['file'] ?? ''));
                $line = (int) ($err['line'] ?? 0);
                $html .= "<div style='border:1px solid #2d1a1a;border-left:3px solid #dc2626;"
                    . "border-radius:5px;margin-bottom:8px;overflow:hidden'>"
                    . "<div style='display:flex;align-items:center;gap:10px;padding:8px 12px;background:#1a0d0d'>"
                    . "<span style='background:#450a0a;color:#fca5a5;font-size:10px;font-weight:700;"
                    . "padding:2px 8px;border-radius:3px'>" . $this->e($severityName) . '</span>'
                    . "<span style='flex:1;color:#fcd5d5'>{$msg}</span>"
                    . "<span style='color:#6b7280;font-size:11px;font-family:monospace'>"
                    . $this->e(basename((string) ($err['file'] ?? ''))) . ':' . $line
                    . '</span>'
                    . '</div>'
                    . $this->renderToolbarCodeSnippet($file, $line, 4)
                    . '</div>';
                continue;
            }

            // Exception
            $class   = (string) ($err['class'] ?? 'Exception');
            $short   = substr(strrchr($class, '\\') ?: $class, 1) ?: $class;
            $msg     = $this->e((string) ($err['message'] ?? ''));
            $file    = (string) ($err['file'] ?? '');
            $line    = (int) ($err['line'] ?? 0);
            $trace   = (array) ($err['trace'] ?? []);
            $prev    = $err['previous'] ?? null;

            $prevHtml = '';
            if (is_array($prev) && $prev !== []) {
                $prevHtml = "<div style='border-top:1px solid #1e293b;padding:6px 12px;background:#0a0f1a'>"
                    . "<span style='color:#64748b;font-size:10px;text-transform:uppercase;letter-spacing:.05em'>Caused by: </span>"
                    . "<span style='color:#a78bfa;font-family:monospace;font-size:11px'>" . $this->e((string) ($prev['class'] ?? '')) . '</span>'
                    . "<div style='color:#94a3b8;font-size:12px;margin-top:2px'>" . $this->e((string) ($prev['message'] ?? '')) . '</div>'
                    . '</div>';
            }

            $panelId   = 'ldbg-err-' . $idx;
            $traceHtml = $this->renderToolbarTrace($trace, $panelId);

            $html .= "<div style='border:1px solid #2d1a3a;border-left:3px solid #7c3aed;"
                . "border-radius:5px;margin-bottom:10px;overflow:hidden'>"
                . "<div style='cursor:pointer;padding:10px 12px;background:#14082a' "
                . "onclick=\"ldbgToggle(document.getElementById('{$panelId}'))\">"
                . "<div style='display:flex;align-items:center;gap:8px;margin-bottom:4px'>"
                . "<span style='background:#4c1d95;color:#c4b5fd;font-size:10px;font-weight:700;"
                . "padding:2px 8px;border-radius:3px;font-family:monospace'>" . $this->e($short) . '</span>'
                . "<span style='color:#64748b;font-size:11px;font-family:monospace'>"
                . $this->e(basename($file)) . ':' . $line
                . '</span>'
                . "<span style='margin-left:auto;color:#374151;font-size:10px'>▶</span>"
                . '</div>'
                . "<div style='color:#fcd5d5;font-size:13px'>{$msg}</div>"
                . '</div>'
                . $prevHtml
                . "<div id='{$panelId}' style='display:none'>"
                . $this->renderToolbarCodeSnippet($file, $line, 5)
                . $traceHtml
                . '</div>'
                . '</div>';
        }

        return $html !== '' ? $html : '<p style="color:#6b7280">No errors recorded.</p>';
    }

    // -----------------------------------------------------------------
    // Error panel helpers
    // -----------------------------------------------------------------

    private function renderToolbarTrace(array $trace, string $panelId): string
    {
        if ($trace === []) {
            return '';
        }

        $html = "<div style='border-top:1px solid #1e293b;padding:8px'>"
            . "<div style='color:#475569;font-size:10px;text-transform:uppercase;letter-spacing:.06em;"
            . "margin-bottom:6px'>Stack Trace</div>";

        foreach ($trace as $i => $frame) {
            $frameFile = (string) ($frame['file'] ?? '[internal]');
            $frameLine = (int) ($frame['line'] ?? 0);
            $class     = (string) ($frame['class'] ?? '');
            $type      = (string) ($frame['type'] ?? '');
            $func      = (string) ($frame['function'] ?? '');
            $vendor    = str_contains($frameFile, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR);
            $frameId   = $panelId . '-f' . $i;

            $method = ($class !== '' ? "<span style='color:#93c5fd'>" . $this->e($class) . '</span>'
                . "<span style='color:#475569'>" . $this->e($type) . '</span>' : '')
                . "<span style='color:#bfdbfe'>" . $this->e($func) . '()</span>';

            $locShort = $frameLine > 0
                ? "<span style='color:#9ca3af'>" . $this->e(basename($frameFile)) . '</span>'
                  . "<span style='color:#374151'>:</span>"
                  . "<span style='color:#fbbf24'>" . $frameLine . '</span>'
                : "<span style='color:#6b7280'>" . $this->e($frameFile) . '</span>';

            $opacity = $vendor ? 'opacity:.5;' : '';

            $snippet = $frameLine > 0 ? $this->codeSnippet($frameFile, $frameLine, 4) : [];

            $bodyHtml = '<div id="' . $frameId . '" style="display:none">';
            if ($snippet !== []) {
                $bodyHtml .= $this->renderInlineCodeBlock($snippet)
                    . "<div style='padding:2px 8px;font-size:10px;color:#475569;font-family:monospace;"
                    . "background:#040810;border-top:1px solid #1e293b'>" . $this->e($frameFile) . '</div>';
            } else {
                $bodyHtml .= "<div style='padding:8px;color:#475569;font-size:11px;font-style:italic'>Internal / not available</div>";
            }
            $bodyHtml .= '</div>';

            $html .= "<div style='{$opacity}border:1px solid #1e293b;border-radius:4px;margin-bottom:3px;overflow:hidden'>"
                . "<div style='display:flex;align-items:center;gap:8px;padding:5px 10px;background:#0a1020;"
                . "cursor:pointer' onclick=\"ldbgToggle(document.getElementById('{$frameId}'))\">"
                . "<span style='color:#334155;font-size:10px;font-weight:700;background:#1e293b;"
                . "padding:1px 5px;border-radius:2px;font-family:monospace'>#{$i}</span>"
                . "<span style='flex:1;font-family:monospace;font-size:11px'>{$method}</span>"
                . "<span style='font-family:monospace;font-size:11px'>{$locShort}</span>"
                . "<span style='color:#374151;font-size:9px;margin-left:4px'>▶</span>"
                . '</div>'
                . $bodyHtml
                . '</div>';
        }

        $html .= '</div>';
        return $html;
    }

    private function renderToolbarCodeSnippet(string $file, int $line, int $context = 5): string
    {
        $snippet = $this->codeSnippet($file, $line, $context);
        if ($snippet === []) {
            return '';
        }
        return '<div style="border-top:1px solid #1e293b">'
            . $this->renderInlineCodeBlock($snippet)
            . "<div style='padding:2px 8px;font-size:10px;color:#475569;font-family:monospace;"
            . "background:#040810;border-top:1px solid #1e293b'>" . $this->e($file) . ':' . $line . '</div>'
            . '</div>';
    }

    /**
     * @return list<array{number:int, code:string, highlight:bool}>
     */
    private function codeSnippet(string $file, int $errorLine, int $context = 5): array
    {
        if ($errorLine <= 0 || !is_file($file) || !is_readable($file)) {
            return [];
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return [];
        }

        $start   = max(1, $errorLine - $context);
        $end     = min(count($lines), $errorLine + $context);
        $snippet = [];

        for ($i = $start; $i <= $end; $i++) {
            $snippet[] = [
                'number'    => $i,
                'code'      => $lines[$i - 1],
                'highlight' => ($i === $errorLine),
            ];
        }

        return $snippet;
    }

    /**
     * @param list<array{number:int, code:string, highlight:bool}> $lines
     */
    private function renderInlineCodeBlock(array $lines): string
    {
        $html = "<div style='background:#060b18;font-family:ui-monospace,monospace;font-size:11.5px;"
            . "line-height:1.6;overflow:auto'>";
        foreach ($lines as $l) {
            $bg = $l['highlight'] ? 'background:#2d0a0a;' : '';
            $ln = $l['highlight']
                ? "<span style='min-width:36px;text-align:right;padding:0 8px;color:#fca5a5;font-weight:700;"
                  . "background:#450a0a;display:inline-block;user-select:none;border-right:1px solid #3d0a0a'>" . $l['number'] . '</span>'
                : "<span style='min-width:36px;text-align:right;padding:0 8px;color:#334155;"
                  . "background:#040810;display:inline-block;user-select:none;border-right:1px solid #1e293b'>" . $l['number'] . '</span>';
            $arrow = $l['highlight']
                ? "<span style='width:14px;display:inline-block;text-align:center;color:#dc2626;font-size:9px'>▶</span>"
                : "<span style='width:14px;display:inline-block'></span>";
            $code = "<span style='padding:0 10px;white-space:pre;color:" . ($l['highlight'] ? '#fff' : '#e2e8f0') . "'>"
                . $this->e($l['code']) . '</span>';
            $html .= "<div style='display:flex;min-height:20px;{$bg}'>{$ln}{$arrow}{$code}</div>";
        }
        $html .= '</div>';
        return $html;
    }

    // -----------------------------------------------------------------
    // HTML helpers
    // -----------------------------------------------------------------

    private function kv(string $label, string $value): string
    {
        return '<div style="display:flex;gap:8px;padding:4px 0;border-bottom:1px solid #1f2937">'
            . "<span style='color:#6b7280;min-width:120px'>{$this->e($label)}</span>"
            . "<span style='color:#e5e7eb'>{$this->e($value)}</span>"
            . '</div>';
    }

    private function kvColoured(string $label, string $value, string $colour): string
    {
        return '<div style="display:flex;gap:8px;padding:4px 0;border-bottom:1px solid #1f2937">'
            . "<span style='color:#6b7280;min-width:120px'>{$this->e($label)}</span>"
            . "<span style='color:{$colour};font-weight:600'>{$this->e($value)}</span>"
            . '</div>';
    }

    private function sectionHeader(string $title): string
    {
        return "<h4 style='color:#60a5fa;font-size:11px;text-transform:uppercase;letter-spacing:.05em;"
            . "margin:12px 0 4px'>{$this->e($title)}</h4>";
    }

    private function arrayTable(array $items): string
    {
        if ($items === []) {
            return '<p style="color:#6b7280;font-size:12px">—</p>';
        }
        $html = '<table style="width:100%;border-collapse:collapse;font-size:12px">';
        foreach ($items as $k => $v) {
            $val = is_array($v) ? json_encode($v) : (string) $v;
            $html .= '<tr><td style="padding:2px 8px;color:#6b7280;min-width:140px">'
                . $this->e((string) $k) . '</td><td style="padding:2px 8px;color:#e5e7eb">'
                . $this->e($val) . '</td></tr>';
        }
        $html .= '</table>';
        return $html;
    }

    private function headerTable(array $headers): string
    {
        if ($headers === []) {
            return '<p style="color:#6b7280;font-size:12px">—</p>';
        }
        $html = '<table style="width:100%;border-collapse:collapse;font-size:12px">';
        foreach ($headers as $name => $values) {
            $val  = is_array($values) ? implode(', ', $values) : (string) $values;
            $html .= '<tr><td style="padding:2px 8px;color:#6b7280;min-width:160px">'
                . $this->e((string) $name) . '</td><td style="padding:2px 8px;color:#e5e7eb">'
                . $this->e($val) . '</td></tr>';
        }
        $html .= '</table>';
        return $html;
    }

    /** Highlight SQL keywords for display (HTML output — already escaped by caller). */
    private function sqlKeywords(string $sql): string
    {
        return $sql;
    }

    private function phpErrorName(int $severity): string
    {
        return match ($severity) {
            E_ERROR             => 'E_ERROR',
            E_WARNING           => 'E_WARNING',
            E_NOTICE            => 'E_NOTICE',
            E_DEPRECATED        => 'E_DEPRECATED',
            E_USER_ERROR        => 'E_USER_ERROR',
            E_USER_WARNING      => 'E_USER_WARNING',
            E_USER_NOTICE       => 'E_USER_NOTICE',
            E_USER_DEPRECATED   => 'E_USER_DEPRECATED',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
            default             => 'PHP Error (' . $severity . ')',
        };
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    // -----------------------------------------------------------------
    // Static assets (CSS / JS)
    // -----------------------------------------------------------------

    private function css(): string
    {
        return '<style>'
            . '#ldbg *{box-sizing:border-box}'
            . '#ldbg button:focus{outline:none}'
            . '#ldbg-bar:hover{background:#263244!important}'
            . '#ldbg-tabs button:hover{background:#1f2937!important;color:#e5e7eb!important}'
            . '#ldbg code{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:11px}'
            . '.ldbg-scroll{max-height:60vh;overflow:auto;padding:10px}'
            . '</style>';
    }

    private function js(): string
    {
        return <<<'JS'
<script>
function ldbgToggle(el){
  if(el===undefined){
    var p=document.getElementById('ldbg-panel');
    p.style.display=p.style.display==='none'?'block':'none';
    return;
  }
  el.style.display=el.style.display==='none'?'block':'none';
}
function ldbgTab(btn,id){
  document.querySelectorAll('#ldbg-tabs button').forEach(function(b){
    b.style.background='transparent';b.style.color='#9ca3af';
  });
  btn.style.background='#1f2937';btn.style.color='#60a5fa';
  document.querySelectorAll('[id^="ldbg-pane-"]').forEach(function(p){p.style.display='none';});
  var pane=document.getElementById('ldbg-pane-'+id);
  if(pane)pane.style.display='block';
}
</script>
JS;
    }
}
