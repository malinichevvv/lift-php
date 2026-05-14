<?php

/**
 * Format results.json into a human markdown report.
 * Usage: php format-results.php results.json > results.md
 */

declare(strict_types=1);

$file = $argv[1] ?? null;
if (!$file || !is_file($file)) {
    fwrite(STDERR, "Usage: php format-results.php <results.json>\n");
    exit(2);
}

$rows = json_decode(file_get_contents($file), true) ?: [];

// group by endpoint
$byEp = [];
foreach ($rows as $r) {
    $byEp[$r['endpoint']][] = $r;
}

echo "# Benchmark Results\n\n";
echo "**Date:** " . date('Y-m-d H:i:s T') . "  \n";
echo "**PHP:** " . PHP_VERSION . "  \n";
echo "**Host:** " . php_uname('n') . " / " . php_uname('m') . "  \n";
echo "**Tester:** built-in `php -S` + `loadtest.php` (curl_multi)\n\n";
echo "Each row is the result of N requests with C concurrent workers, after warmup.\n\n";

foreach ($byEp as $ep => $list) {
    usort($list, fn($a, $b) => $b['req_s'] <=> $a['req_s']);
    $best = $list[0]['req_s'] ?: 1;

    echo "## `GET $ep`\n\n";
    echo "| # | Framework | req/s | rel | avg ms | p50 | p95 | p99 | errors |\n";
    echo "|---|-----------|------:|----:|-------:|----:|----:|----:|-------:|\n";
    $i = 1;
    foreach ($list as $r) {
        $rel = $r['req_s'] > 0 ? sprintf('%.2fx', $r['req_s'] / $best) : '—';
        $name = $r['framework'] === 'lift' ? "**{$r['framework']}**" : $r['framework'];
        printf(
            "| %d | %s | %s | %s | %.2f | %.2f | %.2f | %.2f | %d |\n",
            $i++,
            $name,
            number_format((float) $r['req_s'], 1),
            $rel,
            $r['avg_ms'],
            $r['p50_ms'],
            $r['p95_ms'],
            $r['p99_ms'],
            $r['errors'],
        );
    }
    echo "\n";
}

echo "---\n\n";
echo "## Methodology\n\n";
echo "- Single PHP process per framework (`php -S`), OPcache + JIT enabled (`php-bench.ini`).\n";
echo "- Identical handlers across frameworks: static `/ping` (text), static `/json` (5-item JSON), dynamic `/users/{id}` (JSON).\n";
echo "- Warmup excluded from latency stats.\n";
echo "- Numbers are **relative within this run** — absolute throughput depends heavily on host CPU and PHP build.\n";
