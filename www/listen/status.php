<?php
// status.php
// Returns JSON describing what FPP is doing + a server timestamp.
// Clients poll this at 4Hz for smooth sync behavior.
//
// Output fields:
//   ok (bool)          -> true if we can determine a playable track/position
//   playing (bool)     -> whether show is currently playing
//   track (string)     -> audio filename (e.g. MySong.mp3)
//   pos_ms (int)       -> position into track in milliseconds
//   server_ms (int)    -> monotonic-ish ms (not epoch) from PHP process start
//   wall_ms (int)      -> epoch ms (Date.now()-compatible)
//   src (string)       -> audio URL for the client to load (relative)

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$start = microtime(true);

// Server clocks
$server_ms = (int) round(microtime(true) * 1000);
$wall_ms   = (int) round((microtime(true) + (time() - microtime(true))) * 1000); // epoch-ish ms

// FPP status endpoint (local)
$fpp_url = 'http://127.0.0.1/api/fppd/status';

function http_get_json($url) {
  $ctx = stream_context_create([
    'http' => [
      'timeout' => 1.5,
      'ignore_errors' => true,
      'header' => "Accept: application/json\r\n"
    ]
  ]);
  $raw = @file_get_contents($url, false, $ctx);
  if ($raw === false) return null;
  $data = json_decode($raw, true);
  if (!is_array($data)) return null;
  return $data;
}

// Attempt to infer play state + elapsed time + media/sequence name from various FPP payload shapes.
// You may customize this for your specific FPP version if desired.
$data = http_get_json($fpp_url);

$playing = false;
$elapsed_s = null;
$base = null;

// Heuristics for "playing"
if (is_array($data)) {
  if (isset($data['status'])) {
    $playing = ($data['status'] === 'playing');
  } elseif (isset($data['playing'])) {
    $playing = (bool)$data['playing'];
  }
}

// Heuristics for elapsed seconds
$elapsed_candidates = ['elapsed', 'seconds_elapsed', 'elapsedSeconds', 'timeElapsed', 'elapsed_time'];
foreach ($elapsed_candidates as $k) {
  if (isset($data[$k]) && is_numeric($data[$k])) { $elapsed_s = (float)$data[$k]; break; }
}

// Sometimes FPP nests time fields
if ($elapsed_s === null && isset($data['time']) && is_array($data['time'])) {
  foreach (['elapsed', 'seconds_elapsed'] as $k) {
    if (isset($data['time'][$k]) && is_numeric($data['time'][$k])) { $elapsed_s = (float)$data['time'][$k]; break; }
  }
}

// Identify current item / sequence base name.
// Many installations: audio file name matches sequence base name.
// We try multiple known keys.
$name_candidates = [
  'song', 'current_song', 'media', 'sequence', 'current_sequence', 'fseq', 'filename', 'mediaFilename'
];

foreach ($name_candidates as $k) {
  if (!isset($data[$k])) continue;

  $v = $data[$k];

  if (is_string($v) && strlen($v)) { $base = $v; break; }
  if (is_array($v)) {
    foreach (['file','filename','name','media','sequence'] as $kk) {
      if (isset($v[$kk]) && is_string($v[$kk]) && strlen($v[$kk])) { $base = $v[$kk]; break 2; }
    }
  }
}

// Normalize base to strip extension and directories
if (is_string($base) && strlen($base)) {
  $base = basename($base);
  // If it's an fseq, map to mp3 by default (client will request through music.php)
  // If it already ends with an audio extension, leave it.
  $lower = strtolower($base);
  $audio_exts = ['.mp3','.m4a','.aac','.ogg','.wav'];
  $is_audio = false;
  foreach($audio_exts as $ext){
    if (substr($lower, -strlen($ext)) === $ext) { $is_audio = true; break; }
  }
  if (!$is_audio) {
    // strip known non-audio extensions and default to .mp3
    $base = preg_replace('/\.(fseq|seq|eseq|mp4|mov)$/i', '', $base);
    $base = $base . '.mp3';
  }
}

$ok = (is_string($base) && strlen($base) && $elapsed_s !== null);

$out = [
  'ok' => $ok,
  'playing' => $playing && $ok,
  'track' => $ok ? $base : null,
  'pos_ms' => $ok ? (int)round($elapsed_s * 1000.0) : 0,
  'server_ms' => $server_ms,
  'wall_ms' => (int) round(microtime(true) * 1000), // close enough for Date.now() math
  'src' => $ok ? ('../music.php?file=' . rawurlencode($base)) : null,
];

echo json_encode($out, JSON_UNESCAPED_SLASHES);
