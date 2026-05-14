#!/usr/bin/env bash
# Install composer deps for every framework app.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT/frameworks"

for dir in lift slim lumen leaf flight; do
    if [[ -f "$dir/composer.json" ]]; then
        echo "==> Installing $dir ..."
        ( cd "$dir" && composer install --no-interaction --no-progress --quiet --optimize-autoloader )
    fi
done

echo "==> All deps installed."
