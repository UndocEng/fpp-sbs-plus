<?php
header('Content-Type: text/plain');
$data = @json_decode(@file_get_contents('http://127.0.0.1/api/fppd/version'), true);
echo isset($data['version']) ? $data['version'] : 'unknown';
