# SBS+ TODO

## Admin Page — BT Pre-Start (admin.html)

The admin device can start sequences, so it can pre-start audio before the FSEQ fires.
This eliminates PLL convergence delay for BT-compensated audio on the owner's device.

**Important**: FPP always plays its own audio in sync with the FSEQ natively. The RPi's
audio output (amplifier, local speakers) is the master clock — listeners sync to it via WS
position broadcasts. The pre-start flow only affects the admin page's *local* audio timing;
it does NOT change FPP's own audio/FSEQ sync or the position data listeners receive.

- [x] **Pre-start flow when BT delay > 0**: When user clicks Start with a BT profile active:
  1. Load and start audio playing locally at position 0
  2. Wait `btDelayMs` milliseconds
  3. *Then* send the FSEQ start command to FPP (`action: "start_sequence"`)
  4. FPP starts FSEQ + RPi audio together (native sync, unaffected by BT delay)
  5. Admin's local audio is already `btDelayMs` ahead → BT latency brings it back in sync with lights
  6. Listeners sync to FPP's position broadcasts as normal (RPi audio = FSEQ = listener target)
- [x] **PLL initial state**: After pre-start, PLL should see near-zero error immediately
  (admin audio position ≈ FPP position + btDelayMs from the first sync tick)
- [x] **Fallback for join-in-progress**: If admin joins a show already playing (didn't click Start),
  keep current behavior — initial seek to `targetMs` (which includes btDelayMs) and let PLL converge
- [x] **Edge case: audio load time**: Audio metadata must load before play starts;
  FSEQ start waits for audio to be ready + btDelayMs of actual playback
- [x] **BT delay = 0**: When no BT profile is active (btDelayMs = 0), skip pre-start entirely —
  start FSEQ immediately (current behavior), RPi audio and admin audio start together
- [x] **Abort handling**: Stop button cancels pre-start mid-flight (btPreStartAbort flag)
- [x] **Sync guard**: sync() ignores stop/pause messages during pre-start + 500ms grace period
  after FSEQ start command (btPreStartActive + btPreStartGraceUntil)
- [x] **Playlist fallback**: If audio URL can't be resolved (playlist selected, or no matching
  audio file), fall through to normal start (no pre-start)

## Listener Page — BT Jump-Start (listen.html)

Listeners can't control when sequences start — they discover a playing sequence via WS broadcast.
BT compensation is a local-only initial jump to get the audio ahead by the calibrated offset.

- [x] **Initial jump**: On new track detection, initial seek includes `btOffsetMs` in targetMs
  (`targetMs = rawPosMs + elapsed + btOffsetMs`), so `audio.currentTime` is set to
  FPP position + btOffset on the very first sync tick (listen.html:886, 938)
- [x] **PLL maintains offset**: After initial jump, PLL keeps `targetMs = rawPosMs + elapsed + btOffsetMs`
  to maintain the offset during steady-state (listen.html:886)

## Backend

- [x] **get_audio_url endpoint**: admin.php resolves sequence base name → audio file URL
  (checks /home/fpp/media/music/ for mp3, m4a, mp4, aac, ogg, wav)
