<?php

/**
 * PHP-based HTTP load tester (no wrk/ab required).
 *
 * Usage:
 *   php loadtest.php <url> [requests] [concurrency] [warmup]
 *
 * Example:
 *   php loadtest.php http://127.0.0.1:9101/ping 5000 50 200
 *
 * Output (JSON to stdout):
 *   {"url":"...","requests":5000,"concurrency":50,"req_s":12345.6,
 *    "p50_ms":1.2,"p95_ms":3.4,"p99_ms":7.8,"errors":0,"duration_s":0.41}
 */

declare(strict_types=1);

if ($argc < 2) {
    fwrite(STDERR, "Usage: php loadtest.php <url> [requests=2000] [concurrency=50] [warmup=200]\n");
    exit(2);
}

$url         = $argv[1];
$requests    = (int) ($argv[2] ?? 2000);
$concurrency = max(1, (int) ($argv[3] ?? 50));
$warmup      = max(0, (int) ($argv[4] ?? 200));

/**
 * Send N requests in parallel batches of $concurrency.
 * Returns [latencies_ms[], errors_count, total_seconds].
 *
 * @return array{0: list<float>, 1: int, 2: float}
 */
function run_batch(string $url, int $total, int $concurrency): array
{
    $latencies = [];
    $errors    = 0;
    $tStart    = microtime(true);

    $remaining = $total;
    while ($remaining > 0) {
        $batchSize = min($concurrency, $remaining);
        $multi     = curl_multi_init();
        /** @var array<int, array{handle: \CurlHandle, start: float}> $handles */
        $handles   = [];

        for ($i = 0; $i < $batchSize; $i++) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_NOSIGNAL       => 1,
                CURLOPT_TCP_NODELAY    => 1,
            ]);
            curl_multi_add_handle($multi, $ch);
            $handles[(int) $ch] = ['handle' => $ch, 'start' => microtime(true)];
        }

        $active = null;
        do {
            $status = curl_multi_exec($multi, $active);
            if ($active) {
                curl_multi_select($multi, 1.0);
            }
        } while ($active && $status === CURLM_OK);

        // collect per-handle status
        while ($info = curl_multi_info_read($multi)) {
            $ch  = $info['handle'];
            $key = (int) $ch;
            $end = microtime(true);
            $start = $handles[$key]['start'] ?? $tStart;
            $code  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

            if ($info['result'] !== CURLE_OK || $code < 200 || $code >= 400) {
                $errors++;
            } else {
                $latencies[] = ($end - $start) * 1000.0;
            }

            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
            unset($handles[$key]);
        }

        curl_multi_close($multi);
        $remaining -= $batchSize;
    }

    return [$latencies, $errors, microtime(true) - $tStart];
}

// warmup (single-threaded, ignored)
if ($warmup > 0) {
    run_batch($url, $warmup, min(10, $concurrency));
}

[$latencies, $errors, $duration] = run_batch($url, $requests, $concurrency);

sort($latencies);
$count = count($latencies);
$pct   = function (float $p) use ($latencies, $count): float {
    if ($count === 0) {
        return 0.0;
    }
    $idx = (int) max(0, min($count - 1, ceil($p * $count) - 1));
    return round($latencies[$idx], 3);
};

$reqS = $duration > 0 ? round($requests / $duration, 1) : 0.0;
$avg  = $count > 0 ? round(array_sum($latencies) / $count, 3) : 0.0;

echo json_encode([
    'url'         => $url,
    'requests'    => $requests,
    'concurrency' => $concurrency,
    'duration_s'  => round($duration, 3),
    'req_s'       => $reqS,
    'avg_ms'      => $avg,
    'p50_ms'      => $pct(0.50),
    'p95_ms'      => $pct(0.95),
    'p99_ms'      => $pct(0.99),
    'errors'      => $errors,
], JSON_PRETTY_PRINT) . "\n";
