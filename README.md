# FPP Listener Sync (Simple_Sync flavor, production bundle)

This is a **DIY** “listen on your phone” sync system for Falcon Player (FPP).

**Design goal:** visitors open a webpage, their phone downloads the show audio file, and the page keeps playback synced to whatever FPP is currently playing.

## Key behaviors (matching the Simple_Sync flavor)
- Phone downloads full MP3 (or other supported format) and plays locally
- Client polls `status.php` at **4 Hz** (250ms)
- Server returns:
  - current track base name
  - current position (ms)
  - server timestamp (ms)
  - playing state
- Client smooths between FPP’s coarse time updates using the server timestamp
- Gentle playbackRate nudging (default clamp **0.997x–1.003x**)
- Hard seek only when error exceeds **1 second**
- iOS-friendly “Enable Audio” button (user gesture required)

## Assumptions
- Your `.fseq` and audio file share the same base name, e.g.
  - `MySong.fseq` and `MySong.mp3`
- Audio files exist in: `/home/fpp/media/music/`
- FPP REST API is available locally: `http://127.0.0.1/api/fppd/status`

## Install (on an FPP Pi)
```bash
cd /home/fpp
git clone https://github.com/YOUR_ACCOUNT/YOUR_REPO.git fpp-listener-sync
cd fpp-listener-sync
sudo ./install.sh
```

Then open:
- `http://listen.local/listen/` (if mDNS/captive portal configured)
- or `http://192.168.50.1/listen/` (default AP IP in this bundle)

## Uninstall
```bash
cd /home/fpp/fpp-listener-sync
sudo ./uninstall.sh
```

## Files
- `www/listen/index.html` – the listening page
- `www/listen/status.php` – JSON status for clients (poll this)
- `www/qrcode.html` – simple Wi‑Fi + URL QR generator
- `server/listener-ap.service` – systemd unit to run AP setup script (optional)
- `server/listener-ap.sh` – hostapd/dnsmasq + captive portal setup (optional)

> NOTE: The AP/captive portal pieces are provided as a solid starting point, but you may need to adjust for your USB Wi‑Fi adapter name (wlan1 vs wlan0) and your local networking preferences.
