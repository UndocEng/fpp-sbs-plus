<?php
/**
 * detect.php — Captive Portal Detection Handler (legacy/backup)
 * ==============================================================
 *
 * NOTE: The primary captive portal redirect is handled by .htaccess rewrite
 * rules, which are faster (no PHP execution). This file exists as a backup
 * for edge cases where .htaccess rules might not catch a detection URL.
 *
 * When a phone connects to the show AP, it checks specific URLs to
 * determine if a captive portal is present. Each OS uses different URLs:
 *   - Android/Chrome: /generate_204 (expects HTTP 204 = internet works)
 *   - Apple iOS/macOS: /hotspot-detect.html, /success.html
 *   - Windows: /connecttest.txt
 *   - Firefox/Ubuntu: /canonical.html
 *
 * By returning a 302 redirect instead of the expected response, we tell
 * the phone "there's a captive portal here" which triggers the portal popup.
 */

$ip = $_SERVER['SERVER_ADDR'] ?? '192.168.60.1';
$redirect = "http://$ip/listen/listen.html";
$uri = $_SERVER['REQUEST_URI'] ?? '';

// Android/Chrome checks for generate_204 (expects HTTP 204 No Content)
if (strpos($uri, 'generate_204') !== false || strpos($uri, 'gen_204') !== false) {
    header('HTTP/1.1 302 Found');
    header("Location: $redirect");
    exit;
}

// Apple iOS checks for success.html (expects specific HTML)
if (strpos($uri, 'success.html') !== false || strpos($uri, 'hotspot-detect.html') !== false) {
    header('HTTP/1.1 302 Found');
    header("Location: $redirect");
    exit;
}

// Microsoft Windows checks for connecttest.txt
if (strpos($uri, 'connecttest.txt') !== false) {
    header('HTTP/1.1 302 Found');
    header("Location: $redirect");
    exit;
}

// Ubuntu/Firefox checks for canonical.html
if (strpos($uri, 'canonical.html') !== false) {
    header('HTTP/1.1 302 Found');
    header("Location: $redirect");
    exit;
}

// Default: redirect to public listening page
header('HTTP/1.1 302 Found');
header("Location: $redirect");
exit;
