<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');

// SBS+ version — single source of truth in the plugin directory
$sbsVer = 'unknown';
$versionFile = '/home/fpp/media/plugins/SBSPlus/VERSION';
if (file_exists($versionFile)) {
  $sbsVer = trim(file_get_contents($versionFile));
}

// FPP version from API
$fppVer = 'unknown';
$data = @json_decode(@file_get_contents('http://127.0.0.1/api/fppd/version'), true);
if (isset($data['version'])) {
  $fppVer = $data['version'];
}

echo json_encode([
  'sbs' => $sbsVer,
  'fpp' => $fppVer
]);
