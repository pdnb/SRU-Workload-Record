#!/usr/bin/env bash
# Deploy SRU Workload Record on HestiaCP (run via SSH as the domain user).
# ponytail: assumes APP_DIR is the Laravel root and docroot points to APP_DIR/public
set -euo pipefail

APP_DIR="${APP_DIR:-$(cd "$(dirname "$0")/.." && pwd)}"
DEPLOY_BRANCH="${DEPLOY_BRANCH:-main}"
BUILD_ASSETS="${BUILD_ASSETS:-auto}" # auto|yes|no

cd "$APP_DIR"

if [[ ! -f artisan ]]; then
    echo "error: $APP_DIR is not a Laravel project root" >&2
    exit 1
fi

php artisan down --retry=60 --refresh=15 2>/dev/null || true

if [[ -d .git ]]; then
    git fetch origin
    git checkout "$DEPLOY_BRANCH"
    git pull --ff-only origin "$DEPLOY_BRANCH"
fi

composer install --no-dev --optimize-autoloader --no-interaction

should_build=no
case "$BUILD_ASSETS" in
    yes) should_build=yes ;;
    no)  should_build=no ;;
    auto)
        if command -v npm >/dev/null 2>&1 && [[ -f package.json ]]; then
            should_build=yes
        fi
        ;;
esac

if [[ "$should_build" == yes ]]; then
    npm ci
    npm run build
fi

php artisan migrate --force
php artisan storage:link --force 2>/dev/null || true
php artisan optimize

php artisan up

echo "deploy ok: $(git rev-parse --short HEAD 2>/dev/null || echo 'no-git')"
