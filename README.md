# MSP Tools — Real-Time Auto-Detect (ADB / Fastboot / EDL / BROM)

Repository: `https://github.com/KhunMyintAung/MSP_Tools`

## What's New
- **Zero-Click USB Auto-Detection:** Automatically detects **Fastboot**, **ADB**, **Qualcomm EDL 9008**, **MediaTek BROM/Preloader**, **Samsung Odin**, and **Unisoc SPD** as soon as the USB cable is plugged in or the device reboots between modes.
- **Automatic Brand & Target Sync:** Automatically switches the active Brand tab, updates the `LIVE CONNECTED DEVICE` spec card, and logs a clean timestamped event in the console without flooding.
- **Portable `bin/` Auto-Resolution:** Both `node-engine/server.js` and `php-engine/` automatically locate `bin\adb.exe` and `bin\fastboot.exe` directly.
