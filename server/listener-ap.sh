#!/usr/bin/env bash
# listener-ap.sh
# Brings up a WPA2-protected AP and captive portal-like redirect to /listen/
# Adjust wlan device, channel, and IPs to your environment.

set -euo pipefail

WLAN_IF="${WLAN_IF:-wlan1}"
SSID="${SSID:-SHOW_AUDIO}"
CHANNEL="${CHANNEL:-6}"
AP_IP="${AP_IP:-192.168.50.1}"
AP_MASK="${AP_MASK:-24}"

echo "[listener-ap] Using interface: $WLAN_IF"

sudo ip link set "$WLAN_IF" down || true
sudo ip addr flush dev "$WLAN_IF" || true
sudo ip addr add "$AP_IP/$AP_MASK" dev "$WLAN_IF"
sudo ip link set "$WLAN_IF" up

# Use persistent hostapd config (password changeable via web UI)
HOSTAPD_CONF="/home/fpp/listen-sync/hostapd-listener.conf"

# Create default config if missing
if [[ ! -f "$HOSTAPD_CONF" ]]; then
  echo "[listener-ap] Creating default hostapd config"
  sudo mkdir -p /home/fpp/listen-sync
  sudo tee "$HOSTAPD_CONF" > /dev/null <<EOF
interface=$WLAN_IF
driver=nl80211
ssid=$SSID
hw_mode=g
channel=$CHANNEL
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
  echo "[listener-ap] Default password: Listen123"
fi

# dnsmasq config
DNSMASQ_CONF="/tmp/listener-dnsmasq.conf"
cat > "$DNSMASQ_CONF" <<EOF
interface=$WLAN_IF
bind-interfaces
dhcp-range=192.168.50.10,192.168.50.200,12h
dhcp-option=3,$AP_IP
dhcp-option=6,$AP_IP
address=/#/$AP_IP
EOF

echo "[listener-ap] Starting dnsmasq..."
sudo pkill dnsmasq || true
sudo dnsmasq --conf-file="$DNSMASQ_CONF"

echo "[listener-ap] Starting hostapd..."
sudo pkill hostapd || true
sudo hostapd "$HOSTAPD_CONF" -B

echo "[listener-ap] AP up (SSID: $SSID, WPA2)."
echo "[listener-ap] Default password: Listen123 (change via web UI)"
