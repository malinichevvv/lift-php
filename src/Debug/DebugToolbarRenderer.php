<?php

declare(strict_types=1);

namespace Lift\Debug;

/**
 * Renders the inline HTML debug toolbar injected before `</body>`.
 *
 * The toolbar consists of two layers:
 *  - A **mini-bar** fixed at the bottom right — always visible, shows key stats at a glance.
 *  - A **full panel** that expands on click — tabbed interface with Request, Response,
 *    SQL, Logs, and Performance sections.
 *
 * SQL slow-query thresholds: > 50 ms → orange, > 200 ms → red.
 * Log level colours: DEBUG grey, INFO blue, WARNING orange, ERROR/above red.
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
            . "max-width:min(800px,96vw);width:min(800px,96vw)'>"
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
        $wrap = '<div style="max-height:55vh;overflow:auto;padding:10px">';

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
        if (!empty($req['params'])) {
            $html .= $this->sectionHeader('Route Parameters');
            $html .= $this->arrayTable((array) $req['params']);
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
        $html    = "<p style='color:#9ca3af;margin:0 0 8px'>"
            . count($queries) . ' quer' . (count($queries) === 1 ? 'y' : 'ies')
            . ', total ' . round($totalMs, 2) . ' ms</p>';

        $html .= '<table style="width:100%;border-collapse:collapse;font-size:12px">';
        $html .= '<thead><tr style="color:#6b7280;text-align:left">'
            . '<th style="padding:4px 8px;border-bottom:1px solid #374151">#</th>'
            . '<th style="padding:4px 8px;border-bottom:1px solid #374151">SQL</th>'
            . '<th style="padding:4px 8px;border-bottom:1px solid #374151">Bindings</th>'
            . '<th style="padding:4px 8px;border-bottom:1px solid #374151;text-align:right">ms</th>'
            . '</tr></thead><tbody>';

        foreach ($queries as $i => $q) {
            $ms      = (float) ($q['time_ms'] ?? 0);
            $colour  = match (true) {
                $ms > 200 => '#fca5a5',
                $ms > 50  => '#fde68a',
                default   => '#e5e7eb',
            };
            $bg      = ($i % 2 === 0) ? '#1a2234' : 'transparent';
            $html   .= "<tr style='background:{$bg}'>"
                . '<td style="padding:4px 8px;color:#6b7280">' . ($i + 1) . '</td>'
                . '<td style="padding:4px 8px"><code style="white-space:pre-wrap;word-break:break-all">'
                . $this->e((string) ($q['sql'] ?? ''))
                . '</code></td>'
                . '<td style="padding:4px 8px;color:#9ca3af">'
                . $this->e(json_encode($q['bindings'] ?? [], JSON_UNESCAPED_UNICODE) ?: '[]')
                . '</td>'
                . "<td style='padding:4px 8px;text-align:right;color:{$colour}'>"
                . number_format($ms, 3)
                . '</td></tr>';
        }

        $html .= '</tbody></table>';
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
        $html  = $this->kv('Duration',    number_format($ms, 2) . ' ms');
        $html .= $this->kv('Peak Memory', number_format($mb, 2) . ' MB');
        return $html;
    }

    private function renderErrors(array $errors): string
    {
        $html  = '<table style="width:100%;border-collapse:collapse;font-size:12px">';
        $html .= '<thead><tr style="color:#6b7280;text-align:left">'
            . '<th style="padding:4px 8px;border-bottom:1px solid #374151">Type</th>'
            . '<th style="padding:4px 8px;border-bottom:1px solid #374151">Message</th>'
            . '<th style="padding:4px 8px;border-bottom:1px solid #374151">Location</th>'
            . '</tr></thead><tbody>';

        foreach ($errors as $i => $err) {
            $bg    = ($i % 2 === 0) ? '#1a2234' : 'transparent';
            $type  = $this->e((string) ($err['class'] ?? 'PHP Error'));
            $msg   = $this->e((string) ($err['message'] ?? ''));
            $file  = $this->e(basename((string) ($err['file'] ?? '')));
            $line  = $this->e((string) ($err['line'] ?? ''));
            $html .= "<tr style='background:{$bg}'>"
                . "<td style='padding:4px 8px;color:#fca5a5;font-size:11px'>{$type}</td>"
                . "<td style='padding:4px 8px'>{$msg}</td>"
                . "<td style='padding:4px 8px;color:#9ca3af'>{$file}:{$line}</td>"
                . '</tr>';
        }
        $html .= '</tbody></table>';
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

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    // -----------------------------------------------------------------
    // Static assets (CSS / JS)
    // -----------------------------------------------------------------

    private function css(): string
    {
        return '<style>#ldbg *{box-sizing:border-box}'
            . '#ldbg button:focus{outline:none}'
            . '#ldbg-bar:hover{background:#263244!important}'
            . '#ldbg-tabs button:hover{background:#1f2937!important;color:#e5e7eb!important}'
            . '#ldbg code{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:11px}'
            . '</style>';
    }

    private function js(): string
    {
        return <<<'JS'
<script>
function ldbgToggle(){
  var p=document.getElementById('ldbg-panel');
  p.style.display=p.style.display==='none'?'block':'none';
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
