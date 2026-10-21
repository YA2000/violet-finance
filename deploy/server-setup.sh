#!/usr/bin/env bash
# Violet Finance - one-time server setup for the "violet" deployment tool. Run as root:
#   bash /opt/violet-src/deploy/server-setup.sh
set -euo pipefail
[ "$(id -u)" = 0 ] || { echo "Run as root."; exit 1; }

SRC=$(cd "$(dirname "$0")/.." && pwd)
ask() { local q=$1 d=$2 a; read -r -p "$q [$d]: " a; echo "${a:-$d}"; }

echo "Violet Finance deployment setup (press Enter to accept the value in brackets)"
LIVE_SITE=$(ask "Live site folder" /home/violet/htdocs/finance.technologytime.org)
LIVE_USER=$(ask "Live site user"   violet)
[ -f "$LIVE_SITE/.env" ] || { echo "No .env in $LIVE_SITE - check the folder."; exit 1; }

TEST_SITE=$(ask "Test site folder (leave '-' if you have no test site yet)" -)
TEST_USER=-
if [ "$TEST_SITE" != - ]; then
  TEST_USER=$(ask "Test site user" violet-test)
  [ -f "$TEST_SITE/.env" ] || echo "Note: no .env in $TEST_SITE yet - create it before 'violet update test'."
else TEST_SITE=; TEST_USER=; fi

command -v php8.3 >/dev/null || { echo "php8.3 not found."; exit 1; }
command -v composer >/dev/null || [ -x /usr/local/bin/composer ] || { echo "composer not found (CloudPanel normally has it at /usr/local/bin/composer)."; exit 1; }
command -v rsync >/dev/null || apt-get install -y rsync

cat > /etc/violet-deploy.conf <<EOF
# Violet Finance deployment settings (used by /usr/local/bin/violet)
SRC=$SRC
BRANCH=main
PHP=php8.3
LIVE_SITE=$LIVE_SITE
LIVE_USER=$LIVE_USER
TEST_SITE=$TEST_SITE
TEST_USER=$TEST_USER
BACKUPS=/root/violet-backups
KEEP=10
EOF
chmod 600 /etc/violet-deploy.conf

chmod +x "$SRC/deploy/violet"
ln -sf "$SRC/deploy/violet" /usr/local/bin/violet
git config --global --add safe.directory "$SRC" || true

echo
echo "Ready. Try:  violet status"
