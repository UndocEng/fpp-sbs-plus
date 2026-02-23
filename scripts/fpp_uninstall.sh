#!/bin/bash
# =============================================================================
# fpp_uninstall.sh — FPP Eavesdrop SBS+ Plugin Uninstall Script
# =============================================================================
# Called by FPP's plugin manager before removing the plugin, or manually via:
#   sudo ./scripts/fpp_uninstall.sh
#
# Reverses everything fpp_install.sh did. FPP's network settings
# (/home/fpp/media/config/interface.*) are NOT touched — they survive uninstall.
# =============================================================================

set -e

RED='\033[0;31m'; GREEN='\033[0;32m'; CYAN='\033[0;36m'; YELLOW='\033[1;33m'; NC='\033[0m'
info()  { printf '%b\n' "${CYAN}[INFO]${NC} $1"; }
ok()    { printf '%b\n' "${GREEN}[OK]${NC} $1"; }
warn()  { printf '%b\n' "${YELLOW}[WARN]${NC} $1"; }

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
VERSION=$(cat "$PLUGIN_DIR/VERSION" 2>/dev/null || echo "unknown")

WEBROOT="/opt/fpp/www"
LISTEN_SYNC="/home/fpp/listen-sync"

echo ""
info "Uninstalling FPP Eavesdrop SBS+ v${VERSION}..."

# --- Stop and remove systemd services ---
info "Stopping services..."

sudo systemctl stop ws-sync 2>/dev/null || true
sudo systemctl disable ws-sync 2>/dev/null || true
sudo rm -f /etc/systemd/system/ws-sync.service

sudo systemctl stop listener-ap 2>/dev/null || true
sudo systemctl disable listener-ap 2>/dev/null || true
sudo rm -f /etc/systemd/system/listener-ap.service

# Kill any leftover processes started by listener-ap.sh
if pgrep -f "hostapd-listener.conf" >/dev/null 2>&1; then
  sudo pkill -f "hostapd-listener.conf" || true
fi
if pgrep -f "hostapd-show.conf" >/dev/null 2>&1; then
  sudo pkill -f "hostapd-show.conf" || true
fi
if pgrep -f "listener-dnsmasq.conf" >/dev/null 2>&1; then
  sudo pkill -f "listener-dnsmasq.conf" || true
fi
if pgrep -f "show-dnsmasq.conf" >/dev/null 2>&1; then
  sudo pkill -f "show-dnsmasq.conf" || true
fi
sudo rm -f /tmp/listener-dnsmasq.conf /tmp/show-dnsmasq.conf

sudo systemctl daemon-reload
ok "Services stopped and removed"

# --- Remove nftables rules ---
info "Removing nftables rules..."
if [ -x /usr/sbin/nft ]; then
  sudo /usr/sbin/nft delete table inet listener_ap 2>/dev/null || true
  sudo /usr/sbin/nft delete table inet show_ap 2>/dev/null || true
  sudo /usr/sbin/nft delete table inet listener_filter 2>/dev/null || true
fi

# Remove policy routing
sudo ip rule del fwmark 0x64 table 100 2>/dev/null || true
sudo ip route flush table 100 2>/dev/null || true
sudo ip rule del fwmark 0xC8 table 200 2>/dev/null || true
sudo ip route flush table 200 2>/dev/null || true
ok "Network rules removed"

# --- Remove Apache config ---
info "Removing Apache config..."
sudo a2disconf listener 2>/dev/null || true
sudo rm -f /etc/apache2/conf-available/listener.conf
sudo rm -f /etc/apache2/conf-enabled/listener.conf

# Remove SBS+ rewrite injection from VirtualHost
FPP_VHOST="/etc/apache2/sites-available/000-default.conf"
if [ -f "$FPP_VHOST" ] && grep -q 'show-rewrite.conf' "$FPP_VHOST" 2>/dev/null; then
  sudo sed -i '/show-rewrite\.conf/d' "$FPP_VHOST"
  ok "Removed SBS+ rewrite from VirtualHost"
fi

# Restore Apache AllowOverride backup
if [ -f /etc/apache2/sites-enabled/000-default.conf.listener-backup ]; then
  sudo mv /etc/apache2/sites-enabled/000-default.conf.listener-backup /etc/apache2/sites-enabled/000-default.conf
  ok "Restored Apache config backup"
fi

# Remove captive portal .htaccess
if [ -f "$WEBROOT/.htaccess" ]; then
  sudo rm -f "$WEBROOT/.htaccess"
fi
ok "Apache config removed"

# --- Restore network config page ---
info "Restoring network config page..."
if [ -f "$WEBROOT/networkconfig.php.listener-backup" ]; then
  sudo mv "$WEBROOT/networkconfig.php.listener-backup" "$WEBROOT/networkconfig.php"
  ok "Restored original networkconfig.php"
else
  warn "No networkconfig.php backup found"
fi
sudo rm -f "$WEBROOT/networkconfig-original.php" 2>/dev/null || true

# --- Remove sudoers ---
info "Removing sudoers..."
sudo rm -f /etc/sudoers.d/listener-sync
sudo rm -f /etc/sudoers.d/fpp-listener
ok "Sudoers removed"

# --- Remove web files ---
info "Removing web files..."
sudo rm -rf "$WEBROOT/listen"
sudo rm -f "$WEBROOT/qrcode.html" "$WEBROOT/qrcode.min.js"
sudo rm -f "$WEBROOT/print-sign.html" "$WEBROOT/music.php"

# Remove /music symlink (only if it points to FPP music dir)
if [ -L "$WEBROOT/music" ]; then
  LINK_TARGET=$(readlink -f "$WEBROOT/music")
  if [ "$LINK_TARGET" = "/home/fpp/media/music" ]; then
    sudo rm -f "$WEBROOT/music"
    ok "Removed /music symlink"
  fi
fi
ok "Web files removed"

# --- Remove listener config directory ---
info "Removing listener config..."
if [ -d "$LISTEN_SYNC" ]; then
  sudo rm -rf "$LISTEN_SYNC"
  ok "Config directory removed"
fi

# --- Remove FPP plugin registration ---
info "Removing FPP plugin..."
if [ -d "/home/fpp/media/plugins/fpp-eavesdrop" ]; then
  sudo rm -rf /home/fpp/media/plugins/fpp-eavesdrop
  ok "Plugin removed"
fi

# --- Remove old custom.js injection if present ---
CUSTOM_JS="/home/fpp/media/config/custom.js"
if [ -f "$CUSTOM_JS" ] && grep -q "fpp-eavesdrop" "$CUSTOM_JS" 2>/dev/null; then
  sed -i '/-- fpp-eavesdrop/,/-- end fpp-eavesdrop --/d' "$CUSTOM_JS"
  if [ ! -s "$CUSTOM_JS" ] || ! grep -q '[^[:space:]]' "$CUSTOM_JS" 2>/dev/null; then
    rm -f "$CUSTOM_JS"
  fi
  ok "Old custom.js injection removed"
fi

# --- Restart Apache ---
info "Restarting Apache..."
sudo systemctl restart apache2 2>/dev/null || sudo apachectl restart 2>/dev/null || true

# --- Reload systemd ---
sudo systemctl daemon-reload

echo ""
echo "========================================="
printf '%b\n' "${GREEN}  Uninstall successful! (was v${VERSION})${NC}"
echo "========================================="
echo ""
info "FPP network settings are preserved. Reboot recommended."
