# MSP Tools — Technical Development Specification

## 1. Overview
- **Product Name:** MSP Tools
- **Supported Brands:** Xiaomi, Samsung, OPPO, Vivo, Realme, Tecno, Infinix
- **Chipset Coverage:** Qualcomm Snapdragon, MediaTek (Helio/Dimensity), Samsung Exynos, Unisoc (SPD), Android Standard
- **Stack:** Electron + React + TypeScript (UI) + PHP 8.2+ Local Service Engine (`127.0.0.1:8910`)

## 2. Engine Adapters
1. **Android Standard Engine:** Official `adb` and `fastboot` commands for device diagnostics, partition info, and authorized fastboot operations.
2. **Qualcomm Engine:** Sahara & Firehose protocol workflows (`rawprogram0.xml`, `patch0.xml`, vendor-signed programmers) in EDL 9008 mode (`VID_05C6&PID_9008`).
3. **MediaTek Engine:** BROM (`VID_0E8D&PID_0003`) & Preloader (`VID_0E8D&PID_2000`) scatter-based firmware verification and authorized flashing.
4. **Samsung Engine:** Download Mode (`VID_04E8&PID_685D`) PIT inspection and `BL` / `AP` / `CP` / `CSC` `.tar.md5` verification.
5. **Unisoc Engine:** SPRD U2S Diag (`VID_1782&PID_4D00`) FDL1/FDL2 `.pac` package handling.

## 3. Safety & Execution Rules
- **Staged Job -> Explicit `DO JOB` Execution:** Selecting a brand, model, or operation stages the job without executing device commands until the technician explicitly clicks **DO JOB**.
- **Strict Command Allowlist:** PHP `proc_open()` executes fixed command arrays with `bypass_shell => true` and timeout enforcement.
- **Prohibited Operations:** No FRP bypass, account/activation lock bypass, passcode removal, or anti-rollback circumvention.
