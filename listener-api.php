<?php
// =============================================================================
// listener-api.php — Eavesdrop Admin API (Plugin Dashboard Backend)
// =============================================================================
// Backend for the plugin dashboard. Called via AJAX from dashboard.js.
// Served through FPP's plugin.php handler (nopage=1 mode).
//
// IMPORTANT: This file is NOT named api.php because FPP's API system
// (index.php:addPluginEndpoints) auto-includes any plugin's api.php and
// expects it to define a getEndpoints{name}() function. Having our standalone
// API as api.php causes a PHP Fatal Error that breaks ALL FPP API calls.
//
// Security: .htaccess Rule 3b blocks all 192.168.50.x clients from accessing
// plugin.php. This API is only reachable from LAN/localhost.
// =============================================================================

header('Content-Type: application/json');
header('Cache-Control: no-store');

// Config paths
$listenSync = '/home/fpp/listen-sync';
$pluginDir  = dirname(__FILE__);
$configFile = $listenSync . '/ap.conf';
$hostapdConf = $listenSync . '/hostapd-listener.conf';
$rolesFile  = $listenSync . '/roles.json';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'get_status':
        echo json_encode(getStatus());
        break;
    case 'get_config':
        echo json_encode(getConfig());
        break;
    case 'save_config':
        echo json_encode(saveConfig());
        break;
    case 'get_clients':
        echo json_encode(getClients());
        break;
    case 'get_logs':
        echo json_encode(getLogs());
        break;
    case 'clear_logs':
        echo json_encode(clearLogs());
        break;
    case 'restart_service':
        echo json_encode(restartService());
        break;
    case 'selftest':
        echo json_encode(runSelfTest());
        break;
    case 'get_interfaces':
        echo json_encode(getAllInterfaces());
        break;
    case 'get_roles':
        echo json_encode(getRoles());
        break;
    case 'save_role':
        echo json_encode(saveRole());
        break;
    case 'fix_wifi':
        echo json_encode(fixWifiConnect());
        break;
    case 'get_readme':
        $readmePath = dirname(__FILE__) . '/README.md';
        if (!file_exists($readmePath)) {
            $readmePath = '/home/fpp/media/plugins/fpp-eavesdrop/README.md';
        }
        $content = @file_get_contents($readmePath) ?: '(README.md not found)';
        echo json_encode(['success' => true, 'content' => $content]);
        break;
    // SBS+ Show AP management
    case 'get_show_ap_config':
        echo json_encode(getShowAPConfig());
        break;
    case 'save_show_ap_config':
        echo json_encode(saveShowAPConfig(
            $_POST['enabled'] ?? '0',
            $_POST['ssid'] ?? '',
            $_POST['ip'] ?? ''
        ));
        break;
    case 'get_show_ap_clients':
        echo json_encode(getShowAPClients());
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
}

// =============================================================================
// Status
// =============================================================================
function getStatus() {
    $services = [
        'listener-ap' => serviceStatus('listener-ap'),
        'dnsmasq'     => serviceStatus('dnsmasq'),
        'ws-sync'     => serviceStatus('ws-sync'),
    ];

    // Check nftables
    $nft = trim(shell_exec('sudo /usr/sbin/nft list table inet listener_filter 2>/dev/null') ?? '');
    $services['nftables'] = !empty($nft) ? 'active' : 'inactive';

    // Get AP interface and IP
    $iface = getAPInterface();
    $wlanIP = trim(shell_exec("ip addr show $iface 2>/dev/null | grep 'inet ' | awk '{print \$2}'") ?? '');

    // Current SSID from hostapd config
    $ssid = getHostapdValue('ssid');
    $channel = getHostapdValue('channel');

    // Count connected clients from DHCP leases
    $clientCount = 0;
    $leases = @file('/var/lib/misc/dnsmasq.leases');
    if ($leases) {
        $clientCount = count(array_filter($leases, 'strlen'));
    }

    // FPP tether state
    $tetherState = getFPPTetherState();
    $tetherLabels = ["0" => "conditional", "1" => "enabled", "2" => "disabled"];

    return [
        'success'      => true,
        'services'     => $services,
        'wlanIP'       => $wlanIP,
        'ssid'         => $ssid,
        'channel'      => $channel,
        'interface'    => $iface,
        'clientCount'  => $clientCount,
        'tether_state' => $tetherState,
        'tether_label' => isset($tetherLabels[$tetherState]) ? $tetherLabels[$tetherState] : 'unknown',
    ];
}

// =============================================================================
// Configuration
// =============================================================================
function getConfig() {
    global $hostapdConf;

    $config = [
        'interface' => getHostapdValue('interface') ?: 'wlan1',
        'ssid'      => getHostapdValue('ssid') ?: 'SHOW_AUDIO',
        'channel'   => getHostapdValue('channel') ?: '6',
        'wpa'       => getHostapdValue('wpa') ?: '0',
    ];

    // Detect AP IP from ap.conf or current interface
    $iface = $config['interface'];
    $ip = getAPConfValue('AP_IP');
    if (!$ip) {
        $ip = trim(shell_exec("ip addr show $iface 2>/dev/null | grep 'inet ' | awk '{print \$2}' | cut -d/ -f1") ?? '');
    }
    $config['ap_ip'] = $ip ?: '192.168.50.1';

    // Detect available wireless interfaces
    $config['interfaces'] = getWirelessInterfaces();

    // Check subnet conflict
    $mask = getAPConfValue('AP_MASK') ?: '24';
    $config['subnet_conflict'] = checkSubnetConflict($config['ap_ip'], intval($mask));

    return ['success' => true, 'config' => $config];
}

function saveConfig() {
    global $hostapdConf, $listenSync, $pluginDir;

    $iface   = $_POST['interface'] ?? 'wlan1';
    $ssid    = $_POST['ssid'] ?? 'SHOW_AUDIO';
    $channel = $_POST['channel'] ?? '6';
    $password = $_POST['password'] ?? '';
    $apIP    = $_POST['ap_ip'] ?? '192.168.50.1';

    // Validate interface name (alphanumeric + numbers only)
    if (!preg_match('/^wlan[0-9]+$/', $iface)) {
        return ['success' => false, 'error' => 'Invalid interface name'];
    }

    // Validate SSID (1-32 chars, printable)
    if (strlen($ssid) < 1 || strlen($ssid) > 32) {
        return ['success' => false, 'error' => 'SSID must be 1-32 characters'];
    }

    // Validate channel
    $ch = intval($channel);
    if ($ch < 1 || $ch > 11) {
        return ['success' => false, 'error' => 'Channel must be 1-11'];
    }

    // Validate password (empty = open, or 8-63 chars for WPA2)
    if ($password !== '' && (strlen($password) < 8 || strlen($password) > 63)) {
        return ['success' => false, 'error' => 'Password must be 8-63 characters (or empty for open network)'];
    }

    // Validate IP
    if (!filter_var($apIP, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return ['success' => false, 'error' => 'Invalid IP address'];
    }

    // Build hostapd config
    $wpaBlock = '';
    if ($password !== '') {
        $wpaBlock = "wpa=2\nwpa_passphrase=$password\nwpa_key_mgmt=WPA-PSK\nwpa_pairwise=CCMP";
    } else {
        $wpaBlock = "wpa=0";
    }

    $hostapdContent = <<<CONF
# hostapd-listener.conf — managed by Eavesdrop plugin
interface=$iface
driver=nl80211
ssid=$ssid
hw_mode=g
channel=$ch
country_code=US
wmm_enabled=1
ieee80211n=1
auth_algs=1
$wpaBlock
ignore_broadcast_ssid=0
ap_isolate=1
CONF;

    // Write hostapd config via temp file + sudo tee
    $tmp = tempnam('/tmp', 'hostapd_');
    file_put_contents($tmp, $hostapdContent . "\n");
    exec("sudo /usr/bin/tee $hostapdConf < $tmp > /dev/null 2>&1", $out, $ret);
    unlink($tmp);
    if ($ret !== 0) {
        return ['success' => false, 'error' => 'Failed to write hostapd config'];
    }

    // Update ap.conf with interface and IP
    updateAPConf(['WLAN_IF' => $iface, 'AP_IP' => $apIP]);

    // Build dnsmasq config with the (possibly new) AP IP
    $ipParts = explode('.', $apIP);
    $subnet = $ipParts[0] . '.' . $ipParts[1] . '.' . $ipParts[2];
    $dhcpStart = $subnet . '.10';
    $dhcpEnd   = $subnet . '.250';

    $dnsmasqContent = <<<CONF
# dnsmasq.conf — managed by Eavesdrop plugin
interface=$iface
bind-dynamic
dhcp-range=$dhcpStart,$dhcpEnd,255.255.255.0,12h
listen-address=$apIP
dhcp-option=3,$apIP
dhcp-option=6,$apIP
dhcp-option=114,http://$apIP/listen/portal-api.php
address=/listen.local/$apIP
address=/#/$apIP
log-dhcp
CONF;

    $tmp = tempnam('/tmp', 'dnsmasq_');
    file_put_contents($tmp, $dnsmasqContent . "\n");
    exec("sudo /usr/bin/tee /etc/dnsmasq.conf < $tmp > /dev/null 2>&1", $out, $ret);
    unlink($tmp);

    // Update .htaccess IP references if IP changed
    $htaccess = '/opt/fpp/www/.htaccess';
    if (file_exists($htaccess)) {
        exec("sudo /usr/bin/sed -i 's/192\\.168\\.50\\.1/$apIP/g' $htaccess 2>&1");
        exec("sudo /usr/bin/sed -i 's/192\\\\\\.168\\\\\\.50\\\\\\./$subnet\\\\\\\\./g' $htaccess 2>&1");
    }

    // Update portal-api.php IP reference
    $portalApi = '/home/fpp/media/www/listen/portal-api.php';
    if (file_exists($portalApi)) {
        exec("sudo /usr/bin/sed -i 's/192\\.168\\.50\\.1/$apIP/g' $portalApi 2>&1");
    }

    // Configure interface IP
    exec("sudo /sbin/ip addr flush dev $iface 2>/dev/null");
    exec("sudo /sbin/ip addr add $apIP/24 dev $iface 2>/dev/null");
    exec("sudo /sbin/ip link set $iface up 2>/dev/null");

    // Update nftables with new IP
    updateNftables($iface, $apIP);

    // Handle FPP tether when interface changes
    if ($iface === 'wlan0') {
        disableFPPTetherForSBS();
    } else {
        restoreFPPTether();
    }

    // Restart services
    exec('sudo /usr/bin/systemctl restart dnsmasq 2>&1');
    exec('sudo /usr/bin/systemctl restart listener-ap 2>&1');

    $securityNote = $password !== '' ? "WPA2 ($ssid)" : "Open ($ssid)";
    $msg = "AP restarted: $securityNote on $iface ($apIP)";
    if ($iface === 'wlan0') $msg .= " SBS mode — FPP tether disabled.";
    return ['success' => true, 'message' => $msg];
}

// =============================================================================
// Connected Clients
// =============================================================================
function getClients() {
    $clients = [];

    // Parse DHCP leases: timestamp mac ip hostname client-id
    $leases = @file('/var/lib/misc/dnsmasq.leases');
    $leaseMap = [];
    if ($leases) {
        foreach ($leases as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) >= 4) {
                $leaseMap[strtolower($parts[1])] = [
                    'mac'      => strtoupper($parts[1]),
                    'ip'       => $parts[2],
                    'hostname' => $parts[3] !== '*' ? $parts[3] : '',
                    'expires'  => date('H:i:s', intval($parts[0])),
                ];
            }
        }
    }

    // Get signal strength from hostapd
    $iface = getAPInterface();
    $staDump = shell_exec("sudo /usr/sbin/iw dev " . escapeshellarg($iface) . " station dump 2>/dev/null") ?? '';
    $stations = [];
    $currentMac = '';
    foreach (explode("\n", $staDump) as $line) {
        if (preg_match('/^Station\s+([0-9a-f:]+)/i', $line, $m)) {
            $currentMac = strtolower($m[1]);
            $stations[$currentMac] = ['signal' => '', 'connected' => ''];
        } elseif ($currentMac) {
            if (preg_match('/signal:\s+(-?\d+)\s+dBm/', $line, $m)) {
                $stations[$currentMac]['signal'] = $m[1] . ' dBm';
            }
            if (preg_match('/connected time:\s+(\d+)\s+seconds/', $line, $m)) {
                $secs = intval($m[1]);
                $stations[$currentMac]['connected'] = sprintf('%02d:%02d:%02d', $secs / 3600, ($secs % 3600) / 60, $secs % 60);
            }
        }
    }

    // Merge lease data + station data
    foreach ($leaseMap as $mac => $info) {
        $signal = $stations[$mac]['signal'] ?? '';
        $connected = $stations[$mac]['connected'] ?? '';
        $clients[] = [
            'mac'       => $info['mac'],
            'ip'        => $info['ip'],
            'hostname'  => $info['hostname'],
            'signal'    => $signal,
            'connected' => $connected,
        ];
    }

    // Add stations not in leases (rare, but possible)
    foreach ($stations as $mac => $info) {
        if (!isset($leaseMap[$mac])) {
            $clients[] = [
                'mac'       => strtoupper($mac),
                'ip'        => '',
                'hostname'  => '',
                'signal'    => $info['signal'],
                'connected' => $info['connected'],
            ];
        }
    }

    return ['success' => true, 'clients' => $clients];
}

// =============================================================================
// Logs
// =============================================================================
function getLogs() {
    $source = $_POST['source'] ?? $_GET['source'] ?? 'ws-sync';
    $lines  = intval($_POST['lines'] ?? $_GET['lines'] ?? 50);
    $lines  = max(10, min($lines, 200));

    $allowed = ['ws-sync', 'listener-ap', 'dnsmasq', 'hostapd', 'sync'];
    if (!in_array($source, $allowed)) {
        return ['success' => false, 'error' => 'Invalid log source'];
    }

    if ($source === 'sync') {
        $logFile = '/home/fpp/listen-sync/sync.log';
        if (file_exists($logFile)) {
            $output = shell_exec("tail -n $lines $logFile 2>&1") ?? '';
        } else {
            $output = '(no sync.log found)';
        }
    } else {
        $output = shell_exec("journalctl -u $source -n $lines --no-pager 2>&1") ?? '';
    }

    return ['success' => true, 'source' => $source, 'log' => $output];
}

function clearLogs() {
    $source = $_POST['source'] ?? '';
    $allowed = ['ws-sync', 'listener-ap', 'dnsmasq', 'sync'];
    if (!in_array($source, $allowed)) {
        return ['success' => false, 'error' => 'Invalid log source'];
    }

    if ($source === 'sync') {
        $logFile = '/home/fpp/listen-sync/sync.log';
        if (file_exists($logFile)) {
            file_put_contents($logFile, '');
        }
    } else {
        exec("sudo journalctl --rotate 2>&1");
        exec("sudo journalctl --vacuum-time=1s -u $source 2>&1");
    }

    return ['success' => true, 'message' => "Logs cleared for $source"];
}

// =============================================================================
// Service Control
// =============================================================================
function restartService() {
    $service = $_POST['service'] ?? '';
    $allowed = ['listener-ap', 'dnsmasq', 'ws-sync'];
    if (!in_array($service, $allowed)) {
        return ['success' => false, 'error' => 'Invalid service name'];
    }
    exec("sudo /usr/bin/systemctl restart $service 2>&1", $out, $ret);
    return ['success' => $ret === 0, 'message' => $ret === 0 ? "$service restarted" : "Failed to restart $service"];
}

// =============================================================================
// Self-Test
// =============================================================================
function runSelfTest() {
    $results = [];

    // Check if the listener AP interface exists
    $iface = getAPInterface();
    $ifaceExists = !empty(trim(shell_exec("ip link show $iface 2>/dev/null") ?? ''));

    // Service checks — skip listener-ap if AP interface doesn't exist
    foreach (['listener-ap', 'dnsmasq', 'ws-sync'] as $svc) {
        if ($svc === 'listener-ap' && !$ifaceExists) continue;
        $status = serviceStatus($svc);
        $results[] = ['test' => "$svc service", 'pass' => $status === 'active', 'detail' => $status];
    }

    // AP interface IP check — skip if interface doesn't exist
    if ($ifaceExists) {
        $ip = trim(shell_exec("ip addr show $iface 2>/dev/null | grep 'inet ' | awk '{print \$2}'") ?? '');
        $results[] = ['test' => "$iface IP", 'pass' => !empty($ip), 'detail' => $ip ?: 'no IP'];
    }

    // nftables check
    $nft = trim(shell_exec('sudo /usr/sbin/nft list table inet listener_filter 2>/dev/null') ?? '');
    $results[] = ['test' => 'nftables firewall', 'pass' => !empty($nft), 'detail' => !empty($nft) ? 'active' : 'inactive'];

    // ws-sync port check
    $wsHttp = trim(shell_exec("curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8080/ 2>/dev/null") ?? '');
    $results[] = ['test' => 'ws-sync port 8080', 'pass' => $wsHttp === '426', 'detail' => "HTTP $wsHttp"];

    // Apache /listen/ check
    $http = trim(shell_exec("curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1/listen/ 2>/dev/null") ?? '');
    $results[] = ['test' => '/listen/ page', 'pass' => $http === '200', 'detail' => "HTTP $http"];

    // status.php check
    $http2 = trim(shell_exec("curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1/listen/status.php 2>/dev/null") ?? '');
    $results[] = ['test' => 'status.php', 'pass' => $http2 === '200', 'detail' => "HTTP $http2"];

    // Show AP check (if enabled)
    $showConfig = getShowAPConfig();
    if ($showConfig['enabled']) {
        $showIface = $showConfig['show_iface'];
        $showRunning = $showConfig['running'];
        $results[] = ['test' => "Show AP ($showIface)", 'pass' => $showRunning, 'detail' => $showRunning ? 'running' : 'stopped'];
    }

    $allPass = count(array_filter($results, function($r) { return !$r['pass']; })) === 0;
    return ['success' => true, 'allPass' => $allPass, 'results' => $results];
}

// =============================================================================
// Interface & Role Management
// =============================================================================
function getAllInterfaces() {
    $roles = loadRoles();
    $interfaces = [];

    // Get all non-loopback interfaces from /sys/class/net
    $sysNet = glob('/sys/class/net/*');
    if (!$sysNet) return ['success' => true, 'interfaces' => []];

    foreach ($sysNet as $path) {
        $name = basename($path);
        if ($name === 'lo') continue;

        // Determine type
        $isWireless = is_dir($path . '/wireless');
        $type = 'ethernet';
        if ($isWireless) {
            $driver = @readlink($path . '/device/driver');
            $driver = $driver ? basename($driver) : '';
            $isUSB = (strpos(@readlink($path . '/device'), 'usb') !== false);
            $type = $isUSB ? 'wifi-usb' : 'wifi';
        }

        // Get current state
        $operstate = trim(@file_get_contents($path . '/operstate') ?: 'unknown');
        $ip = trim(shell_exec("ip addr show $name 2>/dev/null | grep 'inet ' | awk '{print \$2}' | cut -d/ -f1") ?? '');
        $mac = trim(@file_get_contents($path . '/address') ?: '');

        // Friendly label
        $label = $name;
        if ($type === 'ethernet') $label = "$name (Ethernet)";
        elseif ($type === 'wifi') $label = "$name (WiFi)";
        elseif ($type === 'wifi-usb') $label = "$name (USB WiFi)";

        $interfaces[] = [
            'name'      => $name,
            'type'      => $type,
            'label'     => $label,
            'operstate' => $operstate,
            'ip'        => $ip,
            'mac'       => $mac,
            'role'      => $roles[$name] ?? '',
            'wireless'  => $isWireless,
        ];
    }

    return ['success' => true, 'interfaces' => $interfaces];
}

function getRoles() {
    return ['success' => true, 'roles' => loadRoles()];
}

function saveRole() {
    global $rolesFile;

    $iface = $_POST['interface'] ?? '';
    $role  = $_POST['role'] ?? '';

    if (!preg_match('/^[a-z0-9]+$/', $iface)) {
        return ['success' => false, 'error' => 'Invalid interface name'];
    }

    $validRoles = ['internet', 'show', 'listener', 'unused', ''];
    if (!in_array($role, $validRoles)) {
        return ['success' => false, 'error' => 'Invalid role'];
    }

    $roles = loadRoles();
    if ($role === '' || $role === 'unused') {
        unset($roles[$iface]);
    } else {
        $roles[$iface] = $role;
    }

    // Write roles file
    $json = json_encode($roles, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $tmp = tempnam('/tmp', 'roles_');
    file_put_contents($tmp, $json . "\n");
    exec("sudo /usr/bin/tee $rolesFile < $tmp > /dev/null 2>&1", $out, $ret);
    unlink($tmp);

    return ['success' => true, 'roles' => $roles];
}

function loadRoles() {
    global $rolesFile;
    if (!file_exists($rolesFile)) return [];
    $json = @file_get_contents($rolesFile);
    if (!$json) return [];
    $roles = json_decode($json, true);
    return is_array($roles) ? $roles : [];
}

// =============================================================================
// WiFi Client Fix — disable ieee80211w after FPP apply
// =============================================================================
function fixWifiConnect() {
    $iface = $_POST['interface'] ?? '';
    if (!preg_match('/^wlan[0-9]+$/', $iface)) {
        return ['success' => false, 'error' => 'Invalid interface name'];
    }

    $out1 = trim(shell_exec("sudo /usr/sbin/wpa_cli -i $iface set_network 0 ieee80211w 0 2>&1") ?? '');
    $out2 = trim(shell_exec("sudo /usr/sbin/wpa_cli -i $iface reassociate 2>&1") ?? '');

    sleep(3);
    $status = trim(shell_exec("sudo /usr/sbin/wpa_cli -i $iface status 2>&1") ?? '');
    $state = '';
    if (preg_match('/wpa_state=(\S+)/', $status, $m)) {
        $state = $m[1];
    }

    return [
        'success' => true,
        'state' => $state,
        'detail' => "ieee80211w=$out1, reassociate=$out2"
    ];
}

// =============================================================================
// SBS+ Show AP Management
// =============================================================================
function getShowAPConfig() {
    $apIface = getAPInterface();
    $showIface = ($apIface === 'wlan0') ? 'wlan1' : 'wlan0';
    $hasShowIface = file_exists('/sys/class/net/' . $showIface);

    $result = [
        "success" => true,
        "enabled" => false,
        "ssid" => "SHOW_AUDIO",
        "ip" => "192.168.60.1",
        "mask" => "24",
        "show_iface" => $showIface,
        "has_show_iface" => $hasShowIface,
        "running" => false,
        "ap_iface" => $apIface
    ];

    // Read show AP config from ap.conf
    $apConf = "/home/fpp/listen-sync/ap.conf";
    if (file_exists($apConf)) {
        $lines = file($apConf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines !== false) {
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') continue;
                if (strpos($line, 'SHOW_AP_ENABLED=') === 0) {
                    $result["enabled"] = (substr($line, 16) === '1');
                }
                if (strpos($line, 'SHOW_AP_SSID=') === 0) {
                    $result["ssid"] = substr($line, 13);
                }
                if (strpos($line, 'SHOW_AP_IP=') === 0) {
                    $result["ip"] = substr($line, 11);
                }
                if (strpos($line, 'SHOW_AP_MASK=') === 0) {
                    $result["mask"] = substr($line, 13);
                }
            }
        }
    }

    // Check if show AP hostapd is running
    exec("pgrep -f hostapd-show.conf 2>/dev/null", $out, $ret);
    $result["running"] = ($ret === 0);

    return $result;
}

function saveShowAPConfig($enabled, $ssid, $ip) {
    $enabled = ($enabled === '1' || $enabled === 'true') ? '1' : '0';
    $ssid = trim($ssid);
    $ip = trim($ip);

    // Validate SSID if provided
    if ($ssid !== '') {
        if (strlen($ssid) < 1 || strlen($ssid) > 32) {
            return ["success" => false, "error" => "SSID must be 1-32 characters"];
        }
        if (!preg_match('/^[a-zA-Z0-9 _\-]+$/', $ssid)) {
            return ["success" => false, "error" => "SSID can only contain letters, numbers, spaces, hyphens, underscores"];
        }
    }

    // Validate IP if provided
    if ($ip !== '') {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return ["success" => false, "error" => "Invalid IPv4 address"];
        }
        $parts = explode('.', $ip);
        if ($parts[0] === '0' || $parts[0] === '127' || intval($parts[0]) >= 224) {
            return ["success" => false, "error" => "Reserved IP address"];
        }
    }

    // Update ap.conf (preserve all existing lines, update show AP fields)
    $apConf = "/home/fpp/listen-sync/ap.conf";
    $lines = @file($apConf, FILE_IGNORE_NEW_LINES);
    $newLines = [];
    $foundEnabled = false;
    $foundSSID = false;
    $foundIP = false;

    if ($lines !== false) {
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (strpos($trimmed, 'SHOW_AP_ENABLED=') === 0) {
                $newLines[] = "SHOW_AP_ENABLED=" . $enabled;
                $foundEnabled = true;
            } elseif ($ssid !== '' && strpos($trimmed, 'SHOW_AP_SSID=') === 0) {
                $newLines[] = "SHOW_AP_SSID=" . $ssid;
                $foundSSID = true;
            } elseif ($ip !== '' && strpos($trimmed, 'SHOW_AP_IP=') === 0) {
                $newLines[] = "SHOW_AP_IP=" . $ip;
                $foundIP = true;
            } else {
                $newLines[] = $line;
            }
        }
    }

    if (!$foundEnabled) $newLines[] = "SHOW_AP_ENABLED=" . $enabled;
    if ($ssid !== '' && !$foundSSID) $newLines[] = "SHOW_AP_SSID=" . $ssid;
    if ($ip !== '' && !$foundIP) $newLines[] = "SHOW_AP_IP=" . $ip;

    $content = implode("\n", $newLines) . "\n";
    $tmpFile = tempnam(sys_get_temp_dir(), 'apconf_');
    file_put_contents($tmpFile, $content);
    exec("sudo /usr/bin/tee /home/fpp/listen-sync/ap.conf < " . escapeshellarg($tmpFile) . " > /dev/null 2>&1", $out, $ret);
    unlink($tmpFile);
    if ($ret !== 0) {
        return ["success" => false, "error" => "Failed to write AP config"];
    }

    // Update hostapd-show.conf SSID if changed
    if ($ssid !== '') {
        $showHostapd = "/home/fpp/listen-sync/hostapd-show.conf";
        $hLines = @file($showHostapd, FILE_IGNORE_NEW_LINES);
        if ($hLines !== false) {
            $hNewLines = [];
            foreach ($hLines as $line) {
                if (strpos($line, 'ssid=') === 0) {
                    $hNewLines[] = "ssid=" . $ssid;
                } else {
                    $hNewLines[] = $line;
                }
            }
            $hContent = implode("\n", $hNewLines) . "\n";
            $tmpFile2 = tempnam(sys_get_temp_dir(), 'hostapd_');
            file_put_contents($tmpFile2, $hContent);
            exec("sudo /usr/bin/tee /home/fpp/listen-sync/hostapd-show.conf < " . escapeshellarg($tmpFile2) . " > /dev/null 2>&1", $out2, $ret2);
            unlink($tmpFile2);
        }
    }

    // Restart service
    exec("sudo /usr/bin/systemctl restart listener-ap.service 2>&1", $out3, $ret3);

    $msg = $enabled === '1' ? "Show AP enabled" : "Show AP disabled";
    $msg .= ". Service restarting.";
    if ($ssid !== '') $msg .= " SSID: " . $ssid . ".";
    if ($ip !== '') $msg .= " IP: " . $ip . ".";

    return ["success" => true, "message" => $msg];
}

function getShowAPClients() {
    $apIface = getAPInterface();
    $showIface = ($apIface === 'wlan0') ? 'wlan1' : 'wlan0';
    $clients = getClientsForInterface($showIface);
    return ["success" => true, "clients" => $clients, "count" => count($clients)];
}

// =============================================================================
// Helpers
// =============================================================================
function serviceStatus($name) {
    $status = trim(shell_exec("systemctl is-active $name 2>/dev/null") ?? '');
    return $status ?: 'unknown';
}

function getHostapdValue($key) {
    global $hostapdConf;
    if (!file_exists($hostapdConf)) return '';
    $lines = file($hostapdConf);
    foreach ($lines as $line) {
        $line = trim($line);
        if (strpos($line, '#') === 0) continue;
        if (preg_match('/^' . preg_quote($key, '/') . '\s*=\s*(.+)$/', $line, $m)) {
            return trim($m[1]);
        }
    }
    return '';
}

function getWirelessInterfaces() {
    $interfaces = [];
    $sysNet = glob('/sys/class/net/wlan*');
    if ($sysNet) {
        foreach ($sysNet as $path) {
            $name = basename($path);
            $driver = @readlink($path . '/device/driver');
            $driver = $driver ? basename($driver) : 'unknown';
            $interfaces[] = ['name' => $name, 'driver' => $driver];
        }
    }
    return $interfaces;
}

function getAPInterface() {
    $apConf = "/home/fpp/listen-sync/ap.conf";
    if (file_exists($apConf)) {
        $lines = file($apConf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines !== false) {
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') continue;
                if (strpos($line, 'WLAN_IF=') === 0) {
                    return substr($line, 8);
                }
            }
        }
    }
    return 'wlan1';
}

function getAPConfValue($key) {
    $apConf = "/home/fpp/listen-sync/ap.conf";
    if (!file_exists($apConf)) return '';
    $lines = file($apConf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return '';
    $prefix = $key . '=';
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, $prefix) === 0) {
            return substr($line, strlen($prefix));
        }
    }
    return '';
}

function updateAPConf($values) {
    $apConf = "/home/fpp/listen-sync/ap.conf";
    $lines = @file($apConf, FILE_IGNORE_NEW_LINES);
    $newLines = [];
    $found = [];

    if ($lines !== false) {
        foreach ($lines as $line) {
            $replaced = false;
            foreach ($values as $key => $val) {
                if (strpos(trim($line), $key . '=') === 0) {
                    $newLines[] = $key . '=' . $val;
                    $found[$key] = true;
                    $replaced = true;
                    break;
                }
            }
            if (!$replaced) {
                $newLines[] = $line;
            }
        }
    }

    // Append any values not found in existing file
    foreach ($values as $key => $val) {
        if (!isset($found[$key])) {
            $newLines[] = $key . '=' . $val;
        }
    }

    $content = implode("\n", $newLines) . "\n";
    $tmpFile = tempnam(sys_get_temp_dir(), 'apconf_');
    file_put_contents($tmpFile, $content);
    exec("sudo /usr/bin/tee /home/fpp/listen-sync/ap.conf < " . escapeshellarg($tmpFile) . " > /dev/null 2>&1", $out, $ret);
    unlink($tmpFile);
    return $ret === 0;
}

function updateNftables($iface, $apIP) {
    $nft = '/usr/sbin/nft';
    if (!is_executable($nft)) return;

    // Clear existing rules
    exec("sudo $nft delete table inet listener_filter 2>/dev/null");

    // Recreate with new IP
    exec("sudo $nft add table inet listener_filter");
    exec("sudo $nft add chain inet listener_filter wlan1_input '{ type filter hook input priority 0; policy accept; }'");
    exec("sudo $nft add rule inet listener_filter wlan1_input iifname $iface udp dport '{67, 68}' accept");
    exec("sudo $nft add rule inet listener_filter wlan1_input iifname $iface ip daddr $apIP udp dport 53 accept");
    exec("sudo $nft add rule inet listener_filter wlan1_input iifname $iface ip daddr $apIP tcp dport 53 accept");
    exec("sudo $nft add rule inet listener_filter wlan1_input iifname $iface ip daddr $apIP tcp dport '{80, 8080}' accept");
    exec("sudo $nft add rule inet listener_filter wlan1_input iifname $iface meta l4proto tcp reject with tcp reset");
    exec("sudo $nft add rule inet listener_filter wlan1_input iifname $iface reject");
}

function checkSubnetConflict($apIP, $cidr) {
    $apLong = ip2long($apIP);
    if ($apLong === false) return null;
    $mask = $cidr > 0 ? (~0 << (32 - $cidr)) : 0;

    $output = [];
    exec("ip -4 addr show 2>/dev/null", $output);
    $conflicts = [];
    $currentIface = '';
    $apIface = getAPInterface();
    foreach ($output as $line) {
        if (preg_match('/^\d+:\s+(\S+):/', $line, $m)) {
            $currentIface = $m[1];
        }
        if (preg_match('/inet\s+(\d+\.\d+\.\d+\.\d+)\/(\d+)/', $line, $m)) {
            $ifIP = $m[1];
            $ifCidr = intval($m[2]);
            if ($currentIface === $apIface || $currentIface === 'lo') continue;
            $ifLong = ip2long($ifIP);
            if ($ifLong === false) continue;
            $ifMask = $ifCidr > 0 ? (~0 << (32 - $ifCidr)) : 0;
            $checkMask = ($mask & $ifMask);
            if (($apLong & $checkMask) === ($ifLong & $checkMask)) {
                $conflicts[] = $currentIface . " (" . $ifIP . "/" . $ifCidr . ")";
            }
        }
    }
    if (empty($conflicts)) return null;
    return "AP subnet overlaps with: " . implode(", ", $conflicts);
}

function getClientsForInterface($iface) {
    if (!file_exists('/sys/class/net/' . $iface)) {
        return [];
    }

    $clients = [];

    // Read DHCP leases
    $leases = @file("/var/lib/misc/dnsmasq.leases", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $leaseMap = [];
    if ($leases !== false) {
        foreach ($leases as $line) {
            $parts = preg_split('/\s+/', $line, 5);
            if (count($parts) >= 4) {
                $leaseMap[strtolower($parts[1])] = [
                    "ip" => $parts[2],
                    "hostname" => ($parts[3] !== '*') ? $parts[3] : ''
                ];
            }
        }
    }

    // Read ARP table for interface
    $arp = @file("/proc/net/arp", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($arp !== false) {
        foreach ($arp as $i => $line) {
            if ($i === 0) continue;
            $parts = preg_split('/\s+/', $line);
            if (count($parts) >= 6 && $parts[5] === $iface && $parts[2] !== '0x0') {
                $mac = strtolower($parts[3]);
                $client = [
                    "mac" => $mac,
                    "ip" => $parts[0],
                    "hostname" => ""
                ];
                if (isset($leaseMap[$mac])) {
                    $client["hostname"] = $leaseMap[$mac]["hostname"];
                }
                $clients[] = $client;
            }
        }
    }

    return $clients;
}

// =============================================================================
// FPP Tether Management
// =============================================================================
function getFPPTetherState() {
    $ctx = stream_context_create(['http' => ['timeout' => 2.0]]);
    $raw = @file_get_contents('http://127.0.0.1/api/settings/EnableTethering', false, $ctx);
    if ($raw === false) return null;
    $val = json_decode($raw, true);
    if (is_array($val) && isset($val['value'])) return strval($val['value']);
    if (is_string($val)) return $val;
    return null;
}

function setFPPTether($mode) {
    $opts = [
        'http' => [
            'method' => 'PUT',
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode($mode),
            'timeout' => 3.0
        ]
    ];
    $ctx = stream_context_create($opts);
    $result = @file_get_contents('http://127.0.0.1/api/settings/EnableTethering', false, $ctx);
    if ($result === false) return false;
    $data = json_decode($result, true);
    return isset($data['status']) && $data['status'] === 'OK';
}

function disableFPPTetherForSBS() {
    setFPPTether("2");
    exec("sudo /usr/bin/systemctl stop hostapd 2>/dev/null", $out, $ret);
}

function restoreFPPTether() {
    setFPPTether("0");
}
