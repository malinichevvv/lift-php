<?php

declare(strict_types=1);

namespace Lift\Debug;

use Lift\Exception\HttpException;
use Lift\Http\Request;
use Lift\Http\Response;
use Lift\Validation\ValidationException;
use Throwable;

/**
 * Debug-aware exception and PHP error handler.
 *
 * ErrorHandler is used by {@see \Lift\App} when debug mode or exception-specific
 * renderers are configured. It keeps Lift's default behaviour for validation and
 * HTTP exceptions, while allowing applications to register custom renderers by
 * exception class and a generic fallback renderer.
 *
 * ```php
 * $app->debug(['enabled' => true]);
 *
 * $app->onException(NotFoundException::class, function (NotFoundException $e) {
 *     return Response::html('<h1>Not found</h1>', 404);
 * });
 * ```
 */
final class ErrorHandler
{
    /** @var array<class-string, callable> */
    private array $renderers = [];
    private mixed $fallback = null;
    private mixed $previousErrorHandler = null;

    public function __construct(
        private readonly DebugConfig $config,
        private readonly DebugCollector $collector,
    ) {}

    /**
     * Register a renderer for an exception class or interface.
     *
     * Renderers are checked with `instanceof`, so handlers registered for parent
     * classes or interfaces also match child exceptions.
     *
     * @param class-string $exceptionClass
     * @param callable $handler Callable(Throwable $e, Request $request): Response
     */
    public function render(string $exceptionClass, callable $handler): self
    {
        $this->renderers[$exceptionClass] = $handler;
        return $this;
    }

    /**
     * Register a fallback renderer used after class-specific renderers.
     *
     * This is how {@see \Lift\App::onError()} is integrated with the debug error
     * pipeline while preserving backwards compatibility.
     */
    public function fallback(callable $handler): self
    {
        $this->fallback = $handler;
        return $this;
    }

    /**
     * Convert an exception into an HTTP response.
     *
     * Resolution order:
     * 1. exception-specific renderers;
     * 2. fallback renderer;
     * 3. Lift defaults for validation and HTTP exceptions;
     * 4. debug HTML page for HTML requests;
     * 5. generic JSON 500 response.
     */
    public function handle(Throwable $e, Request $request): Response
    {
        $this->collector->recordException($e);

        foreach ($this->renderers as $class => $renderer) {
            if ($e instanceof $class) {
                return $renderer($e, $request);
            }
        }

        if ($this->fallback !== null) {
            return ($this->fallback)($e, $request);
        }

        if ($e instanceof ValidationException) {
            return Response::json(['errors' => $e->errors()], 422);
        }

        if ($e instanceof HttpException) {
            return Response::json(['error' => $e->getMessage()], $e->getStatusCode());
        }

        if ($this->config->renderExceptionPages() && !$request->wantsJson()) {
            return Response::html($this->renderExceptionPage($e), 500);
        }

        return Response::json(['error' => 'Internal Server Error'], 500);
    }

    /**
     * Install a PHP error handler that records warnings/notices in the collector.
     *
     * The previous PHP error handler is preserved and called after Lift records
     * the error, so existing application-level handlers continue to work.
     */
    public function trackPhpErrors(): self
    {
        if (!$this->config->trackPhpErrors()) {
            return $this;
        }

        $this->previousErrorHandler = set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }

            $this->collector->recordPhpError($severity, $message, $file, $line);

            if (is_callable($this->previousErrorHandler)) {
                return (bool) ($this->previousErrorHandler)($severity, $message, $file, $line);
            }

            return false;
        });

        return $this;
    }

    /**
     * Restore the PHP error handler that was active before tracking started.
     */
    public function restorePhpHandlers(): void
    {
        if ($this->previousErrorHandler === null) {
            return;
        }

        restore_error_handler();
        $this->previousErrorHandler = null;
    }

    private function renderExceptionPage(Throwable $e): string
    {
        $class   = $e::class;
        $short   = substr(strrchr($class, '\\') ?: $class, 1) ?: $class;
        $message = $e->getMessage();
        $file    = $e->getFile();
        $line    = $e->getLine();

        $source     = $this->codeSnippet($file, $line, 10);
        $sourceHtml = $this->renderCodeBlock($source);

        $traceHtml = $this->renderStackTrace($e->getTrace());

        $prev = $e->getPrevious();
        $chainHtml = '';
        if ($prev !== null) {
            $chainHtml = '<div class="chain-label">Caused by</div>'
                . '<div class="chain-item">'
                . '<span class="chain-cls">' . $this->e($prev::class) . '</span>'
                . '<div class="chain-msg">' . $this->e($prev->getMessage()) . '</div>'
                . '</div>';
        }

        $css = $this->exceptionPageCss();
        $js  = $this->exceptionPageJs();

        return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$this->e($short)} — Lift Debug</title>
{$css}
</head>
<body>
<div class="err-header">
  <span class="exc-badge">{$this->e($class)}</span>
  <h1 class="err-msg">{$this->e($message)}</h1>
  <div class="err-loc">
    <span class="loc-file">{$this->e($file)}</span><span class="loc-colon">:</span><span class="loc-line">{$line}</span>
  </div>
  {$chainHtml}
</div>

<div class="page">
  <div class="section-label">Source — {$this->e(basename($file))}:{$line}</div>
  {$sourceHtml}

  <div class="section-label">Stack Trace</div>
  <div class="trace">{$traceHtml}</div>
</div>

{$js}
</body>
</html>
HTML;
    }

    /**
     * Read lines around the error location from a source file.
     *
     * @return list<array{number:int, code:string, highlight:bool}>
     */
    private function codeSnippet(string $file, int $errorLine, int $context = 10): array
    {
        if (!is_file($file) || !is_readable($file)) {
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
    private function renderCodeBlock(array $lines): string
    {
        if ($lines === []) {
            return '<div class="code-unavail">Source file not available.</div>';
        }

        $rows = '';
        foreach ($lines as $l) {
            $cls   = $l['highlight'] ? ' err' : '';
            $arrow = $l['highlight'] ? '▶' : '';
            $rows .= '<div class="cl' . $cls . '">'
                   . '<span class="ln">' . $l['number'] . '</span>'
                   . '<span class="arr">' . $arrow . '</span>'
                   . '<span class="cd">' . $this->e($l['code']) . '</span>'
                   . '</div>';
        }

        return '<div class="code-block">' . $rows . '</div>';
    }

    /**
     * @param list<array<string,mixed>> $trace
     */
    private function renderStackTrace(array $trace): string
    {
        if ($trace === []) {
            return '<div class="no-trace">No stack trace available.</div>';
        }

        $html = '';
        foreach ($trace as $i => $frame) {
            $frameFile = (string) ($frame['file'] ?? '[internal]');
            $frameLine = (int) ($frame['line'] ?? 0);
            $class     = (string) ($frame['class'] ?? '');
            $type      = (string) ($frame['type'] ?? '');
            $func      = (string) ($frame['function'] ?? '');
            $method    = ($class !== '' ? $this->e($class) . '<span class="sep">' . $this->e($type) . '</span>' : '')
                       . '<span class="fn">' . $this->e($func) . '()</span>';
            $vendor    = str_contains($frameFile, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR);
            $locShort  = $frameLine > 0
                ? $this->e(basename($frameFile)) . '<span class="fl-sep">:</span><span class="fl-line">' . $frameLine . '</span>'
                : $this->e($frameFile);

            $snippet = $frameLine > 0 ? $this->codeSnippet($frameFile, $frameLine, 5) : [];

            $bodyHtml = '';
            if ($snippet !== []) {
                $bodyHtml = '<div class="frame-body">'
                          . $this->renderCodeBlock($snippet)
                          . '<div class="frame-file">' . $this->e($frameFile) . '</div>'
                          . '</div>';
            } else {
                $bodyHtml = '<div class="frame-body"><div class="code-unavail">Internal / unavailable</div></div>';
            }

            $vendorCls = $vendor ? ' vendor' : '';
            $html .= '<div class="frame' . $vendorCls . '" onclick="liftToggleFrame(this)">'
                   . '<div class="frame-hd">'
                   . '<span class="frame-n">#' . $i . '</span>'
                   . '<span class="frame-mth">' . $method . '</span>'
                   . '<span class="frame-loc">' . $locShort . '</span>'
                   . '<span class="frame-arr">▶</span>'
                   . '</div>'
                   . $bodyHtml
                   . '</div>';
        }

        return $html;
    }

    private function exceptionPageCss(): string
    {
        return '<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font:14px/1.6 system-ui,-apple-system,sans-serif;background:#0a0e1a;color:#cbd5e1;min-height:100vh}
a{color:#60a5fa}
.err-header{background:linear-gradient(135deg,#1e1035 0%,#130d2a 100%);border-bottom:3px solid #7c3aed;padding:32px 40px 28px}
.exc-badge{display:inline-block;background:#4c1d95;color:#c4b5fd;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;letter-spacing:.06em;margin-bottom:14px;font-family:monospace}
.err-msg{font-size:22px;font-weight:700;color:#f1f5f9;line-height:1.35;margin-bottom:14px;max-width:900px}
.err-loc{font-family:ui-monospace,monospace;font-size:13px;color:#64748b}
.loc-file{color:#93c5fd}.loc-colon{color:#475569}.loc-line{color:#f87171;font-weight:700}
.chain-label{font-size:11px;text-transform:uppercase;letter-spacing:.07em;color:#64748b;margin:16px 0 6px}
.chain-item{background:#0f172a;border:1px solid #1e293b;border-left:3px solid #7c3aed;border-radius:4px;padding:10px 14px;font-size:13px}
.chain-cls{color:#a78bfa;font-family:monospace}.chain-msg{color:#94a3b8;margin-top:4px}

.page{max-width:1100px;margin:0 auto;padding:28px 40px 60px}
.section-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#475569;margin:28px 0 10px}

/* Code block */
.code-block{background:#060b18;border:1px solid #1e293b;border-radius:6px;overflow:auto;font-family:ui-monospace,"Cascadia Code","Fira Code",monospace;font-size:12.5px;line-height:1.65}
.cl{display:flex;min-height:21px}
.cl.err{background:#2d0a0a}
.ln{min-width:48px;text-align:right;padding:0 10px;color:#334155;user-select:none;border-right:1px solid #1e293b;background:#040810}
.cl.err .ln{background:#450a0a;color:#fca5a5;font-weight:700}
.arr{width:16px;text-align:center;color:#dc2626;font-size:9px;padding-top:1px}
.cd{padding:0 14px;white-space:pre;color:#e2e8f0;flex:1;overflow-x:visible}
.cl.err .cd{color:#fff}
.code-unavail{padding:12px 16px;color:#475569;font-size:13px;font-style:italic}
.frame-file{padding:6px 12px;font-size:11px;color:#475569;font-family:monospace;background:#040810;border-top:1px solid #1e293b}

/* Stack trace */
.trace{display:flex;flex-direction:column;gap:4px}
.frame{border:1px solid #1e293b;border-radius:5px;overflow:hidden;cursor:pointer}
.frame:hover .frame-hd{background:#111d2e}
.frame.vendor{opacity:.55}
.frame.vendor:hover{opacity:.85}
.frame-hd{display:flex;align-items:center;gap:10px;padding:9px 14px;background:#0d1524;transition:background .12s}
.frame-n{background:#1e293b;color:#64748b;font-size:10px;font-weight:700;padding:2px 7px;border-radius:3px;min-width:30px;text-align:center;font-family:monospace}
.frame-mth{color:#bfdbfe;font-family:monospace;font-size:12.5px;flex:1}.fn{color:#93c5fd}.sep{color:#475569}
.frame-loc{color:#475569;font-size:12px;font-family:monospace;white-space:nowrap}
.fl-sep{color:#334155}.fl-line{color:#fbbf24;font-weight:600}
.frame-arr{color:#374151;font-size:9px;margin-left:4px;transition:transform .15s;display:block}
.frame.open .frame-arr{transform:rotate(90deg)}
.frame-body{display:none}
.frame.open .frame-body{display:block}
.no-trace{padding:12px 16px;color:#475569;font-size:13px;font-style:italic}
</style>';
    }

    private function exceptionPageJs(): string
    {
        return '<script>
function liftToggleFrame(el){el.classList.toggle("open");}
// Auto-open first non-vendor frame
var frames=document.querySelectorAll(".frame:not(.vendor)");
if(frames.length>0)frames[0].classList.add("open");
</script>';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
