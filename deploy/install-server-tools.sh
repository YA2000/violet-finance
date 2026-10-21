#!/usr/bin/env bash
# Violet Finance - one-time server tools (run as root on Ubuntu 24.04)
set -e
cd /tmp
# 1) wkhtmltopdf with patched Qt (exact same PDF layout as the Excel / Odoo printouts)
if ! /usr/local/bin/wkhtmltopdf --version 2>/dev/null | grep -q "with patched qt"; then
  wget -q https://github.com/wkhtmltopdf/packaging/releases/download/0.12.6.1-3/wkhtmltox_0.12.6.1-3.jammy_amd64.deb
  apt-get update
  apt-get install -y ./wkhtmltox_0.12.6.1-3.jammy_amd64.deb
  rm -f wkhtmltox_0.12.6.1-3.jammy_amd64.deb
fi
# 2) merge PDF + attachments into the final file
apt-get install -y qpdf poppler-utils fontconfig
/usr/local/bin/wkhtmltopdf --version
qpdf --version | head -1
echo "Server tools are ready."
