<?php
/**
 * portal-api.php — Captive Portal API (RFC 8908/8910)
 * ====================================================
 *
 * Implements the Captive Portal API for modern devices (Android 11+, iOS 14+).
 * Referenced by DHCP option 114 in the show AP dnsmasq config.
 *
 * When a device connects to the show AP, DHCP option 114 tells it to fetch
 * this URL. The JSON response tells the device:
 *   - "captive": true  → this network requires sign-in
 *   - "user-portal-url" → where to open the sign-in page
 *
 * The device then shows a "Sign in to Wi-Fi network" notification/popup.
 */

$ip = $_SERVER['SERVER_ADDR'] ?? '192.168.60.1';

header('Content-Type: application/captive+json');
header('Cache-Control: private, no-store, max-age=0');

echo json_encode([
    'captive' => true,
    'user-portal-url' => "http://$ip/listen/public.html"
], JSON_UNESCAPED_SLASHES);
