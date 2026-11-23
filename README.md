# MSP Tools — Multi-Brand Mobile Service Platform

Repository: `https://github.com/KhunMyintAung/MSP_Tools`

Windows-first desktop platform combining an **Electron + React + TypeScript** service console with a **PHP 8.2+ Local Engine Service (`127.0.0.1:8910`)** for authorized diagnostics, boot-mode detection, firmware inspection, and multi-brand servicing across **Xiaomi, Samsung, OPPO, Vivo, Realme, Tecno, and Infinix**.

## Architecture

- **Frontend (`src/renderer`)**: Classic 3-column Multi-Brand Service Tool UI with Emerald & Gold PCB styling, searchable Model/SoC database, Staged Job -> `DO JOB` execution workflow, live terminal, and dual progress bars.
- **Desktop Bridge (`src/main` & `src/preload`)**: Manages the background PHP built-in server lifecycle and exposes strict allowlisted IPC channels (`msp:health`, `msp:scan`, `msp:executeJob`).
- **PHP Service Engine (`php-engine/`)**:
  - `src/Security/CommandAllowlist.php`: Enforces strict command allowlisting with `bypass_shell => true` and process timeouts.
  - `src/Discovery/WindowsUsbScanner.php`: Enumerates Windows USB ports and classifies Qualcomm EDL (`05C6:9008`), MediaTek BROM/Preloader (`0E8D:0003` / `0E8D:2000`), Samsung Odin (`04E8:685D`), Unisoc (`1782:4D00`), and Fastboot (`18D1:D00D`).
  - `public/index.php`: Local REST API router serving `/api/v1/health`, `/api/v1/devices/scan`, and `/api/v1/jobs/execute`.

## Prerequisites (Windows 10/11 x64)

1. **Node.js 20+ LTS** and `npm`
2. **PHP 8.2+** installed and added to Windows `PATH` (`php -v`)
3. **Android SDK Platform-Tools** (`adb.exe`, `fastboot.exe`) added to Windows `PATH`
4. Brand USB drivers (Qualcomm 9008, MediaTek VCOM, Samsung USB, Unisoc)

## Quick Start

```bash
npm install
npm run dev
```

## Push to GitHub (`KhunMyintAung/MSP_Tools`)

Extract this archive into your local project directory and run:

```bash
git init
git add .
git commit -m "feat: initialize MSP Tools Phase 1 (Electron UI + PHP 8.3 Service Engine)"
git branch -M main
git remote add origin https://github.com/KhunMyintAung/MSP_Tools.git
git push -u origin main
```
