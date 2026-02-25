#!/bin/bash
# =============================================================================
# fpp_install.sh - SBS with Audio Sync Plugin Install Script
# =============================================================================
# Called by FPP's plugin manager after cloning, or manually via:
#   sudo ./scripts/fpp_install.sh
#
# This is the main install logic. install.sh at repo root is a thin wrapper.
# =============================================================================

set -e

# Determine plugin directory (this script lives in scripts/)
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

WEBROOT="/opt/fpp/www"
MUSIC_DIR="/home/fpp/media/music"
LISTEN_SYNC="/home/fpp/listen-sync"

RED='\033[0;31m'; GREEN='\033[0;32m'; CYAN='\033[0;36m'; YELLOW='\033[1;33m'; NC='\033[0m'
info()  { printf '%b\n' "${CYAN}[INFO]${NC} $1"; }
ok()    { printf '%b\n' "${GREEN}[OK]${NC} $1"; }
warn()  { printf '%b\n' "${YELLOW}[WARN]${NC} $1"; }
fail()  { printf '%b\n' "${RED}[FAIL]${NC} $1"; exit 1; }

VERSION=$(cat "$PLUGIN_DIR/VERSION" 2>/dev/null || echo "unknown")
echo ""
echo "========================================="
printf '%b\n' "${CYAN}  SBS with Audio Sync - v${VERSION}${NC}"
echo "========================================="
echo ""

# --- Step 1: Prerequisites ---
info "Checking prerequisites..."
[ -d "$WEBROOT" ] || fail "Apache docroot $WEBROOT not found. Is this an FPP system?"
[ -d "$MUSIC_DIR" ] || fail "FPP music directory $MUSIC_DIR not found."
php -v >/dev/null 2>&1 || fail "PHP is not installed."
python3 --version >/dev/null 2>&1 || fail "Python3 is not installed."
ok "Prerequisites OK"

# --- Step 2: Fix Windows line endings ---
if command -v sed >/dev/null 2>&1; then
  find "$PLUGIN_DIR" \( -name "*.sh" -o -name "*.py" -o -name "*.service" \
    -o -name "*.conf" -o -name "*.html" -o -name "*.php" -o -name "*.htaccess*" \
    -o -name "*.inc" -o -name "*.json" -o -name "*.js" -o -name "*.css" \) \
    -exec sed -i 's/\r$//' {} + 2>/dev/null || true
fi

# --- Step 3: Python websockets ---
info "Checking Python websockets package..."
if ! python3 -c "import websockets" 2>/dev/null; then
  info "Installing websockets package..."
  sudo apt install -y python3-websockets 2>/dev/null || \
    python3 -m pip install websockets 2>/dev/null || \
    sudo python3 -m pip install websockets --break-system-packages 2>/dev/null || \
    fail "Could not install Python websockets package"
fi
ok "Python websockets available"

# --- Step 4: hostapd + dnsmasq ---
info "Checking hostapd and dnsmasq..."
NEED_INSTALL=""
dpkg -s hostapd >/dev/null 2>&1 || NEED_INSTALL="hostapd"
dpkg -s dnsmasq >/dev/null 2>&1 || NEED_INSTALL="$NEED_INSTALL dnsmasq"
if [ -n "$NEED_INSTALL" ]; then
  info "Installing: $NEED_INSTALL"
  sudo apt-get install -y $NEED_INSTALL
fi
sudo systemctl unmask hostapd 2>/dev/null || true
ok "hostapd and dnsmasq installed"

# --- Step 5: Deploy web files ---
info "Deploying web files..."
sudo mkdir -p "$WEBROOT/listen"

# Deploy all files from www/listen/
for f in "$PLUGIN_DIR/www/listen/"*; do
  [ -f "$f" ] && sudo cp -f "$f" "$WEBROOT/listen/" 2>/dev/null || true
done

# Deploy files from www/ root (qrcode, music.php, etc.)
for f in "$PLUGIN_DIR/www/"*.html "$PLUGIN_DIR/www/"*.js "$PLUGIN_DIR/www/"*.php; do
  [ -f "$f" ] && sudo cp -f "$f" "$WEBROOT/" 2>/dev/null || true
done

# Deploy VERSION to listen dir
sudo cp -f "$PLUGIN_DIR/VERSION" "$WEBROOT/listen/VERSION" 2>/dev/null || true

# Create index.html redirect if admin.html exists
if [ -f "$WEBROOT/listen/admin.html" ]; then
  echo '<meta http-equiv="refresh" content="0;url=listen.html">' | sudo tee "$WEBROOT/listen/index.html" > /dev/null
fi

# Clean up old files from previous installs
sudo rm -f "$WEBROOT/listen/public.html" 2>/dev/null || true

sudo chown -R www-data:www-data "$WEBROOT/listen" 2>/dev/null || true
sudo chmod -R 755 "$WEBROOT/listen" 2>/dev/null || true
ok "Web files deployed to $WEBROOT/listen/"

# --- Step 6: Music symlink ---
if [ ! -L "$WEBROOT/music" ] && [ ! -d "$WEBROOT/music" ]; then
  sudo ln -s "$MUSIC_DIR" "$WEBROOT/music"
elif [ -L "$WEBROOT/music" ]; then
  CURRENT=$(readlink -f "$WEBROOT/music")
  if [ "$CURRENT" != "$MUSIC_DIR" ]; then
    sudo rm -f "$WEBROOT/music"
    sudo ln -s "$MUSIC_DIR" "$WEBROOT/music"
  fi
fi
sudo chmod -R a+rX "$MUSIC_DIR" 2>/dev/null || true
ok "Music symlink OK"

# --- Step 7: Apache modules + config ---
info "Configuring Apache..."
for mod in proxy proxy_wstunnel headers rewrite; do
  if ! apache2ctl -M 2>/dev/null | grep -q "${mod}_module"; then
    sudo a2enmod "$mod" 2>/dev/null || true
  fi
done

if [ -f "$PLUGIN_DIR/config/apache-listener.conf" ]; then
  sudo cp -f "$PLUGIN_DIR/config/apache-listener.conf" /etc/apache2/conf-available/listener.conf
  sudo a2enconf listener 2>/dev/null || true
fi

# Clean up any previous 000-default.conf modifications (FPP regenerates this file,
# so we no longer inject IncludeOptional or AllowOverride changes there).
# Rewrite rules now live in conf-enabled/listener.conf which FPP doesn't touch.
FPP_VHOST="/etc/apache2/sites-available/000-default.conf"
if [ -f "$FPP_VHOST" ] && grep -q 'show-rewrite.conf' "$FPP_VHOST" 2>/dev/null; then
  sudo sed -i '/show-rewrite\.conf/d' "$FPP_VHOST"
  info "Removed old VirtualHost injection (now in conf-enabled)"
fi
# Remove old AllowOverride backup (no longer needed - we don't modify 000-default.conf).
# IMPORTANT: Do NOT restore the backup - it replaces the sites-enabled symlink with a
# stale file copy, breaking FPP's Apache config when FPP updates sites-available.
sudo rm -f "/etc/apache2/sites-enabled/000-default.conf.listener-backup" 2>/dev/null || true
# Ensure sites-enabled is a symlink (not a stale file from old backup restore)
if [ -f "/etc/apache2/sites-enabled/000-default.conf" ] && [ ! -L "/etc/apache2/sites-enabled/000-default.conf" ]; then
  sudo rm "/etc/apache2/sites-enabled/000-default.conf"
  sudo ln -s /etc/apache2/sites-available/000-default.conf /etc/apache2/sites-enabled/000-default.conf
  info "Restored 000-default.conf symlink"
fi

# Clean up old fpp-listener-sync wlan1-setup.service if present
# The old plugin created a service that hardcoded 192.168.50.1 on wlan1 at boot,
# conflicting with our per-interface IP management in listener-ap.sh.
if [ -f /etc/systemd/system/wlan1-setup.service ]; then
  info "Removing old wlan1-setup service..."
  sudo systemctl stop wlan1-setup 2>/dev/null || true
  sudo systemctl disable wlan1-setup 2>/dev/null || true
  sudo rm -f /etc/systemd/system/wlan1-setup.service
  sudo rm -f /usr/local/bin/wlan1-setup.sh
  sudo systemctl daemon-reload
  ok "Old wlan1-setup service removed"
fi

# Clean up old fpp-listener-sync dnsmasq config if present
# The old plugin wrote directly to /etc/dnsmasq.conf with listen-address=192.168.50.1
# which conflicts with our per-interface dnsmasq instances.
if grep -q 'SHOW_AUDIO\|listen-sync\|listener' /etc/dnsmasq.conf 2>/dev/null; then
  info "Cleaning old fpp-listener-sync dnsmasq config..."
  sudo systemctl stop dnsmasq 2>/dev/null || true
  sudo systemctl disable dnsmasq 2>/dev/null || true
  # Reset to minimal default (dnsmasq service stays disabled - we use per-interface instances)
  sudo tee /etc/dnsmasq.conf > /dev/null <<'DNSEOF'
# Default dnsmasq.conf - system dnsmasq disabled.
# Per-interface DNS/DHCP is managed by SBS with Audio Sync plugin.
DNSEOF
  ok "Old dnsmasq config cleaned"
fi

# Disable SSL (self-signed cert breaks captive portal)
sudo a2dissite listener-ssl 2>/dev/null || true
sudo a2dismod ssl 2>/dev/null || true
ok "Apache configured"

# --- Step 8: Network config page redirect ---
info "Redirecting network config page to plugin dashboard..."
NETCONFIG="$WEBROOT/networkconfig.php"
if [ -f "$NETCONFIG" ] && [ ! -f "$NETCONFIG.listener-backup" ]; then
  sudo cp "$NETCONFIG" "$NETCONFIG.listener-backup"
  info "Backed up original networkconfig.php"
fi
sudo tee "$NETCONFIG" > /dev/null <<'PHPREDIRECT'
<?php
header('Location: plugin.php?plugin=SBSPlus&page=plugin.php');
exit;
PHPREDIRECT
ok "Network page redirects to plugin dashboard"

if [ -f "$NETCONFIG.listener-backup" ]; then
  sudo cp "$NETCONFIG.listener-backup" "$WEBROOT/networkconfig-original.php"
  ok "Original network page at /networkconfig-original.php"
fi

# --- Step 9: Deploy runtime configs ---
info "Setting up listener config..."
sudo mkdir -p "$LISTEN_SYNC"
sudo chown fpp:fpp "$LISTEN_SYNC"

# Deploy roles.json (role-driven config, replaces ap.conf + hostapd-*.conf)
ROLES_FILE="$LISTEN_SYNC/roles.json"
if [ ! -f "$ROLES_FILE" ]; then
  if [ -f "$LISTEN_SYNC/ap.conf" ]; then
    # Migrate from legacy ap.conf - dashboard will complete migration on first load
    info "Legacy ap.conf found - will migrate on first dashboard access"
    echo '{}' | sudo tee "$ROLES_FILE" > /dev/null
  else
    # Fresh install - detect first wireless interface and assign SBS role
    DEFAULT_WLAN=""
    for w in /sys/class/net/wlan*; do
      [ -d "$w" ] && DEFAULT_WLAN=$(basename "$w") && break
    done
    # Build initial roles.json with detected interfaces
    HAS_ETH0=""
    [ -d "/sys/class/net/eth0" ] && HAS_ETH0="yes"

    if [ -n "$DEFAULT_WLAN" ] || [ -n "$HAS_ETH0" ]; then
      python3 -c "
import json
roles = {}
wlan = '${DEFAULT_WLAN}'
eth = '${HAS_ETH0}'
if wlan:
    roles[wlan] = {
        'role': 'sbs',
        'ssid': 'EAVESDROP',
        'channel': 6,
        'password': 'Listen123',
        'ip': '192.168.40.1',
        'mask': 24
    }
if eth:
    roles['eth0'] = {'role': 'show_network'}
print(json.dumps(roles, indent=4))
" | sudo tee "$ROLES_FILE" > /dev/null
      if [ -n "$DEFAULT_WLAN" ]; then
        info "Default roles.json created (SBS on $DEFAULT_WLAN, eth0: show_network)"
      else
        info "Default roles.json created (eth0: show_network)"
      fi
    else
      echo '{}' | sudo tee "$ROLES_FILE" > /dev/null
      warn "No interfaces detected - empty roles.json created"
    fi
  fi
  sudo chmod 644 "$ROLES_FILE"
else
  ok "roles.json exists, keeping current settings"
fi

# Determine first AP interface from roles.json (for service start check)
WLAN_IF=""
if [ -f "$ROLES_FILE" ]; then
  WLAN_IF=$(python3 -c "
import json
try:
    roles = json.load(open('$ROLES_FILE'))
    for iface, cfg in roles.items():
        role = cfg.get('role','') if isinstance(cfg, dict) else cfg
        if role in ('sbs','listener'):
            print(iface)
            break
except: pass
" 2>/dev/null)
fi
[ -z "$WLAN_IF" ] && WLAN_IF="wlan0"

# Deploy ws-sync-server.py
sudo cp -f "$PLUGIN_DIR/server/ws-sync-server.py" "$LISTEN_SYNC/ws-sync-server.py"
sudo chown fpp:fpp "$LISTEN_SYNC/ws-sync-server.py"
sudo chmod 755 "$LISTEN_SYNC/ws-sync-server.py"

# Deploy listener-ap.sh
sudo cp -f "$PLUGIN_DIR/server/listener-ap.sh" "$LISTEN_SYNC/listener-ap.sh"
sudo chown fpp:fpp "$LISTEN_SYNC/listener-ap.sh"
sudo chmod 755 "$LISTEN_SYNC/listener-ap.sh"
# Fix CRLF on deployed copies
sudo sed -i 's/\r$//' "$LISTEN_SYNC/listener-ap.sh" "$LISTEN_SYNC/ws-sync-server.py" 2>/dev/null || true

sudo chown -R fpp:fpp "$LISTEN_SYNC"
ok "Runtime configs deployed"

# --- Step 10: Systemd services ---
info "Installing systemd services..."

# ws-sync service
if [ -f "$PLUGIN_DIR/config/ws-sync.service" ]; then
  sudo cp -f "$PLUGIN_DIR/config/ws-sync.service" /etc/systemd/system/ws-sync.service
  sudo systemctl daemon-reload
  sudo systemctl enable ws-sync 2>/dev/null || true
  sudo systemctl restart ws-sync 2>/dev/null || true
  ok "ws-sync service installed and started"
fi

# listener-ap service
if [ -f "$PLUGIN_DIR/server/listener-ap.service" ]; then
  sudo cp -f "$PLUGIN_DIR/server/listener-ap.service" /etc/systemd/system/listener-ap.service
  sudo systemctl daemon-reload
  sudo systemctl enable listener-ap 2>/dev/null || true

  if [ -e "/sys/class/net/$WLAN_IF" ]; then
    sudo systemctl restart listener-ap 2>/dev/null || true
    ok "listener-ap service started ($WLAN_IF detected)"
  else
    warn "$WLAN_IF not detected - listener-ap enabled but not started"
    info "Connect $WLAN_IF and run: sudo systemctl start listener-ap"
  fi
fi

# --- Step 11: Sudoers ---
info "Configuring sudoers..."
SUDOERS_FILE="/etc/sudoers.d/listener-sync"
sudo tee "$SUDOERS_FILE" > /dev/null <<'EOF'
# SBS with Audio Sync - allow www-data to manage services and config
www-data ALL=(ALL) NOPASSWD: /usr/bin/tee /home/fpp/listen-sync/hostapd-*
www-data ALL=(ALL) NOPASSWD: /usr/bin/tee /home/fpp/listen-sync/roles.json
www-data ALL=(ALL) NOPASSWD: /usr/bin/tee /etc/dnsmasq.conf
www-data ALL=(ALL) NOPASSWD: /usr/bin/systemctl restart listener-ap
www-data ALL=(ALL) NOPASSWD: /usr/bin/systemctl restart dnsmasq
www-data ALL=(ALL) NOPASSWD: /usr/bin/systemctl restart ws-sync
www-data ALL=(ALL) NOPASSWD: /usr/bin/systemctl stop hostapd
www-data ALL=(ALL) NOPASSWD: /usr/sbin/iw dev * station dump
www-data ALL=(ALL) NOPASSWD: /usr/sbin/nft *
www-data ALL=(ALL) NOPASSWD: /sbin/ip addr *
www-data ALL=(ALL) NOPASSWD: /sbin/ip link *
www-data ALL=(ALL) NOPASSWD: /usr/bin/sed -i *
www-data ALL=(ALL) NOPASSWD: /usr/sbin/wpa_cli *
www-data ALL=(ALL) NOPASSWD: /usr/bin/journalctl --rotate
www-data ALL=(ALL) NOPASSWD: /usr/bin/journalctl --vacuum-time=1s *
# BT calibration
www-data ALL=(ALL) NOPASSWD: /usr/bin/bluetoothctl *
www-data ALL=(fpp) NOPASSWD: /usr/bin/pactl *
www-data ALL=(ALL) NOPASSWD: /bin/cp *
www-data ALL=(ALL) NOPASSWD: /bin/chown fpp\:fpp /home/fpp/media/sequences/_bt_cal.fseq
www-data ALL=(ALL) NOPASSWD: /bin/rm -f /home/fpp/media/sequences/_bt_cal.fseq
EOF
sudo chmod 440 "$SUDOERS_FILE"

if sudo visudo -cf "$SUDOERS_FILE" >/dev/null 2>&1; then
  ok "Sudoers configured"
else
  warn "Sudoers syntax check failed - admin API may not work"
fi

# --- Step 12: FPP Plugin registration ---
info "Registering FPP plugin..."
FPP_PLUGIN_DIR="/home/fpp/media/plugins/SBSPlus"
sudo mkdir -p "$FPP_PLUGIN_DIR"

# Copy plugin system files (readlink guard prevents cp same-file error)
REAL_PLUGIN=$(readlink -f "$PLUGIN_DIR")
REAL_FPP_PLUGIN=$(readlink -f "$FPP_PLUGIN_DIR")

if [ "$REAL_PLUGIN" != "$REAL_FPP_PLUGIN" ]; then
  sudo cp -f "$PLUGIN_DIR/pluginInfo.json" "$FPP_PLUGIN_DIR/pluginInfo.json"
  sudo cp -f "$PLUGIN_DIR/api.php" "$FPP_PLUGIN_DIR/api.php"
  sudo cp -f "$PLUGIN_DIR/plugin.php" "$FPP_PLUGIN_DIR/plugin.php"
  sudo cp -f "$PLUGIN_DIR/menu.inc" "$FPP_PLUGIN_DIR/menu.inc"
  sudo cp -f "$PLUGIN_DIR/about.php" "$FPP_PLUGIN_DIR/about.php"
  sudo cp -f "$PLUGIN_DIR/listener-api.php" "$FPP_PLUGIN_DIR/listener-api.php"
  sudo cp -f "$PLUGIN_DIR/fpp-network.php" "$FPP_PLUGIN_DIR/fpp-network.php"
  sudo cp -f "$PLUGIN_DIR/VERSION" "$FPP_PLUGIN_DIR/VERSION"
  sudo cp -f "$PLUGIN_DIR/README.md" "$FPP_PLUGIN_DIR/README.md" 2>/dev/null || true
fi
sudo chown -R fpp:fpp "$FPP_PLUGIN_DIR"
ok "FPP plugin registered"

# --- Step 13: Add Undoc Admin footer button via custom.js ---
CUSTOM_JS="/home/fpp/media/config/custom.js"
# Remove any old injection first (clean slate)
if [ -f "$CUSTOM_JS" ] && grep -q "SBSPlus" "$CUSTOM_JS" 2>/dev/null; then
  sed -i '/-- SBSPlus/,/-- end SBSPlus --/d' "$CUSTOM_JS"
  # Remove file if only whitespace remains
  if [ ! -s "$CUSTOM_JS" ] || ! grep -q '[^[:space:]]' "$CUSTOM_JS" 2>/dev/null; then
    rm -f "$CUSTOM_JS"
  fi
fi
# Add footer button (appears on all FPP pages)
touch "$CUSTOM_JS"
cat >> "$CUSTOM_JS" <<'CUSTOMEOF'
// -- SBSPlus -- Undoc Admin footer button
$(function(){
  var btn = $('<button type="button" class="buttons btn-outline-light">')
    .html('<i class="fas fa-fw fa-headphones fa-nbsp"></i>Undoc Admin')
    .on('click', function(){ window.open('/listen/admin.html','_blank'); });
  $('#rebootShutdown').prepend(btn);
});
// -- end SBSPlus --
CUSTOMEOF
ok "Footer button configured"

# --- Step 14: Fix git .git/ ownership ---
if [ -d "$PLUGIN_DIR/.git" ]; then
  sudo chown -R fpp:fpp "$PLUGIN_DIR/.git" 2>/dev/null || true
fi

# --- Step 15: Restart Apache ---
sudo systemctl restart apache2 2>/dev/null || sudo apachectl restart 2>/dev/null || true
ok "Apache restarted"

# --- Step 16: Self-Test ---
echo ""
info "Running self-tests..."
ERRORS=0

# ws-sync service
if systemctl is-active --quiet ws-sync 2>/dev/null; then
  ok "ws-sync: running"
else
  printf '%b\n' "${RED}[FAIL] ws-sync not running${NC}"
  ERRORS=$((ERRORS+1))
fi

# ws-sync port
if command -v curl >/dev/null 2>&1; then
  WS_HTTP=$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8080/ 2>/dev/null || echo "000")
  [ "$WS_HTTP" = "426" ] && ok "ws-sync port 8080: responding" || { printf '%b\n' "${RED}[FAIL] ws-sync port 8080: HTTP $WS_HTTP${NC}"; ERRORS=$((ERRORS+1)); }
fi

# listener-ap service (only if interface exists)
if [ -e "/sys/class/net/$WLAN_IF" ]; then
  if systemctl is-active --quiet listener-ap 2>/dev/null; then
    ok "listener-ap: running"
  else
    printf '%b\n' "${RED}[FAIL] listener-ap not running${NC}"
    ERRORS=$((ERRORS+1))
  fi
fi

# Web page
if command -v curl >/dev/null 2>&1; then
  HTTP=$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1/listen/ 2>/dev/null || echo "000")
  [ "$HTTP" = "200" ] && ok "/listen/: HTTP 200" || { printf '%b\n' "${RED}[FAIL] /listen/: HTTP $HTTP${NC}"; ERRORS=$((ERRORS+1)); }

  HTTP2=$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1/listen/status.php 2>/dev/null || echo "000")
  [ "$HTTP2" = "200" ] && ok "status.php: HTTP 200" || { printf '%b\n' "${RED}[FAIL] status.php: HTTP $HTTP2${NC}"; ERRORS=$((ERRORS+1)); }
fi

# nftables (if running via listener-ap)
if [ -x /usr/sbin/nft ]; then
  /usr/sbin/nft list tables 2>/dev/null | grep -q 'listener_' && ok "nftables: active" || info "nftables: will activate when AP starts"
fi

# --- Summary ---
LOCAL_IP=$(hostname -I 2>/dev/null | awk '{print $1}')
[ -z "$LOCAL_IP" ] && LOCAL_IP="YOUR_FPP_IP"

echo ""
echo "========================================="
if [ $ERRORS -eq 0 ]; then
  printf '%b\n' "${GREEN}  Install successful! v${VERSION}${NC}"
else
  printf '%b\n' "${RED}  Install completed with $ERRORS error(s). v${VERSION}${NC}"
fi
echo "========================================="
echo "  FPP Dashboard: Status > SBS Audio Sync"
echo "  Admin:  http://${LOCAL_IP}/listen/admin.html"
echo "  Sync:   WebSocket (ws://${LOCAL_IP}/ws)"

# Show configured interfaces from roles.json
if [ -f "$LISTEN_SYNC/roles.json" ]; then
  python3 -c "
import json
try:
    roles = json.load(open('$LISTEN_SYNC/roles.json'))
    for iface, cfg in roles.items():
        if not isinstance(cfg, dict): continue
        role = cfg.get('role','')
        if role == 'sbs':
            ssid = cfg.get('ssid','EAVESDROP')
            ip = cfg.get('ip','192.168.40.1')
            print(f'  SBS AP:   {ssid} (WPA2) on {iface} ({ip})')
        elif role == 'listener':
            ssid = cfg.get('ssid','SHOW_AUDIO')
            ip = cfg.get('ip','192.168.50.1')
            pw = cfg.get('password','')
            sec = 'WPA2' if pw else 'open'
            print(f'  Listen:   {ssid} ({sec}) on {iface} ({ip})')
            print(f'  Public: http://{ip}/listen/')
except: pass
" 2>/dev/null
fi

echo "========================================="
if [ $ERRORS -ne 0 ]; then
  echo "  Check: journalctl -u ws-sync -f"
  echo "         journalctl -u listener-ap -f"
  echo "========================================="
fi
