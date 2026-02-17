<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');

$action = isset($_POST['action']) ? $_POST['action'] : '';

switch ($action) {
  case 'get_sequences':
    $hasWlan1 = file_exists('/sys/class/net/wlan1');
    echo json_encode([
      "success" => true,
      "sequences" => getSequences(),
      "playlists" => getPlaylists(),
      "has_wlan1" => $hasWlan1,
      "current_ssid" => $hasWlan1 ? getCurrentSSID() : ""
    ]);
    break;
  case 'start_sequence':
    echo json_encode(startSequence($_POST['sequence'] ?? ''));
    break;
  case 'stop_playback':
    echo json_encode(stopPlayback());
    break;
  case 'get_ap_config':
    echo json_encode(getAPConfig());
    break;
  case 'change_password':
    echo json_encode(changePassword($_POST['password'] ?? ''));
    break;
  case 'change_ssid':
    echo json_encode(changeSSID($_POST['ssid'] ?? ''));
    break;
  case 'change_ip':
    echo json_encode(changeIP($_POST['ip'] ?? ''));
    break;
  default:
    echo json_encode(["success" => false, "error" => "Unknown action"]);
}


// ── FPP Playback ────────────────────────────────────────────────────

function getPlaylists() {
  $ctx = stream_context_create(['http' => ['timeout' => 2.0]]);
  $raw = @file_get_contents('http://127.0.0.1/api/playlists', false, $ctx);
  if ($raw === false) return [];
  $data = json_decode($raw, true);
  return is_array($data) ? array_values($data) : [];
}


function getSequences() {
  $ctx = stream_context_create(['http' => ['timeout' => 2.0]]);
  $raw = @file_get_contents('http://127.0.0.1/api/sequence', false, $ctx);
  if ($raw === false) return [];
  $data = json_decode($raw, true);
  if (!is_array($data)) return [];
  // Return with .fseq extension for Start Playlist command
  return array_map(function($s) { return $s . '.fseq'; }, array_values($data));
}


function startSequence($name) {
  if ($name === '') {
    return ["success" => false, "error" => "Nothing selected"];
  }
  // "Start Playlist" works for both playlists and .fseq sequences
  return sendFPPCommand([
    "command" => "Start Playlist",
    "args" => [$name]
  ]);
}


function stopPlayback() {
  return sendFPPCommand(["command" => "Stop Now"]);
}


function sendFPPCommand($cmd) {
  $json = json_encode($cmd);
  $opts = [
    'http' => [
      'method' => 'POST',
      'header' => "Content-Type: application/json\r\n",
      'content' => $json,
      'timeout' => 3.0
    ]
  ];
  $ctx = stream_context_create($opts);
  $result = @file_get_contents('http://127.0.0.1/api/command', false, $ctx);
  if ($result === false) {
    return ["success" => false, "error" => "FPP command failed"];
  }
  return ["success" => true];
}


// ── WiFi Access Point ───────────────────────────────────────────────

function getCurrentSSID() {
  $conf = "/home/fpp/listen-sync/hostapd-listener.conf";
  $lines = @file($conf, FILE_IGNORE_NEW_LINES);
  if ($lines === false) return "";
  foreach ($lines as $line) {
    if (strpos($line, 'ssid=') === 0) {
      return substr($line, 5);
    }
  }
  return "";
}


function getAPConfig() {
  $hasWlan1 = file_exists('/sys/class/net/wlan1');

  $result = [
    "success" => true,
    "has_wlan1" => $hasWlan1,
    "ssid" => "",
    "ip" => "192.168.50.1",
    "netmask" => "24",
    "service_active" => false
  ];

  if (!$hasWlan1) return $result;

  // Read SSID from hostapd config
  $result["ssid"] = getCurrentSSID();

  // Read IP from ap.conf
  $apConf = "/home/fpp/listen-sync/ap.conf";
  if (file_exists($apConf)) {
    $lines = file($apConf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines !== false) {
      foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, 'AP_IP=') === 0) {
          $result["ip"] = substr($line, 6);
        }
        if (strpos($line, 'AP_MASK=') === 0) {
          $result["netmask"] = substr($line, 8);
        }
      }
    }
  }

  // Check if service is running
  exec("systemctl is-active listener-ap 2>/dev/null", $out, $ret);
  $result["service_active"] = ($ret === 0);

  return $result;
}


function changePassword($newPass) {
  if (strlen($newPass) < 8 || strlen($newPass) > 63) {
    return ["success" => false, "error" => "Password must be 8-63 characters"];
  }
  // Only allow printable ASCII, no quotes or backslashes
  if (!preg_match('/^[a-zA-Z0-9 !#$%&()*+,\-.\/0-9:;<=>?@\[\]^_`{|}~]+$/', $newPass)) {
    return ["success" => false, "error" => "Password contains invalid characters"];
  }

  $conf = "/home/fpp/listen-sync/hostapd-listener.conf";

  $lines = @file($conf, FILE_IGNORE_NEW_LINES);
  if ($lines === false) {
    return ["success" => false, "error" => "Cannot read config"];
  }

  $newLines = [];
  $found = false;
  foreach ($lines as $line) {
    if (strpos($line, 'wpa_passphrase=') === 0) {
      $newLines[] = "wpa_passphrase=" . $newPass;
      $found = true;
    } else {
      $newLines[] = $line;
    }
  }
  if (!$found) {
    $newLines[] = "wpa_passphrase=" . $newPass;
  }

  $content = implode("\n", $newLines) . "\n";
  $tmpFile = tempnam(sys_get_temp_dir(), 'hostapd_');
  file_put_contents($tmpFile, $content);

  exec("sudo /usr/bin/tee /home/fpp/listen-sync/hostapd-listener.conf < " . escapeshellarg($tmpFile) . " > /dev/null 2>&1", $out, $ret);
  unlink($tmpFile);
  if ($ret !== 0) {
    return ["success" => false, "error" => "Failed to write config"];
  }

  exec("sudo /usr/bin/systemctl restart listener-ap.service 2>&1", $out, $ret);
  if ($ret !== 0) {
    return ["success" => false, "error" => "Failed to restart AP"];
  }

  return ["success" => true, "message" => "Password changed. AP restarting -- reconnect with new password."];
}


function changeSSID($newSSID) {
  $newSSID = trim($newSSID);
  if (strlen($newSSID) < 1 || strlen($newSSID) > 32) {
    return ["success" => false, "error" => "SSID must be 1-32 characters"];
  }
  if (!preg_match('/^[a-zA-Z0-9 _\-]+$/', $newSSID)) {
    return ["success" => false, "error" => "SSID can only contain letters, numbers, spaces, hyphens, underscores"];
  }

  $conf = "/home/fpp/listen-sync/hostapd-listener.conf";
  $lines = @file($conf, FILE_IGNORE_NEW_LINES);
  if ($lines === false) {
    return ["success" => false, "error" => "Cannot read config"];
  }

  $newLines = [];
  $found = false;
  foreach ($lines as $line) {
    if (strpos($line, 'ssid=') === 0) {
      $newLines[] = "ssid=" . $newSSID;
      $found = true;
    } else {
      $newLines[] = $line;
    }
  }
  if (!$found) {
    $newLines[] = "ssid=" . $newSSID;
  }

  $content = implode("\n", $newLines) . "\n";
  $tmpFile = tempnam(sys_get_temp_dir(), 'hostapd_');
  file_put_contents($tmpFile, $content);

  exec("sudo /usr/bin/tee /home/fpp/listen-sync/hostapd-listener.conf < " . escapeshellarg($tmpFile) . " > /dev/null 2>&1", $out, $ret);
  unlink($tmpFile);
  if ($ret !== 0) {
    return ["success" => false, "error" => "Failed to write config"];
  }

  exec("sudo /usr/bin/systemctl restart listener-ap.service 2>&1", $out, $ret);
  if ($ret !== 0) {
    return ["success" => false, "error" => "Failed to restart AP"];
  }

  return ["success" => true, "message" => "SSID changed to \"" . $newSSID . "\". AP restarting -- reconnect to the new network name."];
}


function changeIP($newIP) {
  $newIP = trim($newIP);

  // Validate IPv4 format
  if (!filter_var($newIP, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    return ["success" => false, "error" => "Invalid IPv4 address"];
  }

  // Reject loopback, multicast, 0.0.0.0
  $parts = explode('.', $newIP);
  if ($parts[0] === '0' || $parts[0] === '127' || intval($parts[0]) >= 224) {
    return ["success" => false, "error" => "Reserved IP address"];
  }

  // Read existing netmask from ap.conf (preserve it)
  $apConf = "/home/fpp/listen-sync/ap.conf";
  $mask = "24";
  if (file_exists($apConf)) {
    $lines = @file($apConf, FILE_IGNORE_NEW_LINES);
    if ($lines !== false) {
      foreach ($lines as $line) {
        $line = trim($line);
        if (strpos($line, 'AP_MASK=') === 0) {
          $mask = substr($line, 8);
        }
      }
    }
  }

  $content = "# AP Configuration for FPP Eavesdrop\n";
  $content .= "AP_IP=" . $newIP . "\n";
  $content .= "AP_MASK=" . $mask . "\n";

  $tmpFile = tempnam(sys_get_temp_dir(), 'apconf_');
  file_put_contents($tmpFile, $content);

  exec("sudo /usr/bin/tee /home/fpp/listen-sync/ap.conf < " . escapeshellarg($tmpFile) . " > /dev/null 2>&1", $out, $ret);
  unlink($tmpFile);
  if ($ret !== 0) {
    return ["success" => false, "error" => "Failed to write AP config"];
  }

  exec("sudo /usr/bin/systemctl restart listener-ap.service 2>&1", $out, $ret);
  if ($ret !== 0) {
    return ["success" => false, "error" => "Failed to restart AP"];
  }

  return ["success" => true, "message" => "IP changed to " . $newIP . ". AP restarting -- reconnect and browse to http://" . $newIP . "/listen/"];
}
