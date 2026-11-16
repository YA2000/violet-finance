#!/usr/bin/env bash
# Violet Finance - one-time server tools (run as root on Ubuntu 24.04). Safe to run again.
#   sudo bash deploy/install-server-tools.sh
set -e
cd /tmp
apt-get update -q

# 1) PDF engine: Google Chrome (headless). Maintained, and the system uses it by itself when it is installed.
if ! command -v google-chrome-stable >/dev/null 2>&1; then
  install -d -m 0755 /etc/apt/keyrings
  wget -qO- https://dl.google.com/linux/linux_signing_key.pub | gpg --dearmor --yes -o /etc/apt/keyrings/google-chrome.gpg
  echo "deb [arch=amd64 signed-by=/etc/apt/keyrings/google-chrome.gpg] https://dl.google.com/linux/chrome/deb/ stable main" > /etc/apt/sources.list.d/google-chrome.list
  apt-get update -q
  apt-get install -y google-chrome-stable
fi
google-chrome-stable --version

# 2) merge PDF + attachments into the final file
apt-get install -y qpdf poppler-utils fontconfig
qpdf --version | head -1

# 3) wkhtmltopdf stays only as a fallback (PDF_ENGINE=wkhtmltopdf in .env). It is no longer maintained.
if [ "${WITH_WKHTMLTOPDF:-0}" = 1 ] && ! /usr/local/bin/wkhtmltopdf --version 2>/dev/null | grep -q "with patched qt"; then
  wget -q https://github.com/wkhtmltopdf/packaging/releases/download/0.12.6.1-3/wkhtmltox_0.12.6.1-3.jammy_amd64.deb
  apt-get install -y ./wkhtmltox_0.12.6.1-3.jammy_amd64.deb
  rm -f wkhtmltox_0.12.6.1-3.jammy_amd64.deb
fi
echo "Server tools are ready. Check: open any saved form and press Print."
