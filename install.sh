#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "[listener-sync] Installing web files..."

# Detect FPP web root (FPP images commonly use /var/www/html)
WEBROOT="/var/www/html"
if [[ ! -d "$WEBROOT" ]]; then
  WEBROOT="/var/www"
fi

# Copy www content into webroot
sudo mkdir -p "$WEBROOT/listen"
sudo rsync -a --delete "$ROOT_DIR/www/" "$WEBROOT/"

# Copy VERSION next to web root parent, so version.php can read ../VERSION
sudo cp -f "$ROOT_DIR/VERSION" "$WEBROOT/../VERSION" || true

echo "[listener-sync] Setting permissions..."
sudo chown -R www-data:www-data "$WEBROOT/listen" 2>/dev/null || true
sudo chmod -R 755 "$WEBROOT/listen" 2>/dev/null || true

echo "[listener-sync] Done."
echo "Open: http://<FPP-IP>/listen/"
