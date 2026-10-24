#!/bin/sh
# Formats the code and runs every check (the same as CI) plus the production build.
# On the host it re-runs itself in the Docker "test" container (PHP 8.4, Node, Chromium, MariaDB).
#   ./prepare.sh
set -eu
cd "$(dirname "$0")"

if [ ! -f /.dockerenv ]; then
  docker compose --profile test build test
  exec docker compose --profile test run --rm test sh prepare.sh
fi

step() { printf '\n\033[1m==> %s\033[0m\n' "$1"; }

step 'Dependencies'
npm ci --no-audit --no-fund
composer install -d api --no-interaction --no-progress

step 'Prettier'
npx prettier --write --log-level warn .

step 'ESLint'
npx eslint --fix .

step 'Audits'
npm audit --omit=dev --audit-level=high
composer audit -d api

step 'PHP syntax'
find api tests -name '*.php' -not -path 'api/vendor/*' -print0 | xargs -0 -n1 php -l >/dev/null

step 'PHPStan'
api/vendor/bin/phpstan analyse -c phpstan.neon --no-progress

step 'Schema applies to an empty database'
sql() { mariadb --skip-ssl -h"$DB_HOST" -uroot -p"$DB_ROOT_PASS" "$@"; }
sql -e 'DROP DATABASE IF EXISTS zidle_schema; CREATE DATABASE zidle_schema CHARACTER SET utf8mb4'
sql zidle_schema < db/schema.sql
sql -e 'DROP DATABASE zidle_schema'

step 'PHP integration tests'
php tests/php/run.php

step 'PHP/JS consistency'
npm run --silent test:consistency

step 'Playwright E2E'
npm run test:e2e

step 'Production build'
npm run build
rm -rf build && mkdir -p build/api
cp -R dist/. build/ # the web root: index.html, scanner.html, sw.js, .htaccess, assets/
tar -C api --exclude=./vendor --exclude=./config.local.php --exclude='./logs/*.log' -cf - . | tar -C build/api -xf -
composer install -d build/api --no-dev --optimize-autoloader --no-interaction --no-progress

printf '\n\033[1;32mAll checks passed – build/ (the contents of dist/ + api/ with production vendor/) is ready to deploy as the web root.\033[0m\n'
