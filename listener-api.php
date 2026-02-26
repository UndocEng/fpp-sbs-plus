<?php
// =============================================================================
// listener-api.php - SBS Audio Sync Admin API (Plugin Dashboard Backend)
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
    case 'scan_clients':
        echo json_encode(scanClients());
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
            $readmePath = '/home/fpp/media/plugins/SBSPlus/README.md';
        }
        $content = @file_get_contents($readmePath) ?: '(README.md not found)';
        echo json_encode(['success' => true, 'content' => $content]);
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

    // Check nftables (look for any listener/show AP tables)
    $nft = trim(shell_exec('sudo /usr/sbin/nft list tables 2>/dev/null') ?? '');
    $hasNft = (strpos($nft, 'listener_') !== false || strpos($nft, 'show_ap') !== false);
    $services['nftables'] = $hasNft ? 'active' : 'inactive';

    // Get AP interface and IP
    $iface = getAPInterface();
    $wlanIP = trim(shell_exec("ip addr show $iface 2>/dev/null | grep 'inet ' | awk '{print \$2}'") ?? '');

    // Current SSID from hostapd config
    $ssid = getHostapdValue('ssid');
    $channel = getHostapdValue('channel');

    // Count connected clients from all per-interface lease files
    $clientCount = 0;
    $leaseFiles = glob('/var/lib/misc/dnsmasq-wlan*.leases');
    if (!$leaseFiles) $leaseFiles = ['/var/lib/misc/dnsmasq.leases'];
    foreach ($leaseFiles as $lf) {
        $leases = @file($lf);
        if ($leases) $clientCount += count(array_filter($leases, 'strlen'));
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
    $iface = $_POST['interface'] ?? $_GET['interface'] ?? '';

    // If specific interface requested, return its config from roles.json
    if ($iface && preg_match('/^wlan[0-9]+$/', $iface)) {
        $cfg = getInterfaceConfig($iface);
        $config = [
            'interface' => $iface,
            'ssid'      => $cfg['ssid'] ?? 'EAVESDROP',
            'channel'   => strval($cfg['channel'] ?? 6),
            'password'  => $cfg['password'] ?? '',
            'ap_ip'     => $cfg['ip'] ?? '192.168.50.1',
            'role'      => $cfg['role'] ?? '',
            'interfaces' => getWirelessInterfaces(),
        ];
        $mask = $cfg['mask'] ?? 24;
        $config['subnet_conflict'] = checkSubnetConflict($config['ap_ip'], intval($mask));
        return ['success' => true, 'config' => $config];
    }

    // Legacy: no interface specified, return first AP interface config
    global $hostapdConf;
    $config = [
        'interface' => getHostapdValue('interface') ?: 'wlan0',
        'ssid'      => getHostapdValue('ssid') ?: 'EAVESDROP',
        'channel'   => getHostapdValue('channel') ?: '6',
        'wpa'       => getHostapdValue('wpa') ?: '0',
    ];

    $cfgIface = $config['interface'];
    $ip = getAPConfValue('AP_IP');
    if (!$ip) {
        $ip = trim(shell_exec("ip addr show $cfgIface 2>/dev/null | grep 'inet ' | awk '{print \$2}' | cut -d/ -f1") ?? '');
    }
    $config['ap_ip'] = $ip ?: '192.168.50.1';
    $config['interfaces'] = getWirelessInterfaces();
    $mask = getAPConfValue('AP_MASK') ?: '24';
    $config['subnet_conflict'] = checkSubnetConflict($config['ap_ip'], intval($mask));

    return ['success' => true, 'config' => $config];
}

function saveConfig() {
    global $listenSync;

    $iface   = $_POST['interface'] ?? '';
    $ssid    = $_POST['ssid'] ?? '';
    $channel = $_POST['channel'] ?? '6';
    $password = $_POST['password'] ?? '';
    $apIP    = $_POST['ap_ip'] ?? '192.168.50.1';

    // Validate interface name
    if (!preg_match('/^wlan[0-9]+$/', $iface)) {
        return ['success' => false, 'error' => 'Invalid interface name'];
    }

    // Get the role for this interface
    $role = getRoleForInterface($iface);
    if ($role !== 'sbs' && $role !== 'listener') {
        return ['success' => false, 'error' => 'Interface must be assigned SBS or Listener AP role first'];
    }

    // Validate SSID
    if (strlen($ssid) < 1 || strlen($ssid) > 32) {
        return ['success' => false, 'error' => 'SSID must be 1-32 characters'];
    }

    // Validate channel
    $ch = intval($channel);
    if ($ch < 1 || $ch > 11) {
        return ['success' => false, 'error' => 'Channel must be 1-11'];
    }

    // Validate password: SBS requires WPA2, Listener allows open
    if ($role === 'sbs') {
        if (strlen($password) < 8 || strlen($password) > 63) {
            return ['success' => false, 'error' => 'SBS mode requires a password (8-63 characters)'];
        }
    } else {
        if ($password !== '' && (strlen($password) < 8 || strlen($password) > 63)) {
            return ['success' => false, 'error' => 'Password must be 8-63 characters (or empty for open network)'];
        }
    }

    // Validate IP
    if (!filter_var($apIP, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return ['success' => false, 'error' => 'Invalid IP address'];
    }

    // Update roles.json with the new config
    $roles = loadRoles();
    $roles[$iface] = [
        'role' => $role,
        'ssid' => $ssid,
        'channel' => $ch,
        'password' => $password,
        'ip' => $apIP,
        'mask' => 24,
    ];
    writeRoles($roles);

    // Build per-interface hostapd config
    $apIsolate = ($role === 'listener') ? 1 : 0;
    $wpaBlock = '';
    if ($password !== '') {
        $wpaBlock = "wpa=2\nwpa_passphrase=$password\nwpa_key_mgmt=WPA-PSK\nwpa_pairwise=CCMP\nrsn_pairwise=CCMP";
    } else {
        $wpaBlock = "wpa=0";
    }

    $hostapdContent = <<<CONF
# hostapd-$iface.conf - managed by SBS+ plugin (role: $role)
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
ap_isolate=$apIsolate
CONF;

    $hostapdFile = $listenSync . '/hostapd-' . $iface . '.conf';
    $tmp = tempnam('/tmp', 'hostapd_');
    file_put_contents($tmp, $hostapdContent . "\n");
    exec("sudo /usr/bin/tee $hostapdFile < $tmp > /dev/null 2>&1", $out, $ret);
    unlink($tmp);
    if ($ret !== 0) {
        return ['success' => false, 'error' => 'Failed to write hostapd config'];
    }

    // Handle FPP tether for SBS on wlan0
    if ($role === 'sbs' && $iface === 'wlan0') {
        disableFPPTetherForSBS();
    }

    // Restart listener-ap service (it reads roles.json and configures all APs)
    exec('sudo /usr/bin/systemctl restart listener-ap 2>&1');

    $securityNote = $password !== '' ? "WPA2 ($ssid)" : "Open ($ssid)";
    $roleLabel = $role === 'sbs' ? 'Single Board Show' : 'Listener AP';
    $msg = "$roleLabel restarted: $securityNote on $iface ($apIP)";
    if ($role === 'sbs' && $iface === 'wlan0') $msg .= " FPP tether disabled.";
    return ['success' => true, 'message' => $msg];
}

// =============================================================================
// Connected Clients
// =============================================================================
function getClients() {
    // Accept optional interface parameter for per-card client lists
    $iface = $_POST['interface'] ?? $_GET['interface'] ?? '';
    if (!$iface || !preg_match('/^wlan[0-9]+$/', $iface)) {
        // Fallback: get first AP interface from roles
        $roles = loadRoles();
        foreach ($roles as $k => $v) {
            $r = is_array($v) ? ($v['role'] ?? '') : $v;
            if ($r === 'sbs' || $r === 'listener') {
                $iface = $k;
                break;
            }
        }
        if (!$iface) $iface = 'wlan0';
    }

    $clients = getClientsForInterface($iface);
    return ['success' => true, 'clients' => $clients, 'interface' => $iface];
}

// Scan subnet to discover static-IP devices, then return updated client list
function scanClients() {
    $iface = $_POST['interface'] ?? $_GET['interface'] ?? '';
    if (!$iface || !preg_match('/^wlan[0-9]+$/', $iface)) {
        return ['success' => false, 'error' => 'Interface required'];
    }

    // Get AP's subnet from roles.json
    $roles = loadRoles();
    $cfg = is_array($roles[$iface] ?? null) ? $roles[$iface] : [];
    $apIp = $cfg['ip'] ?? '';
    if (!$apIp || !preg_match('/^(\d+\.\d+\.\d+)\.\d+$/', $apIp, $m)) {
        return ['success' => false, 'error' => 'No AP IP configured'];
    }
    $subnet = $m[1];

    // Targeted ping sweep of DHCP range only (.10-.254), 50 at a time
    for ($batch = 10; $batch <= 254; $batch += 50) {
        $cmds = [];
        $end = min($batch + 49, 254);
        for ($i = $batch; $i <= $end; $i++) {
            $cmds[] = "ping -c1 -W1 {$subnet}.{$i} >/dev/null 2>&1";
        }
        shell_exec("bash -c '" . implode(' & ', $cmds) . " & wait' 2>/dev/null");
    }

    $clients = getClientsForInterface($iface);
    return ['success' => true, 'clients' => $clients, 'interface' => $iface, 'scanned' => true];
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
    $roles = loadRoles();

    // Core services
    foreach (['listener-ap', 'ws-sync'] as $svc) {
        $status = serviceStatus($svc);
        $results[] = ['test' => "$svc service", 'pass' => $status === 'active', 'detail' => $status];
    }

    // Per-interface checks for AP roles
    foreach ($roles as $iface => $cfg) {
        $role = is_array($cfg) ? ($cfg['role'] ?? '') : $cfg;
        if ($role !== 'sbs' && $role !== 'listener') continue;

        $ifaceExists = file_exists("/sys/class/net/$iface");
        $roleLabel = $role === 'sbs' ? 'SBS' : 'Listener';

        if (!$ifaceExists) {
            $results[] = ['test' => "$iface ($roleLabel)", 'pass' => false, 'detail' => 'interface not found'];
            continue;
        }

        // Check interface has IP
        $ip = trim(shell_exec("ip addr show $iface 2>/dev/null | grep 'inet ' | awk '{print \$2}'") ?? '');
        $results[] = ['test' => "$iface IP ($roleLabel)", 'pass' => !empty($ip), 'detail' => $ip ?: 'no IP'];

        // Check hostapd running for this interface
        $hostapdRunning = !empty(trim(shell_exec("pgrep -f 'hostapd.*$iface' 2>/dev/null") ?? ''));
        $results[] = ['test' => "$iface hostapd ($roleLabel)", 'pass' => $hostapdRunning, 'detail' => $hostapdRunning ? 'running' : 'stopped'];
    }

    // nftables check (only needed if listener role exists)
    $hasListener = false;
    foreach ($roles as $iface => $cfg) {
        $r = is_array($cfg) ? ($cfg['role'] ?? '') : $cfg;
        if ($r === 'listener') { $hasListener = true; break; }
    }
    if ($hasListener) {
        $nft = trim(shell_exec('sudo /usr/sbin/nft list tables 2>/dev/null') ?? '');
        $hasNftRules = (strpos($nft, 'listener_') !== false);
        $results[] = ['test' => 'nftables firewall', 'pass' => $hasNftRules, 'detail' => $hasNftRules ? 'active' : 'inactive'];
    }

    // ws-sync port check
    $wsHttp = trim(shell_exec("curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8080/ 2>/dev/null") ?? '');
    $results[] = ['test' => 'ws-sync port 8080', 'pass' => $wsHttp === '426', 'detail' => "HTTP $wsHttp"];

    // Apache /listen/ check
    $http = trim(shell_exec("curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1/listen/ 2>/dev/null") ?? '');
    $results[] = ['test' => '/listen/ page', 'pass' => $http === '200', 'detail' => "HTTP $http"];

    // status.php check
    $http2 = trim(shell_exec("curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1/listen/status.php 2>/dev/null") ?? '');
    $results[] = ['test' => 'status.php', 'pass' => $http2 === '200', 'detail' => "HTTP $http2"];

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

        // Extract role string from extended format
        $roleVal = '';
        if (isset($roles[$name])) {
            $roleVal = is_array($roles[$name]) ? ($roles[$name]['role'] ?? '') : $roles[$name];
        }

        $interfaces[] = [
            'name'      => $name,
            'type'      => $type,
            'label'     => $label,
            'operstate' => $operstate,
            'ip'        => $ip,
            'mac'       => $mac,
            'role'      => $roleVal,
            'wireless'  => $isWireless,
        ];
    }

    return ['success' => true, 'interfaces' => $interfaces];
}

function getRoles() {
    $roles = loadRoles();
    // Return simple role map for dashboard (interface -> role string)
    $simpleRoles = [];
    foreach ($roles as $k => $v) {
        $simpleRoles[$k] = is_array($v) ? ($v['role'] ?? '') : $v;
    }
    return ['success' => true, 'roles' => $simpleRoles];
}

function saveRole() {
    $iface = $_POST['interface'] ?? '';
    $role  = $_POST['role'] ?? '';

    if (!preg_match('/^[a-z0-9]+$/', $iface)) {
        return ['success' => false, 'error' => 'Invalid interface name'];
    }

    $validRoles = ['sbs', 'listener', 'show_network', 'unused', ''];
    if (!in_array($role, $validRoles)) {
        return ['success' => false, 'error' => 'Invalid role'];
    }

    $roles = loadRoles();
    if ($role === '' || $role === 'unused') {
        unset($roles[$iface]);
    } else {
        // Preserve existing config if interface already has settings
        if (isset($roles[$iface]) && is_array($roles[$iface])) {
            $roles[$iface]['role'] = $role;
        } else {
            // New role assignment - set defaults
            $defaults = [
                'sbs' => ['ssid' => 'EAVESDROP', 'channel' => 6, 'password' => 'Listen123', 'ip' => '192.168.40.1', 'mask' => 24],
                'listener' => ['ssid' => 'SHOW_AUDIO', 'channel' => 11, 'password' => '', 'ip' => '192.168.50.1', 'mask' => 24],
                'show_network' => [],
            ];
            $cfg = $defaults[$role] ?? [];
            $cfg['role'] = $role;
            $roles[$iface] = $cfg;
        }
    }

    writeRoles($roles);

    // Extract simple role map for dashboard compatibility
    $simpleRoles = [];
    foreach ($roles as $k => $v) {
        $simpleRoles[$k] = is_array($v) ? ($v['role'] ?? '') : $v;
    }

    return ['success' => true, 'roles' => $simpleRoles];
}

function loadRoles() {
    global $rolesFile;
    $roles = [];
    if (file_exists($rolesFile)) {
        $json = @file_get_contents($rolesFile);
        if ($json) {
            $roles = json_decode($json, true);
            if (!is_array($roles)) $roles = [];
        }
    }

    // Migrate old format: {"wlan0": "listener"} -> {"wlan0": {"role": "listener", ...}}
    $needsMigration = false;
    foreach ($roles as $iface => $val) {
        if (is_string($val)) {
            $needsMigration = true;
            break;
        }
    }

    if ($needsMigration) {
        $roles = migrateRoles($roles);
        writeRoles($roles);
    } elseif (empty($roles) && file_exists('/home/fpp/listen-sync/ap.conf')) {
        // No roles.json but ap.conf exists - migrate from ap.conf
        $roles = migrateFromAPConf();
        if (!empty($roles)) writeRoles($roles);
    }

    return $roles;
}

function migrateRoles($oldRoles) {
    global $listenSync;
    $newRoles = [];

    foreach ($oldRoles as $iface => $val) {
        if (is_string($val)) {
            // Map old role names to new
            $roleMap = ['internet' => 'show_network', 'show' => 'show_network', 'listener' => 'sbs'];
            $role = isset($roleMap[$val]) ? $roleMap[$val] : $val;

            $cfg = ['role' => $role];
            if ($role === 'sbs' || $role === 'listener') {
                // Try to read existing hostapd config for this interface
                $hostapd = $listenSync . '/hostapd-listener.conf';
                if (file_exists($hostapd)) {
                    $hLines = @file($hostapd, FILE_IGNORE_NEW_LINES);
                    if ($hLines) {
                        foreach ($hLines as $line) {
                            if (preg_match('/^ssid=(.+)/', $line, $m)) $cfg['ssid'] = $m[1];
                            if (preg_match('/^channel=(\d+)/', $line, $m)) $cfg['channel'] = intval($m[1]);
                            if (preg_match('/^wpa_passphrase=(.+)/', $line, $m)) $cfg['password'] = $m[1];
                        }
                    }
                }
                $cfg['ip'] = getAPConfValue('AP_IP') ?: '192.168.50.1';
                $cfg['mask'] = intval(getAPConfValue('AP_MASK') ?: 24);
                if (!isset($cfg['ssid'])) $cfg['ssid'] = 'EAVESDROP';
                if (!isset($cfg['channel'])) $cfg['channel'] = 6;
                if (!isset($cfg['password'])) $cfg['password'] = 'Listen123';
            }
            $newRoles[$iface] = $cfg;
        } else {
            $newRoles[$iface] = $val;
        }
    }

    return $newRoles;
}

function migrateFromAPConf() {
    global $listenSync;
    $wlanIF = getAPConfValue('WLAN_IF') ?: 'wlan0';
    $apIP = getAPConfValue('AP_IP') ?: '192.168.50.1';
    $apMask = intval(getAPConfValue('AP_MASK') ?: 24);

    $cfg = [
        'role' => 'sbs',
        'ssid' => 'EAVESDROP',
        'channel' => 6,
        'password' => 'Listen123',
        'ip' => $apIP,
        'mask' => $apMask,
    ];

    // Read existing hostapd config
    $hostapd = $listenSync . '/hostapd-listener.conf';
    if (file_exists($hostapd)) {
        $hLines = @file($hostapd, FILE_IGNORE_NEW_LINES);
        if ($hLines) {
            foreach ($hLines as $line) {
                if (preg_match('/^ssid=(.+)/', $line, $m)) $cfg['ssid'] = $m[1];
                if (preg_match('/^channel=(\d+)/', $line, $m)) $cfg['channel'] = intval($m[1]);
                if (preg_match('/^wpa_passphrase=(.+)/', $line, $m)) $cfg['password'] = $m[1];
            }
        }
    }

    $roles = [$wlanIF => $cfg];

    // Check for show AP config
    $showEnabled = getAPConfValue('SHOW_AP_ENABLED');
    if ($showEnabled === '1') {
        $showIF = ($wlanIF === 'wlan0') ? 'wlan1' : 'wlan0';
        $showSSID = getAPConfValue('SHOW_AP_SSID') ?: 'SHOW_AUDIO';
        $showIP = getAPConfValue('SHOW_AP_IP') ?: '192.168.50.1';
        $showMask = intval(getAPConfValue('SHOW_AP_MASK') ?: 24);
        $roles[$showIF] = [
            'role' => 'listener',
            'ssid' => $showSSID,
            'channel' => 11,
            'password' => '',
            'ip' => $showIP,
            'mask' => $showMask,
        ];
    }

    return $roles;
}

function writeRoles($roles) {
    global $rolesFile;
    $json = json_encode($roles, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $tmp = tempnam('/tmp', 'roles_');
    file_put_contents($tmp, $json . "\n");
    exec("sudo /usr/bin/tee $rolesFile < $tmp > /dev/null 2>&1");
    unlink($tmp);
}

function getRoleForInterface($iface) {
    $roles = loadRoles();
    if (isset($roles[$iface]) && is_array($roles[$iface])) {
        return $roles[$iface]['role'] ?? '';
    }
    return '';
}

function getInterfaceConfig($iface) {
    $roles = loadRoles();
    if (isset($roles[$iface]) && is_array($roles[$iface])) {
        return $roles[$iface];
    }
    return ['role' => '', 'ssid' => '', 'channel' => 6, 'password' => '', 'ip' => '192.168.50.1', 'mask' => 24];
}

// =============================================================================
// WiFi Client Fix - disable ieee80211w after FPP apply
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

    // Read DHCP leases - per-interface file first, fallback to default
    $leaseFile = "/var/lib/misc/dnsmasq-{$iface}.leases";
    if (!file_exists($leaseFile)) {
        $leaseFile = "/var/lib/misc/dnsmasq.leases";
    }
    $leases = @file($leaseFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $leaseMap = [];
    if ($leases !== false) {
        foreach ($leases as $line) {
            $parts = preg_split('/\s+/', $line, 5);
            if (count($parts) >= 4) {
                $leaseMap[strtolower($parts[1])] = [
                    'ip' => $parts[2],
                    'hostname' => ($parts[3] !== '*') ? $parts[3] : '',
                ];
            }
        }
    }

    // Get signal strength from station dump
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

    // Read ARP table for this interface (maps MAC -> IP for static-IP devices)
    $arpMap = [];
    $arp = @file("/proc/net/arp", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($arp !== false) {
        foreach ($arp as $i => $aline) {
            if ($i === 0) continue;
            $parts = preg_split('/\s+/', $aline);
            // Flags 0x2 = complete ARP entry; skip 0x0 (incomplete)
            if (count($parts) >= 6 && $parts[5] === $iface && $parts[2] === '0x2') {
                $arpMap[strtolower($parts[3])] = $parts[0];
            }
        }
    }

    // Build client list from stations (connected to this AP)
    $clients = [];
    foreach ($stations as $mac => $info) {
        $lease = $leaseMap[$mac] ?? null;
        $arpIp = $arpMap[$mac] ?? '';
        $clients[] = [
            'mac' => strtoupper($mac),
            'ip' => $lease ? $lease['ip'] : $arpIp,
            'hostname' => $lease ? $lease['hostname'] : '',
            'signal' => $info['signal'],
            'connected' => $info['connected'],
        ];
    }

    // Add lease entries not in station dump (recently disconnected, still have lease)
    foreach ($leaseMap as $mac => $info) {
        if (!isset($stations[$mac]) && isset($arpMap[$mac])) {
            $clients[] = [
                'mac' => strtoupper($mac),
                'ip' => $info['ip'],
                'hostname' => $info['hostname'],
                'signal' => '',
                'connected' => '',
            ];
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
