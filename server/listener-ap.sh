#!/usr/bin/env bash
# listener-ap.sh
# Brings up a WPA2-protected AP and captive portal-like redirect to /listen/
# Adjust settings via /home/fpp/listen-sync/ap.conf or the web UI.

set -euo pipefail

# Source persistent AP config (IP, netmask)
AP_CONF="/home/fpp/listen-sync/ap.conf"
[[ -f "$AP_CONF" ]] && source "$AP_CONF"

# Defaults (overridden by ap.conf or environment)
WLAN_IF="${WLAN_IF:-wlan1}"
AP_IP="${AP_IP:-192.168.50.1}"
AP_MASK="${AP_MASK:-24}"

echo "[listener-ap] Using interface: $WLAN_IF"

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
ssid=SHOW_AUDIO
hw_mode=g
channel=6
country_code=US
wmm_enabled=1
ieee80211n=1
auth_algs=1
ignore_broadcast_ssid=0
ap_isolate=1

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

# dnsmasq config
DNSMASQ_CONF="/tmp/listener-dnsmasq.conf"
cat > "$DNSMASQ_CONF" <<EOF
interface=$WLAN_IF
bind-interfaces
dhcp-range=${DHCP_START},${DHCP_END},12h
dhcp-option=3,$AP_IP
dhcp-option=6,$AP_IP
address=/#/$AP_IP
EOF

echo "[listener-ap] Starting dnsmasq..."
sudo pkill -f "listener-dnsmasq.conf" || true
sudo dnsmasq --conf-file="$DNSMASQ_CONF"

echo "[listener-ap] Starting hostapd..."
sudo pkill -f "hostapd-listener.conf" || true
sudo hostapd "$HOSTAPD_CONF" -B

# Read actual SSID from config for status message
CURRENT_SSID=$(grep '^ssid=' "$HOSTAPD_CONF" 2>/dev/null | cut -d= -f2 || echo "SHOW_AUDIO")
echo "[listener-ap] AP up (SSID: ${CURRENT_SSID}, IP: ${AP_IP}, WPA2)"
