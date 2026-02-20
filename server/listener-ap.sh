#!/usr/bin/env bash
# listener-ap.sh
# Brings up the eavesdrop admin AP (WPA2) and optionally the SBS+ public listener AP (open).
# Adjust settings via /home/fpp/listen-sync/ap.conf or the web UI.

set -uo pipefail

# Source persistent AP config (IP, netmask, show AP settings)
AP_CONF="/home/fpp/listen-sync/ap.conf"
[[ -f "$AP_CONF" ]] && source "$AP_CONF"

# Defaults (overridden by ap.conf or environment)
WLAN_IF="${WLAN_IF:-wlan0}"
AP_IP="${AP_IP:-192.168.50.1}"
AP_MASK="${AP_MASK:-24}"

echo "[listener-ap] Using interface: $WLAN_IF"

# SBS mode (wlan0): stop FPP's hostapd to avoid conflict on the same interface
if [[ "$WLAN_IF" == "wlan0" ]]; then
  echo "[listener-ap] SBS mode -- stopping FPP hostapd if running..."
  sudo systemctl stop hostapd 2>/dev/null || true
  # Remove FPP tether networkd config if it exists (assigns 192.168.8.1 to wlan0)
  if [[ -f /etc/systemd/network/10-wlan0.network ]]; then
    sudo rm -f /etc/systemd/network/10-wlan0.network
    sudo systemctl restart systemd-networkd 2>/dev/null || true
    echo "[listener-ap] Removed FPP tether network config"
  fi
fi

sudo ip link set "$WLAN_IF" down || true
sudo ip addr flush dev "$WLAN_IF" || true
sudo ip addr add "$AP_IP/$AP_MASK" dev "$WLAN_IF"
sudo ip link set "$WLAN_IF" up

# Use persistent hostapd config (SSID/password changeable via web UI)
HOSTAPD_CONF="/home/fpp/listen-sync/hostapd-listener.conf"

# Create default config if missing
if [[ ! -f "$HOSTAPD_CONF" ]]; then
  echo "[listener-ap] Creating default hostapd config"
  sudo mkdir -p /home/fpp/listen-sync
  sudo tee "$HOSTAPD_CONF" > /dev/null <<EOF
interface=$WLAN_IF
driver=nl80211
ssid=EAVESDROP
hw_mode=g
channel=6
country_code=US
wmm_enabled=1
ieee80211n=1
auth_algs=1
ignore_broadcast_ssid=0
ap_isolate=0

# WPA2 configuration
wpa=2
wpa_passphrase=Listen123
wpa_key_mgmt=WPA-PSK
wpa_pairwise=CCMP
rsn_pairwise=CCMP
EOF
  sudo chmod 644 "$HOSTAPD_CONF"
fi

# Compute DHCP range from AP_IP (assumes /24)
IFS='.' read -r o1 o2 o3 o4 <<< "$AP_IP"
DHCP_START="${o1}.${o2}.${o3}.10"
DHCP_END="${o1}.${o2}.${o3}.200"

# dnsmasq config for eavesdrop AP
DNSMASQ_CONF="/tmp/listener-dnsmasq.conf"
cat > "$DNSMASQ_CONF" <<EOF
interface=$WLAN_IF
except-interface=lo
bind-interfaces
dhcp-range=${DHCP_START},${DHCP_END},12h
dhcp-option=3,$AP_IP
dhcp-option=6,$AP_IP
address=/#/$AP_IP
EOF

echo "[listener-ap] Starting eavesdrop dnsmasq..."
sudo pkill -f "listener-dnsmasq.conf" || true
sudo dnsmasq --conf-file="$DNSMASQ_CONF"

echo "[listener-ap] Starting eavesdrop hostapd..."
sudo pkill -f "hostapd-listener.conf" || true
sudo hostapd "$HOSTAPD_CONF" -B

# Route replies back to AP clients (fixes overlapping subnet with eth0)
# Mark connections arriving on AP interface via conntrack, restore mark on replies,
# then policy-route marked replies out the AP interface instead of eth0.
echo "[listener-ap] Setting up routing for eavesdrop AP clients..."
sudo sysctl -w net.ipv4.ip_forward=1 > /dev/null

# Clean up any previous nftables rules from us
sudo nft delete table inet listener_ap 2>/dev/null || true

# Create nftables rules: mark incoming AP packets, restore mark on replies
sudo nft -f - <<NFT
table inet listener_ap {
  chain prerouting {
    type filter hook prerouting priority mangle; policy accept;
    iifname "$WLAN_IF" ct mark set 0x64
  }
  chain output {
    type route hook output priority mangle; policy accept;
    ct mark 0x64 meta mark set 0x64
  }
}
NFT

# Policy route: marked packets use table 100
sudo ip rule del fwmark 0x64 table 100 2>/dev/null || true
sudo ip rule add fwmark 0x64 table 100
sudo ip route replace "${o1}.${o2}.${o3}.0/${AP_MASK}" dev "$WLAN_IF" table 100

# Read actual SSID from config for status message
CURRENT_SSID=$(grep '^ssid=' "$HOSTAPD_CONF" 2>/dev/null | cut -d= -f2 || echo "EAVESDROP")
echo "[listener-ap] Eavesdrop AP up (SSID: ${CURRENT_SSID}, IP: ${AP_IP}, WPA2)"

# ======================================================================
# SBS+ Show AP (public listener on opposite interface)
# ======================================================================
SHOW_AP_ENABLED="${SHOW_AP_ENABLED:-0}"

if [[ "$SHOW_AP_ENABLED" != "1" ]]; then
  echo "[listener-ap] Show AP disabled (SHOW_AP_ENABLED=0)"
  # Remove rewrite rules and .htaccess if left over from a previous run
  sudo rm -f /home/fpp/listen-sync/show-rewrite.conf 2>/dev/null || true
  sudo rm -f /opt/fpp/www/.htaccess 2>/dev/null || true
  sudo systemctl reload apache2 2>/dev/null || true
  exit 0
fi

# Derive show interface (opposite of eavesdrop)
if [[ "$WLAN_IF" == "wlan0" ]]; then
  SHOW_IF="wlan1"
else
  SHOW_IF="wlan0"
fi

SHOW_AP_IP="${SHOW_AP_IP:-192.168.60.1}"
SHOW_AP_MASK="${SHOW_AP_MASK:-24}"
SHOW_AP_SSID="${SHOW_AP_SSID:-SHOW_AUDIO}"

echo "[listener-ap] SBS+ mode: starting show AP on $SHOW_IF"

# Check if show interface exists
if [[ ! -e "/sys/class/net/$SHOW_IF" ]]; then
  echo "[listener-ap] WARNING: $SHOW_IF not found -- show AP not started"
  echo "[listener-ap] Plug in a USB WiFi adapter for SBS+ mode"
  exit 0
fi

# --- Show AP setup in a subshell so failures don't kill eavesdrop ---
(
  set -e

  # Configure show interface IP
  sudo ip link set "$SHOW_IF" down || true
  sudo ip addr flush dev "$SHOW_IF" || true
  sudo ip addr add "$SHOW_AP_IP/$SHOW_AP_MASK" dev "$SHOW_IF"
  sudo ip link set "$SHOW_IF" up

  # Use persistent show hostapd config
  SHOW_HOSTAPD="/home/fpp/listen-sync/hostapd-show.conf"

  # Create default config if missing
  if [[ ! -f "$SHOW_HOSTAPD" ]]; then
    echo "[listener-ap] Creating default show AP hostapd config"
    sudo tee "$SHOW_HOSTAPD" > /dev/null <<CONFEOF
interface=$SHOW_IF
driver=nl80211
ssid=$SHOW_AP_SSID
hw_mode=g
channel=11
country_code=US
wmm_enabled=1
ieee80211n=1
auth_algs=1
ignore_broadcast_ssid=0
wpa=0
ap_isolate=1
CONFEOF
    sudo chmod 644 "$SHOW_HOSTAPD"
  else
    # Ensure interface line matches the derived show interface
    sudo sed -i "s/^interface=.*/interface=$SHOW_IF/" "$SHOW_HOSTAPD"
  fi

  # Start show AP hostapd
  echo "[listener-ap] Starting show AP hostapd..."
  sudo pkill -f "hostapd-show.conf" || true
  sudo hostapd "$SHOW_HOSTAPD" -B

  # dnsmasq for show AP (separate instance, separate interface)
  IFS='.' read -r s1 s2 s3 s4 <<< "$SHOW_AP_IP"
  SHOW_DHCP_START="${s1}.${s2}.${s3}.10"
  SHOW_DHCP_END="${s1}.${s2}.${s3}.200"

  SHOW_DNSMASQ_CONF="/tmp/show-dnsmasq.conf"
  cat > "$SHOW_DNSMASQ_CONF" <<DNSEOF
interface=$SHOW_IF
except-interface=lo
bind-interfaces
dhcp-range=${SHOW_DHCP_START},${SHOW_DHCP_END},12h
dhcp-option=3,$SHOW_AP_IP
dhcp-option=6,$SHOW_AP_IP
dhcp-option=114,http://$SHOW_AP_IP/listen/portal-api.php
address=/#/$SHOW_AP_IP
DNSEOF

  echo "[listener-ap] Starting show AP dnsmasq..."
  sudo pkill -f "show-dnsmasq.conf" || true
  sudo dnsmasq --conf-file="$SHOW_DNSMASQ_CONF"

  # Generate Apache rewrite rules for captive portal + security whitelist.
  # Written directly to an Apache conf snippet (IncludeOptional'd by listener.conf)
  # because FPP's 000-default.conf sets AllowOverride None, blocking .htaccess.
  SHOW_REWRITE="/home/fpp/listen-sync/show-rewrite.conf"
  SHOW_SUBNET_ESC="${s1}\\.${s2}\\.${s3}\\."
  echo "[listener-ap] Generating captive portal rewrite rules..."
  sudo tee "$SHOW_REWRITE" > /dev/null <<RWEOF
# Auto-generated by listener-ap.sh — DO NOT EDIT
# Captive portal + security whitelist for SBS+ show AP ($SHOW_AP_IP)
# Removed when show AP stops. IncludeOptional'd by listener.conf.
RewriteEngine On

# Captive portal detection: redirect known URLs from show subnet
RewriteCond %{REMOTE_ADDR} ^${SHOW_SUBNET_ESC}
RewriteCond %{REQUEST_URI} ^/(generate_204|gen_204|hotspot-detect\\.html|success\\.html|success\\.txt|connecttest\\.txt|canonical\\.html|ncsi\\.txt|redirect)\$ [NC,OR]
RewriteCond %{HTTP_HOST} ^(captive\\.apple\\.com|connectivitycheck\\.gstatic\\.com|connectivitycheck\\.android\\.com|clients[0-9]\\.google\\.com|www\\.msftconnecttest\\.com|msftconnecttest\\.com|msftncsi\\.com)\$ [NC]
RewriteRule ^ http://${SHOW_AP_IP}/listen/listen.html [R=302,L]

# Whitelist: allow public listen files from show subnet
RewriteCond %{REMOTE_ADDR} ^${SHOW_SUBNET_ESC}
RewriteCond %{REQUEST_URI} ^/listen/(listen\\.html|status\\.php|version\\.php|portal-api\\.php|detect\\.php|logo[^/]*\\.png)\$ [NC]
RewriteRule ^ - [L]

# Whitelist: allow /music/ (audio files)
RewriteCond %{REMOTE_ADDR} ^${SHOW_SUBNET_ESC}
RewriteCond %{REQUEST_URI} ^/music(/|\$) [NC]
RewriteRule ^ - [L]

# Whitelist: allow /ws (WebSocket proxy)
RewriteCond %{REMOTE_ADDR} ^${SHOW_SUBNET_ESC}
RewriteCond %{REQUEST_URI} ^/ws\$ [NC]
RewriteRule ^ - [L]

# Whitelist: allow favicon.ico
RewriteCond %{REMOTE_ADDR} ^${SHOW_SUBNET_ESC}
RewriteCond %{REQUEST_URI} ^/favicon\\.ico\$ [NC]
RewriteRule ^ - [L]

# Block everything else from show subnet (admin pages, FPP UI, /api/, etc.)
RewriteCond %{REMOTE_ADDR} ^${SHOW_SUBNET_ESC}
RewriteRule ^ http://${SHOW_AP_IP}/listen/listen.html [R=302,L]
RWEOF
  sudo systemctl reload apache2 2>/dev/null || true
  echo "[listener-ap] Captive portal rewrite rules deployed"

  # nftables security firewall for show AP
  # Phones on the show AP can ONLY reach: DHCP, DNS, HTTP, WebSocket on the Pi
  # Everything else is REJECTed (fast fail for captive portal detection)
  echo "[listener-ap] Setting up show AP firewall..."
  sudo nft delete table inet show_ap 2>/dev/null || true

  sudo nft -f - <<NFT
table inet show_ap {
  # Conntrack routing (same pattern as eavesdrop, different mark)
  chain prerouting {
    type filter hook prerouting priority mangle; policy accept;
    iifname "$SHOW_IF" ct mark set 0xC8
  }
  chain output {
    type route hook output priority mangle; policy accept;
    ct mark 0xC8 meta mark set 0xC8
  }
  # Security firewall: restrict show AP to listener services only
  chain show_input {
    type filter hook input priority 0; policy accept;
    iifname != "$SHOW_IF" accept
    udp dport { 67, 68 } accept
    ip daddr $SHOW_AP_IP udp dport 53 accept
    ip daddr $SHOW_AP_IP tcp dport 53 accept
    ip daddr $SHOW_AP_IP tcp dport { 80, 8080 } accept
    meta l4proto tcp reject with tcp reset
    reject
  }
  # Block show AP clients from reaching other networks (wlan0, eth0, etc.)
  chain show_forward {
    type filter hook forward priority 0; policy accept;
    iifname "$SHOW_IF" drop
  }
}
NFT

  # Policy route for show AP (separate table)
  sudo ip rule del fwmark 0xC8 table 200 2>/dev/null || true
  sudo ip rule add fwmark 0xC8 table 200
  sudo ip route replace "${s1}.${s2}.${s3}.0/${SHOW_AP_MASK}" dev "$SHOW_IF" table 200

  SHOW_SSID=$(grep '^ssid=' "$SHOW_HOSTAPD" 2>/dev/null | cut -d= -f2 || echo "$SHOW_AP_SSID")
  echo "[listener-ap] Show AP up (SSID: ${SHOW_SSID}, IP: ${SHOW_AP_IP}, open, firewalled)"
  echo "[listener-ap] SBS+ mode active: eavesdrop on $WLAN_IF + public listener on $SHOW_IF"
)

if [[ $? -ne 0 ]]; then
  echo "[listener-ap] ERROR: Show AP setup failed -- eavesdrop AP is still running"
  echo "[listener-ap] Check 'journalctl -u listener-ap' for details"
fi
