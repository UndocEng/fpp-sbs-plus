<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');

$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');

switch ($action) {
  case 'get_sequences':
    $iface = getAPInterface();
    $hasWlan = file_exists('/sys/class/net/wlan0') || file_exists('/sys/class/net/wlan1');
    $hasIface = file_exists('/sys/class/net/' . $iface);
    echo json_encode([
      "success" => true,
      "sequences" => getSequences(),
      "playlists" => getPlaylists(),
      "has_wlan" => $hasWlan,
      "has_wlan1" => $hasWlan,  // compat
      "ap_iface" => $iface,
      "current_ssid" => $hasIface ? getCurrentSSID() : ""
    ]);
    break;
  case 'start_sequence':
    echo json_encode(startSequence($_POST['sequence'] ?? ''));
    break;
  case 'stop_playback':
    echo json_encode(stopPlayback());
    break;
  case 'get_channel_outputs':
    echo json_encode(getChannelOutputs());
    break;
  case 'generate_cal_fseq':
    echo json_encode(generateCalFSEQ(
      intval($_POST['start_ch'] ?? 1),
      intval($_POST['ch_count'] ?? 3),
      intval($_POST['flash_frames'] ?? 5)
    ));
    break;
  case 'cleanup_cal_fseq':
    echo json_encode(cleanupCalFSEQ());
    break;
  case 'bt_scan':
    echo json_encode(btScan());
    break;
  case 'bt_pair':
    echo json_encode(btPair($_POST['mac'] ?? ''));
    break;
  case 'bt_connect':
    echo json_encode(btConnect($_POST['mac'] ?? ''));
    break;
  case 'bt_disconnect':
    echo json_encode(btDisconnect($_POST['mac'] ?? ''));
    break;
  case 'bt_status':
    echo json_encode(btStatus());
    break;
  case 'bt_volume':
    echo json_encode(btVolume(intval($_POST['volume'] ?? 75)));
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


// ── BT Calibration ──────────────────────────────────────────────────

function getChannelOutputs() {
  $ctx = stream_context_create(['http' => ['timeout' => 2.0]]);
  $result = @file_get_contents('http://127.0.0.1/api/channel/output/universeOutputs', false, $ctx);
  if ($result === false) {
    return ["success" => false, "error" => "Cannot reach FPP API"];
  }
  $data = json_decode($result, true);
  if (!is_array($data) || !isset($data['channelOutputs'])) {
    return ["success" => false, "error" => "Invalid API response"];
  }

  $outputs = [];
  foreach ($data['channelOutputs'] as $group) {
    if (empty($group['enabled']) || empty($group['universes'])) continue;
    foreach ($group['universes'] as $uni) {
      $outputs[] = [
        'description' => $uni['description'] ?? '',
        'startChannel' => intval($uni['startChannel'] ?? 1),
        'channelCount' => intval($uni['channelCount'] ?? 512),
        'address' => $uni['address'] ?? '',
        'active' => !empty($uni['active']),
        'id' => intval($uni['id'] ?? 0)
      ];
    }
  }

  return ["success" => true, "outputs" => $outputs];
}


function generateCalFSEQ($startCh, $chCount, $flashFrames) {
  if ($startCh < 1 || $startCh > 32768) {
    return ["success" => false, "error" => "Start channel must be 1-32768"];
  }
  if ($chCount < 1 || $chCount > 512) {
    return ["success" => false, "error" => "Channel count must be 1-512"];
  }
  if ($flashFrames < 1 || $flashFrames > 25) {
    return ["success" => false, "error" => "Flash frames must be 1-25"];
  }

  $maxCh = $startCh + $chCount - 1;
  $channelsPerFrame = max(512, intval(ceil($maxCh / 512)) * 512);
  $fps = 50;
  $stepMs = 20;
  $durationSec = 15;
  $frameCount = $fps * $durationSec; // 750

  // FSEQ v2.0 header (32 bytes)
  $dataOffset = 32;
  $header  = pack('a4', 'PSEQ');            // magic
  $header .= pack('v', $dataOffset);        // channel data start (uint16 LE)
  $header .= pack('C', 0);                  // minor version
  $header .= pack('C', 2);                  // major version
  $header .= pack('v', $dataOffset);        // variable header offset
  $header .= pack('V', $channelsPerFrame);  // channels per frame (uint32 LE)
  $header .= pack('V', $frameCount);        // frame count (uint32 LE)
  $header .= pack('C', $stepMs);            // step time ms
  $header .= pack('C', 0);                  // flags
  $header .= pack('C', 0);                  // compression (0=none)
  $header .= pack('C', 0);                  // compression blocks
  $header .= pack('C', 0);                  // sparse ranges
  $header .= pack('C', 0);                  // flags2
  $header .= str_repeat("\x00", 8);          // UUID

  // Build frame templates
  $blankFrame = str_repeat("\x00", $channelsPerFrame);
  $flashFrame = $blankFrame;
  for ($c = $startCh - 1; $c < $startCh - 1 + $chCount; $c++) {
    $flashFrame[$c] = "\xFF";
  }

  // Build set of flash frame indices (every 1 second, centered)
  $halfFlash = intval($flashFrames / 2);
  $flashSet = [];
  for ($sec = 0; $sec < $durationSec; $sec++) {
    $center = $sec * $fps;
    for ($d = -$halfFlash; $d <= $halfFlash; $d++) {
      $f = $center + $d;
      if ($f >= 0 && $f < $frameCount) {
        $flashSet[$f] = true;
      }
    }
  }

  // Generate frame data
  $data = '';
  for ($f = 0; $f < $frameCount; $f++) {
    $data .= isset($flashSet[$f]) ? $flashFrame : $blankFrame;
  }

  // Write FSEQ file
  $seqDir = '/home/fpp/media/sequences';
  $fseqPath = $seqDir . '/_bt_cal.fseq';
  $fileContent = $header . $data;

  $written = @file_put_contents($fseqPath, $fileContent);
  if ($written === false) {
    // Fall back to temp file + sudo cp
    $tmpFile = tempnam(sys_get_temp_dir(), 'fseq_');
    file_put_contents($tmpFile, $fileContent);
    exec("sudo /bin/cp " . escapeshellarg($tmpFile) . " " . escapeshellarg($fseqPath) . " 2>&1", $out, $ret);
    exec("sudo /bin/chown fpp:fpp " . escapeshellarg($fseqPath) . " 2>&1");
    unlink($tmpFile);
    if ($ret !== 0) {
      return ["success" => false, "error" => "Failed to write FSEQ file"];
    }
  }

  return [
    "success" => true,
    "message" => "Calibration sequence generated",
    "file" => "_bt_cal.fseq",
    "channels" => $startCh . "-" . $maxCh,
    "frames" => $frameCount,
    "duration" => $durationSec . "s",
    "flash_interval" => "1s",
    "flash_frames" => $flashFrames
  ];
}


function cleanupCalFSEQ() {
  $fseqPath = '/home/fpp/media/sequences/_bt_cal.fseq';
  if (file_exists($fseqPath)) {
    $removed = @unlink($fseqPath);
    if (!$removed) {
      exec("sudo /bin/rm -f " . escapeshellarg($fseqPath) . " 2>&1", $out, $ret);
      if ($ret !== 0) {
        return ["success" => false, "error" => "Failed to remove calibration file"];
      }
    }
  }
  return ["success" => true, "message" => "Calibration file removed"];
}


// ── RPi Bluetooth ───────────────────────────────────────────────────

function validateMAC($mac) {
  return preg_match('/^([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/', $mac) === 1;
}


function btScan() {
  // Power on the adapter, scan for 10 seconds, then list devices
  exec("sudo /usr/bin/bluetoothctl power on 2>&1");
  exec("sudo /usr/bin/bluetoothctl --timeout 10 scan on 2>&1", $scanOut, $scanRet);

  // Build a name lookup from scan output
  $scanNames = [];
  foreach ($scanOut as $line) {
    if (preg_match('/\[NEW\]\s+Device\s+([0-9A-Fa-f:]{17})\s+(.+)$/', $line, $m)) {
      $mac = $m[1];
      $n   = trim($m[2]);
      $macDashed = str_replace(':', '-', $mac);
      if ($n !== $macDashed && $n !== $mac) {
        $scanNames[$mac] = $n;
      }
    }
    if (preg_match('/\[CHG\]\s+Device\s+([0-9A-Fa-f:]{17})\s+Name:\s+(.+)$/', $line, $m)) {
      $scanNames[$m[1]] = trim($m[2]);
    }
    if (preg_match('/\[CHG\]\s+Device\s+([0-9A-Fa-f:]{17})\s+Alias:\s+(.+)$/', $line, $m)) {
      $scanNames[$m[1]] = trim($m[2]);
    }
  }

  exec("sudo /usr/bin/bluetoothctl devices 2>&1", $devOut, $devRet);

  $devices = [];
  foreach ($devOut as $line) {
    if (preg_match('/^Device\s+([0-9A-Fa-f:]{17})\s+(.+)$/', $line, $m)) {
      $mac  = $m[1];
      $name = trim($m[2]);
      $macDashed = str_replace(':', '-', $mac);

      if (($name === $macDashed || $name === $mac) && isset($scanNames[$mac])) {
        $name = $scanNames[$mac];
      }

      if ($name === $macDashed || $name === $mac) {
        $infoOut = [];
        exec("sudo /usr/bin/bluetoothctl info " . escapeshellarg($mac) . " 2>&1", $infoOut);
        foreach ($infoOut as $infoLine) {
          $infoLine = trim($infoLine);
          if (preg_match('/^(?:Name|Alias):\s+(.+)$/', $infoLine, $im)) {
            $resolved = trim($im[1]);
            if ($resolved !== $macDashed && $resolved !== $mac) {
              $name = $resolved;
              break;
            }
          }
        }
      }

      $devices[] = ["mac" => $mac, "name" => $name];
    }
  }

  return ["success" => true, "devices" => $devices];
}


function btPair($mac) {
  if (!validateMAC($mac)) {
    return ["success" => false, "error" => "Invalid MAC address"];
  }
  $escapedMAC = escapeshellarg($mac);

  exec("sudo /usr/bin/bluetoothctl pair $escapedMAC 2>&1", $pairOut, $pairRet);
  exec("sudo /usr/bin/bluetoothctl trust $escapedMAC 2>&1", $trustOut, $trustRet);

  $output = implode("\n", array_merge($pairOut, $trustOut));
  $success = (strpos($output, "Pairing successful") !== false) ||
             (strpos($output, "already exists") !== false);

  return [
    "success" => $success,
    "message" => $success ? "Device paired and trusted" : "Pairing may require confirmation on the device",
    "output" => $output
  ];
}


function btConnect($mac) {
  if (!validateMAC($mac)) {
    return ["success" => false, "error" => "Invalid MAC address"];
  }
  $escapedMAC = escapeshellarg($mac);

  exec("sudo /usr/bin/bluetoothctl connect $escapedMAC 2>&1", $out, $ret);
  $output = implode("\n", $out);
  $success = strpos($output, "Connection successful") !== false;

  return [
    "success" => $success,
    "message" => $success ? "Connected" : "Connection failed",
    "output" => $output
  ];
}


function btDisconnect($mac) {
  if (!validateMAC($mac)) {
    return ["success" => false, "error" => "Invalid MAC address"];
  }
  $escapedMAC = escapeshellarg($mac);

  exec("sudo /usr/bin/bluetoothctl disconnect $escapedMAC 2>&1", $out, $ret);
  $output = implode("\n", $out);

  return [
    "success" => true,
    "message" => "Disconnected",
    "output" => $output
  ];
}


function btStatus() {
  // Check for a USB BT adapter (onboard uses hci_uart_bcm, USB uses btusb)
  $hasUsbAdapter = false;
  $hciDevs = glob('/sys/class/bluetooth/hci*');
  foreach ($hciDevs as $hci) {
    $uevent = @file_get_contents($hci . '/device/uevent');
    if ($uevent !== false && strpos($uevent, 'DRIVER=btusb') !== false) {
      $hasUsbAdapter = true;
      break;
    }
  }

  // Check if bluetooth controller is available and powered
  exec("sudo /usr/bin/bluetoothctl show 2>&1", $showOut, $showRet);
  $powered = false;
  $available = ($showRet === 0);
  foreach ($showOut as $line) {
    if (strpos($line, "Powered: yes") !== false) {
      $powered = true;
      break;
    }
  }

  // Get connected devices
  $connected = [];
  if ($available) {
    exec("sudo /usr/bin/bluetoothctl devices Connected 2>&1", $connOut, $connRet);
    foreach ($connOut as $line) {
      if (preg_match('/^Device\s+([0-9A-Fa-f:]{17})\s+(.+)$/', $line, $m)) {
        $mac  = $m[1];
        $name = trim($m[2]);
        $macDashed = str_replace(':', '-', $mac);
        if ($name === $macDashed || $name === $mac) {
          $infoOut = [];
          exec("sudo /usr/bin/bluetoothctl info " . escapeshellarg($mac) . " 2>&1", $infoOut);
          foreach ($infoOut as $infoLine) {
            $infoLine = trim($infoLine);
            if (preg_match('/^(?:Name|Alias):\s+(.+)$/', $infoLine, $im)) {
              $resolved = trim($im[1]);
              if ($resolved !== $macDashed && $resolved !== $mac) {
                $name = $resolved;
                break;
              }
            }
          }
        }
        $connected[] = ["mac" => $mac, "name" => $name];
      }
    }
  }

  // Get current volume if any BT device is connected
  $volume = null;
  if (count($connected) > 0) {
    exec("sudo -u fpp pactl get-sink-volume @DEFAULT_SINK@ 2>&1", $volOut);
    foreach ($volOut as $vl) {
      if (preg_match('/(\d+)%/', $vl, $vm)) {
        $volume = intval($vm[1]);
        break;
      }
    }
  }

  return [
    "success" => true,
    "available" => $available,
    "powered" => $powered,
    "usb_adapter" => $hasUsbAdapter,
    "connected" => $connected,
    "count" => count($connected),
    "volume" => $volume
  ];
}


function btVolume($vol) {
  $vol = max(0, min(100, $vol));
  exec("sudo -u fpp pactl set-sink-volume @DEFAULT_SINK@ " . $vol . "% 2>&1", $out, $ret);
  return [
    "success" => ($ret === 0),
    "volume" => $vol
  ];
}
