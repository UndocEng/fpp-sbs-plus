<?php
// =============================================================================
// about.php — Eavesdrop About Page
// =============================================================================
// Shown under Help > Eavesdrop in FPP's menu.
// FPP's plugin.php wrapper provides the header/nav/footer.
// =============================================================================

$version = @file_get_contents(dirname(__FILE__) . '/VERSION') ?: 'unknown';
?>

<h3>Eavesdrop — Audio Sync for Listeners</h3>
<p>Version: <strong><?php echo htmlspecialchars($version); ?></strong></p>

<p>
    Streams synchronized audio to phones and browsers for Christmas light shows.
    Creates a dedicated WiFi access point with captive portal, and uses WebSocket-based
    adaptive PLL sync to keep all connected phones in time with the FPP show.
</p>

<h4>Features</h4>
<ul>
    <li>Dual WiFi AP support (admin + public listener network)</li>
    <li>Captive portal for automatic phone connection</li>
    <li>WebSocket-based sync with adaptive PLL (5-25ms accuracy)</li>
    <li>Bluetooth audio delay compensation</li>
    <li>Playback control from admin page</li>
    <li>Network configuration dashboard in FPP sidebar</li>
</ul>

<h4>How It Works</h4>
<ol>
    <li>A WiFi adapter creates a listener network for audience members</li>
    <li>Phones connect and are directed to the listener page via captive portal</li>
    <li>A WebSocket server broadcasts the current show position every 100ms</li>
    <li>Each phone's browser adjusts its audio playback rate to stay in sync</li>
    <li>Typical sync accuracy: 5-25ms (imperceptible to the human ear)</li>
</ol>

<h4>Links</h4>
<ul>
    <li><a href="https://github.com/UndocEng/fpp-eavesdrop-sbs-plus" target="_blank" rel="noopener">GitHub Repository</a></li>
    <li><a href="https://github.com/UndocEng/fpp-eavesdrop-sbs-plus/issues" target="_blank" rel="noopener">Report a Bug</a></li>
</ul>

<h4>Credits</h4>
<p>
    Developed by <strong>Undoc Engineering</strong>.<br>
    Built for the <a href="https://falconchristmas.com/" target="_blank" rel="noopener">Falcon Player</a> community.
</p>
