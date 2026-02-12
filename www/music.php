<?php
// music.php
// Streams an audio file from /home/fpp/media/music/ by filename.
// Keeps the browser origin stable and allows simple allow-listing.

$MUSIC_DIR = '/home/fpp/media/music';

$file = isset($_GET['file']) ? $_GET['file'] : '';
$file = basename($file); // prevent path traversal

if ($file === '' || strpos($file, '..') !== false) {
  http_response_code(400);
  echo "Bad file";
  exit;
}

$allowed = ['mp3','m4a','aac','ogg','wav'];
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
if (!in_array($ext, $allowed, true)) {
  http_response_code(415);
  echo "Unsupported type";
  exit;
}

$path = $MUSIC_DIR . '/' . $file;
if (!is_file($path)) {
  http_response_code(404);
  echo "Not found";
  exit;
}

// Content-Type
$types = [
  'mp3' => 'audio/mpeg',
  'm4a' => 'audio/mp4',
  'aac' => 'audio/aac',
  'ogg' => 'audio/ogg',
  'wav' => 'audio/wav',
];
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('Accept-Ranges: bytes');
header('Cache-Control: public, max-age=3600');

// Support range requests (important for iOS + seeking)
$size = filesize($path);
$start = 0;
$end = $size - 1;

if (isset($_SERVER['HTTP_RANGE'])) {
  if (preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
    $start = (int)$m[1];
    if ($m[2] !== '') $end = (int)$m[2];
    if ($end > $size - 1) $end = $size - 1;
    if ($start > $end) {
      http_response_code(416);
      exit;
    }
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
  }
}

$length = $end - $start + 1;
header("Content-Length: $length");

$fp = fopen($path, 'rb');
fseek($fp, $start);

$chunk = 1024 * 256;
while (!feof($fp) && $length > 0) {
  $read = ($length > $chunk) ? $chunk : $length;
  $buf = fread($fp, $read);
  if ($buf === false) break;
  echo $buf;
  flush();
  $length -= strlen($buf);
}
fclose($fp);
