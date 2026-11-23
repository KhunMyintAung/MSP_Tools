# MSP Tools — Phase 2 (Real Device Identity, Chipset & Bootloader Inspector)

Repository: `https://github.com/KhunMyintAung/MSP_Tools`

## What's New in Phase 2
- **Live Device Spec Parser (`/api/device-info`):** Reads real Brand, Model, Device Codename, Chipset/Board (`ro.board.platform` / `ro.hardware`), Android Version, Security Patch, Active Slot (`_a`/`_b`), Anti-Rollback index, and Bootloader Lock state via ADB & Fastboot.
- **USB COM Port & Hardware Mode Resolver:** Extracts Windows `COMx` port numbers alongside Qualcomm EDL (`05C6:9008`), MediaTek BROM/Preloader (`0E8D:0003` / `0E8D:2000`), Samsung Odin (`04E8:685D`), and Unisoc (`1782:4D00`).
- **Dual-Runtime Parity:** Implemented identically in both `node-engine/server.js` and `php-engine/`.

## Quick Run
- **Node.js:** Double-click `MSP_Tools_Node.cmd`
- **PHP 8.3:** Double-click `MSP_Tools_PHP.cmd`
