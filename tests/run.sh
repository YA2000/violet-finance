#!/usr/bin/env bash
# Runs the tests. First time: installs PHPUnit + Mockery into tests/tools/vendor (needs internet).
#   tests/run.sh                    every test
#   tests/run.sh --filter Numbering only the tests whose name contains "Numbering"
#   tests/run.sh tests/Feature/PtrRatesTest.php
set -euo pipefail
cd "$(dirname "$0")/.."
[ -f vendor/autoload.php ] || composer install --no-interaction --no-progress
if [ ! -x tests/tools/vendor/bin/phpunit ]; then
  echo "Installing the test tools (once)..."
  composer --working-dir=tests/tools install --no-interaction --no-progress
fi
[ -f .env ] || { cp .env.example .env; php artisan key:generate --force >/dev/null; }
exec php tests/tools/vendor/bin/phpunit "$@"
