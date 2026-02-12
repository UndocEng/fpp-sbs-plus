#!/usr/bin/env bash
# listener-ap.sh
# Optional: brings up an open AP and captive portal-like redirect to /listen/
# This is a starting point — adjust wlan device, channel, and IPs to your environment.

set -euo pipefail

WLAN_IF="${WLAN_IF:-wlan1}"
SSID="${SSID:-SHOW_AUDIO}"
CHANNEL="${CHANNEL:-6}"
AP_IP="${AP_IP:-192.168.50.1}"
AP_NET="${AP_NET:-192.168.50.0}"
AP_MASK="${AP_MASK:-24}"

echo "[listener-ap] Using interface: $WLAN_IF"

sudo ip link set "$WLAN_IF" down || true
sudo ip addr flush dev "$WLAN_IF" || true
sudo ip addr add "$AP_IP/$AP_MASK" dev "$WLAN_IF"
sudo ip link set "$WLAN_IF" up

# hostapd config
HOSTAPD_CONF="/tmp/listener-hostapd.conf"
cat > "$HOSTAPD_CONF" <<EOF
interface=$WLAN_IF
driver=nl80211
ssid=$SSID
hw_mode=g
channel=$CHANNEL
wmm_enabled=1
auth_algs=1
ignore_broadcast_ssid=0
EOF

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

echo "[listener-ap] AP up. Clients should be redirected by DNS to $AP_IP."
echo "[listener-ap] Make sure your Apache is listening on $AP_IP and /listen/ exists."
