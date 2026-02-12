#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

WEBROOT="/var/www/html"
if [[ ! -d "$WEBROOT" ]]; then
  WEBROOT="/var/www"
fi

echo "[listener-sync] Removing web files..."
sudo rm -rf "$WEBROOT/listen"
sudo rm -f "$WEBROOT/music.php" "$WEBROOT/qrcode.html" "$WEBROOT/version.php" || true

echo "[listener-sync] Done."
