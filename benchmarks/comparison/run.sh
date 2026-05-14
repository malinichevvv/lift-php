#!/usr/bin/env bash
# Bench all frameworks. Boots `php -S` for each, runs loadtest.php, kills server.
#
# Env:
#   REQUESTS=5000        total requests per endpoint
#   CONCURRENCY=50       concurrent in-flight requests
#   WARMUP=300           untimed warmup requests
#   FRAMEWORKS="..."     space-separated list (default: all)
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT"

REQUESTS=${REQUESTS:-5000}
CONCURRENCY=${CONCURRENCY:-50}
WARMUP=${WARMUP:-300}
FRAMEWORKS=${FRAMEWORKS:-"raw-php lift flight slim leaf lumen"}
PHP_INI="$ROOT/php-bench.ini"

declare -A PORTS=( [raw-php]=9100 [lift]=9101 [flight]=9102 [slim]=9103 [leaf]=9104 [lumen]=9105 )

ENDPOINTS=( "/ping" "/json" "/users/42" )

mkdir -p "$ROOT/results"
RESULT_JSON="$ROOT/results/results.json"
RESULT_MD="$ROOT/results/results.md"

echo "[]" > "$RESULT_JSON"

wait_for_port() {
    local port=$1
    for i in $(seq 1 50); do
        if curl -sf "http://127.0.0.1:$port/ping" >/dev/null 2>&1; then
            return 0
        fi
        sleep 0.1
    done
    return 1
}

bench_framework() {
    local fw=$1
    local port=${PORTS[$fw]}
    local app_dir="$ROOT/frameworks/$fw"
    if [[ ! -f "$app_dir/public/index.php" ]]; then
        echo "  SKIP $fw — no public/index.php"
        return
    fi
    if [[ "$fw" != "raw-php" && ! -d "$app_dir/vendor" ]]; then
        echo "  SKIP $fw — vendor/ missing (run ./install.sh)"
        return
    fi

    echo "==> $fw on :$port"
    php -c "$PHP_INI" -S "127.0.0.1:$port" -t "$app_dir/public" >"$ROOT/results/$fw.server.log" 2>&1 &
    local pid=$!
    trap "kill $pid 2>/dev/null || true" EXIT

    if ! wait_for_port "$port"; then
        echo "  ERROR $fw failed to start (see results/$fw.server.log)"
        kill $pid 2>/dev/null || true
        wait $pid 2>/dev/null || true
        trap - EXIT
        return
    fi

    for ep in "${ENDPOINTS[@]}"; do
        local url="http://127.0.0.1:$port$ep"
        echo "    $ep"
        local out
        out=$(php -d opcache.enable_cli=1 "$ROOT/loadtest.php" "$url" "$REQUESTS" "$CONCURRENCY" "$WARMUP")
        # Append metadata (framework, endpoint) and merge into results.json
        php -r '
            $j = json_decode(file_get_contents($argv[1]), true);
            $r = json_decode($argv[2], true);
            $r["framework"] = $argv[3];
            $r["endpoint"]  = $argv[4];
            $j[] = $r;
            file_put_contents($argv[1], json_encode($j, JSON_PRETTY_PRINT));
        ' "$RESULT_JSON" "$out" "$fw" "$ep"
    done

    kill $pid 2>/dev/null || true
    wait $pid 2>/dev/null || true
    trap - EXIT
}

for fw in $FRAMEWORKS; do
    bench_framework "$fw"
done

echo "==> Generating $RESULT_MD"
php "$ROOT/format-results.php" "$RESULT_JSON" > "$RESULT_MD"
echo "Done. See $RESULT_MD"
