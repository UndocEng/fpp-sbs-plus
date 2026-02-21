# SBS+ TODO

## Port from fpp-eavesdrop (V3.6_Add_BT_Delay branch)

- [ ] BT Speaker Delay profile selector card (listen.html / admin.html)
- [ ] BT delay PLL integration (`targetMs = rawPosMs + elapsed - btDelayMs`)
- [ ] Calibration page (calibrate.html) — FSEQ generation, Web Audio clicks, delay slider, profiles
- [ ] BT device management (admin.php) — scan, pair, connect, disconnect via bluetoothctl
- [ ] Signal strength / WiFi quality in Connected Clients card (admin.php + listen.html)
- [ ] install.sh sudoers updates (iw station dump, bluetoothctl)
