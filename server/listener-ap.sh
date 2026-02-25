#!/usr/bin/env bash
# =============================================================================
# listener-ap.sh - Role-driven AP manager for FPP Eavesdrop
# =============================================================================
# Reads roles.json and configures each interface according to its assigned role:
#   sbs:          WPA2 admin AP (WLED/show devices join, no isolation)
#   listener:     Isolated public AP (captive portal, nftables firewall, ap_isolate)
#   show_network: Skip (managed by FPP/wpa_supplicant)
#   unused:       Skip
#
# Adjust settings via the Eavesdrop dashboard or /home/fpp/listen-sync/roles.json
# =============================================================================

set -uo pipefail

LISTEN_SYNC="/home/fpp/listen-sync"
ROLES_FILE="$LISTEN_SYNC/roles.json"

echo "[listener-ap] Starting role-driven AP manager..."

# --- Read roles.json ---
if [[ ! -f "$ROLES_FILE" ]]; then
    echo "[listener-ap] No roles.json found - nothing to configure"
    exit 0
fi

# Clean up any previous nftables rules from us
sudo nft delete table inet listener_ap 2>/dev/null || true
sudo nft delete table inet show_ap 2>/dev/null || true

# Remove old rewrite rules
sudo rm -f /home/fpp/listen-sync/show-rewrite.conf 2>/dev/null || true

# Track conntrack marks (increment per AP for policy routing)
MARK_COUNTER=100

# Parse roles.json with python3 (available on all FPP systems)
# Outputs pipe-delimited: interface|role|ssid|channel|password|ip|mask
# Pipe delimiter prevents empty password from collapsing fields
PARSED=$(python3 -c "
import json, sys
try:
    roles = json.load(open('$ROLES_FILE'))
    for iface, cfg in roles.items():
        if isinstance(cfg, str):
            # Old format - treat as SBS with defaults
            print(f'{iface}|{cfg}|EAVESDROP|6|Listen123|192.168.50.1|24')
        elif isinstance(cfg, dict):
            role = cfg.get('role', '')
            ssid = cfg.get('ssid', 'EAVESDROP')
            ch = cfg.get('channel', 6)
            pw = cfg.get('password', '')
            ip = cfg.get('ip', '192.168.50.1')
            mask = cfg.get('mask', 24)
            print(f'{iface}|{role}|{ssid}|{ch}|{pw}|{ip}|{mask}')
except Exception as e:
    print(f'ERROR {e}', file=sys.stderr)
    sys.exit(1)
" 2>&1)

if [[ $? -ne 0 ]]; then
    echo "[listener-ap] ERROR: Failed to parse roles.json: $PARSED"
    exit 1
fi

if [[ -z "$PARSED" ]]; then
    echo "[listener-ap] No interfaces configured in roles.json"
    exit 0
fi

# Track which interfaces have listener role (for rewrite rule generation)
LISTENER_INTERFACES=()

# --- Process each interface ---
while IFS='|' read -r IFACE ROLE SSID CHANNEL PASSWORD IP MASK; do
    [[ -z "$IFACE" || "$IFACE" == "ERROR" ]] && continue

    echo ""
    echo "[listener-ap] === $IFACE: role=$ROLE ==="

    # Skip non-AP roles
    if [[ "$ROLE" != "sbs" && "$ROLE" != "listener" ]]; then
        echo "[listener-ap] Skipping $IFACE (role: $ROLE)"
        continue
    fi

    # Check if interface exists
    if [[ ! -e "/sys/class/net/$IFACE" ]]; then
        echo "[listener-ap] WARNING: $IFACE not found - skipping"
        continue
    fi

    # SBS on wlan0: stop FPP's hostapd to avoid conflict
    if [[ "$ROLE" == "sbs" && "$IFACE" == "wlan0" ]]; then
        echo "[listener-ap] SBS mode on wlan0 - stopping FPP hostapd..."
        sudo systemctl stop hostapd 2>/dev/null || true
        if [[ -f /etc/systemd/network/10-wlan0.network ]]; then
            sudo rm -f /etc/systemd/network/10-wlan0.network
            sudo systemctl restart systemd-networkd 2>/dev/null || true
            echo "[listener-ap] Removed FPP tether network config"
        fi
    fi

    # Configure interface IP
    sudo ip link set "$IFACE" down 2>/dev/null || true
    sudo ip addr flush dev "$IFACE" 2>/dev/null || true
    sudo ip addr add "$IP/$MASK" dev "$IFACE"
    sudo ip link set "$IFACE" up
    /sbin/iw dev "$IFACE" set power_save off 2>/dev/null || true

    # --- Hostapd config ---
    HOSTAPD_FILE="$LISTEN_SYNC/hostapd-${IFACE}.conf"

    if [[ ! -f "$HOSTAPD_FILE" ]]; then
        echo "[listener-ap] Creating hostapd config for $IFACE"
        AP_ISOLATE=0
        WPA_BLOCK="wpa=2\nwpa_passphrase=Listen123\nwpa_key_mgmt=WPA-PSK\nwpa_pairwise=CCMP\nrsn_pairwise=CCMP"

        if [[ "$ROLE" == "listener" ]]; then
            AP_ISOLATE=1
            if [[ -z "$PASSWORD" ]]; then
                WPA_BLOCK="wpa=0"
            fi
        fi

        sudo tee "$HOSTAPD_FILE" > /dev/null <<HOSTAPD_EOF
interface=$IFACE
driver=nl80211
ssid=$SSID
hw_mode=g
channel=$CHANNEL
country_code=US
wmm_enabled=1
ieee80211n=1
auth_algs=1
$(echo -e "$WPA_BLOCK")
ignore_broadcast_ssid=0
ap_isolate=$AP_ISOLATE
HOSTAPD_EOF
        sudo chmod 644 "$HOSTAPD_FILE"
    else
        # Ensure interface line matches
        sudo sed -i "s/^interface=.*/interface=$IFACE/" "$HOSTAPD_FILE"
    fi

    # --- dnsmasq config ---
    IFS='.' read -r o1 o2 o3 o4 <<< "$IP"
    SUBNET="${o1}.${o2}.${o3}"
    DHCP_START="${SUBNET}.10"
    DHCP_END="${SUBNET}.200"

    DNSMASQ_FILE="/tmp/dnsmasq-${IFACE}.conf"
    LEASE_FILE="/var/lib/misc/dnsmasq-${IFACE}.leases"

    if [[ "$ROLE" == "listener" ]]; then
        # Listener: captive portal with wildcard DNS and CAPPORT
        cat > "$DNSMASQ_FILE" <<DNSEOF
interface=$IFACE
except-interface=lo
bind-interfaces
dhcp-leasefile=$LEASE_FILE
dhcp-range=${DHCP_START},${DHCP_END},12h
dhcp-option=3,$IP
dhcp-option=6,$IP
dhcp-option=114,http://$IP/listen/portal-api.php
address=/#/$IP
DNSEOF
    else
        # SBS: standard DHCP + DNS, no wildcard redirect, no captive portal
        cat > "$DNSMASQ_FILE" <<DNSEOF
interface=$IFACE
except-interface=lo
bind-interfaces
dhcp-leasefile=$LEASE_FILE
dhcp-range=${DHCP_START},${DHCP_END},12h
dhcp-option=3,$IP
dhcp-option=6,$IP
DNSEOF
    fi

    # Start dnsmasq for this interface
    echo "[listener-ap] Starting dnsmasq for $IFACE..."
    sudo pkill -f "dnsmasq-${IFACE}.conf" 2>/dev/null || true
    sudo dnsmasq --conf-file="$DNSMASQ_FILE"

    # Start hostapd for this interface
    echo "[listener-ap] Starting hostapd for $IFACE..."
    sudo pkill -f "hostapd-${IFACE}.conf" 2>/dev/null || true
    sudo hostapd "$HOSTAPD_FILE" -B

    # --- Conntrack routing (policy route replies back to correct AP) ---
    MARK_HEX=$(printf '0x%02X' $MARK_COUNTER)
    TABLE_NUM=$MARK_COUNTER

    echo "[listener-ap] Setting up routing for $IFACE (mark=$MARK_HEX, table=$TABLE_NUM)..."
    sudo sysctl -w net.ipv4.ip_forward=1 > /dev/null

    # Conntrack mark for this AP's clients
    sudo nft add table inet "listener_${IFACE}" 2>/dev/null || true
    sudo nft flush table inet "listener_${IFACE}" 2>/dev/null || true
    sudo nft -f - <<NFT
table inet listener_${IFACE} {
  chain prerouting {
    type filter hook prerouting priority mangle; policy accept;
    iifname "$IFACE" ct mark set $MARK_HEX
  }
  chain output {
    type route hook output priority mangle; policy accept;
    ct mark $MARK_HEX meta mark set $MARK_HEX
  }
}
NFT

    # Policy route
    sudo ip rule del fwmark $MARK_HEX table $TABLE_NUM 2>/dev/null || true
    sudo ip rule add fwmark $MARK_HEX table $TABLE_NUM
    sudo ip route replace "${SUBNET}.0/${MASK}" dev "$IFACE" table $TABLE_NUM

    # --- Listener-specific: nftables firewall + captive portal ---
    if [[ "$ROLE" == "listener" ]]; then
        echo "[listener-ap] Setting up listener firewall for $IFACE..."

        sudo nft -f - <<NFT
table inet listener_${IFACE} {
  chain ${IFACE}_input {
    type filter hook input priority 0; policy accept;
    iifname != "$IFACE" accept
    udp dport { 67, 68 } accept
    ip daddr $IP udp dport 53 accept
    ip daddr $IP tcp dport 53 accept
    ip daddr $IP tcp dport { 80, 8080 } accept
    meta l4proto tcp reject with tcp reset
    reject
  }
  chain ${IFACE}_forward {
    type filter hook forward priority 0; policy accept;
    iifname "$IFACE" drop
  }
}
NFT

        # Track for Apache rewrite generation
        LISTENER_INTERFACES+=("$IFACE:$IP:$SUBNET")

        CURRENT_SSID=$(grep '^ssid=' "$HOSTAPD_FILE" 2>/dev/null | cut -d= -f2 || echo "$SSID")
        echo "[listener-ap] Listener AP up on $IFACE (SSID: ${CURRENT_SSID}, IP: $IP, firewalled)"
    else
        CURRENT_SSID=$(grep '^ssid=' "$HOSTAPD_FILE" 2>/dev/null | cut -d= -f2 || echo "$SSID")
        echo "[listener-ap] SBS AP up on $IFACE (SSID: ${CURRENT_SSID}, IP: $IP, WPA2)"
    fi

    MARK_COUNTER=$((MARK_COUNTER + 1))

done <<< "$PARSED"

# --- Generate Apache rewrite rules for all listener APs ---
# Uses <If> with RedirectMatch (mod_alias) - does NOT interfere with FPP's
# mod_rewrite rules in <Directory /opt/fpp/www/api>. Using <Location> with
# RewriteEngine breaks FPP's API because Apache replaces Directory-level
# rewrite rules with Location-level ones (they don't stack).
# The file is IncludeOptional'd from conf-enabled/listener.conf (NOT from
# 000-default.conf which FPP regenerates and would wipe our changes).
if [[ ${#LISTENER_INTERFACES[@]} -gt 0 ]]; then
    SHOW_REWRITE="/home/fpp/listen-sync/show-rewrite.conf"
    echo "[listener-ap] Generating captive portal rewrite rules for ${#LISTENER_INTERFACES[@]} listener AP(s)..."

    {
        echo "# Auto-generated by listener-ap.sh - DO NOT EDIT"
        echo "# Captive portal redirect for listener APs"
        echo "# Uses <If> with RedirectMatch (mod_alias) to avoid breaking FPP API rewrites"
        echo ""

        for ENTRY in "${LISTENER_INTERFACES[@]}"; do
            IFS=':' read -r L_IFACE L_IP L_SUBNET <<< "$ENTRY"
            # Escape dots for Apache regex: 192.168.60 -> 192\.168\.60\.
            L_SUBNET_RE="${L_SUBNET//./\\.}\\."
            L_IP_RE="${L_IP//./\\.}"

            cat <<RWEOF
# --- Listener AP: $L_IFACE ($L_IP) ---
# Redirect when: client is on listener subnet OR is accessing listener IP
# Pass through: /listen/, /music/, /ws, /favicon.ico
<If "%{REMOTE_ADDR} =~ /^${L_SUBNET_RE}/ || %{HTTP_HOST} =~ /^${L_IP_RE}(:|$)/">
    RedirectMatch 302 "^(?!/listen(/|$)|/music(/|$)|/ws$|/favicon\\.ico$)" http://${L_IP}/listen/listen.html
</If>

RWEOF
        done
    } | sudo tee "$SHOW_REWRITE" > /dev/null

    sudo systemctl reload apache2 2>/dev/null || true
    echo "[listener-ap] Captive portal rewrite rules deployed"
else
    # No listener APs - clean up rewrite rules
    sudo rm -f /home/fpp/listen-sync/show-rewrite.conf 2>/dev/null || true
    sudo systemctl reload apache2 2>/dev/null || true
fi

echo ""
echo "[listener-ap] All configured APs started."
