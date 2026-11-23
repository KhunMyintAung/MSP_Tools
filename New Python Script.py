from pathlib import Path
import zipfile, textwrap

root = Path("/mnt/data/mobile-flashing-tool-phase1")
files = {
"package.json": r'''{
  "name": "mobile-flashing-tool",
  "version": "0.1.0",
  "private": true,
  "description": "Windows-first mobile device service toolkit - Phase 1 foundation",
  "main": "out/main/index.js",
  "scripts": {
    "dev": "electron-vite dev",
    "build": "electron-vite build",
    "preview": "electron-vite preview",
    "typecheck": "tsc --noEmit"
  },
  "dependencies": {
    "react": "^19.0.0",
    "react-dom": "^19.0.0"
  },
  "devDependencies": {
    "@types/node": "^22.0.0",
    "@types/react": "^19.0.0",
    "@types/react-dom": "^19.0.0",
    "@vitejs/plugin-react": "^4.3.0",
    "electron": "^36.0.0",
    "electron-vite": "^4.0.0",
    "typescript": "^5.8.0",
    "vite": "^6.0.0"
  }
}''',
"electron.vite.config.ts": r'''import { defineConfig } from "electron-vite";
import react from "@vitejs/plugin-react";

export default defineConfig({
  main: {},
  preload: {},
  renderer: {
    plugins: [react()]
  }
});''',
"tsconfig.json": r'''{
  "compilerOptions": {
    "target": "ES2022",
    "module": "ESNext",
    "moduleResolution": "Bundler",
    "strict": true,
    "jsx": "react-jsx",
    "esModuleInterop": true,
    "skipLibCheck": true,
    "types": ["node"],
    "noEmit": true
  },
  "include": ["electron.vite.config.ts", "src/**/*.ts", "src/**/*.tsx"]
}''',
"src/main/index.ts": r'''import { app, BrowserWindow, ipcMain } from "electron";
import { spawn } from "node:child_process";
import path from "node:path";

type ToolResult = { ok: boolean; output: string; error?: string };

function runReadOnlyTool(command: "adb" | "fastboot", args: string[]): Promise<ToolResult> {
  return new Promise((resolve) => {
    // Strict allowlist: Phase 1 supports discovery commands only.
    const allowed =
      (command === "adb" && args.length === 2 && args[0] === "devices" && args[1] === "-l") ||
      (command === "fastboot" && args.length === 1 && args[0] === "devices");

    if (!allowed) {
      resolve({ ok: false, output: "", error: "Command is not allowed in Phase 1." });
      return;
    }

    const child = spawn(command, args, {
      windowsHide: true,
      shell: false
    });

    let stdout = "";
    let stderr = "";
    const timeout = setTimeout(() => child.kill(), 8000);

    child.stdout.setEncoding("utf8");
    child.stderr.setEncoding("utf8");
    child.stdout.on("data", (chunk: string) => stdout += chunk);
    child.stderr.on("data", (chunk: string) => stderr += chunk);

    child.on("error", (error) => {
      clearTimeout(timeout);
      resolve({
        ok: false,
        output: "",
        error: `${command} not found or could not start. Install Android SDK Platform-Tools and add adb/fastboot to PATH. ${error.message}`
      });
    });

    child.on("close", (code) => {
      clearTimeout(timeout);
      resolve({
        ok: code === 0,
        output: stdout.trim(),
        error: code === 0 ? undefined : (stderr.trim() || `${command} exited with code ${code}`)
      });
    });
  });
}

function createWindow() {
  const win = new BrowserWindow({
    width: 1280,
    height: 820,
    minWidth: 1000,
    minHeight: 680,
    backgroundColor: "#10141c",
    webPreferences: {
      preload: path.join(__dirname, "../preload/index.js"),
      contextIsolation: true,
      nodeIntegration: false,
      sandbox: true
    }
  });

  if (process.env.ELECTRON_RENDERER_URL) {
    void win.loadURL(process.env.ELECTRON_RENDERER_URL);
  } else {
    void win.loadFile(path.join(__dirname, "../renderer/index.html"));
  }
}

app.whenReady().then(() => {
  ipcMain.handle("device:scan", async () => {
    const [adb, fastboot] = await Promise.all([
      runReadOnlyTool("adb", ["devices", "-l"]),
      runReadOnlyTool("fastboot", ["devices"])
    ]);
    return { adb, fastboot, scannedAt: new Date().toISOString() };
  });

  createWindow();
  app.on("activate", () => {
    if (BrowserWindow.getAllWindows().length === 0) createWindow();
  });
});

app.on("window-all-closed", () => {
  if (process.platform !== "darwin") app.quit();
});''',
"src/preload/index.ts": r'''import { contextBridge, ipcRenderer } from "electron";

contextBridge.exposeInMainWorld("repairToolkit", {
  scanDevices: () => ipcRenderer.invoke("device:scan")
});''',
"src/renderer/index.html": r'''<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Mobile Flashing Toolkit</title>
  </head>
  <body>
    <div id="root"></div>
    <script type="module" src="/src/renderer/main.tsx"></script>
  </body>
</html>''',
"src/renderer/main.tsx": r'''import React from "react";
import { createRoot } from "react-dom/client";
import App from "./ui/App";
import "./ui/styles.css";

createRoot(document.getElementById("root")!).render(
  <React.StrictMode><App /></React.StrictMode>
);''',
"src/renderer/ui/global.d.ts": r'''export {};

declare global {
  interface Window {
    repairToolkit: {
      scanDevices: () => Promise<{
        adb: { ok: boolean; output: string; error?: string };
        fastboot: { ok: boolean; output: string; error?: string };
        scannedAt: string;
      }>;
    };
  }
}''',
"src/renderer/ui/App.tsx": r'''import { useState } from "react";

type ScanResult = Awaited<ReturnType<typeof window.repairToolkit.scanDevices>>;

export default function App() {
  const [result, setResult] = useState<ScanResult | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  async function scan() {
    setBusy(true);
    setError("");
    try {
      setResult(await window.repairToolkit.scanDevices());
    } catch (e) {
      setError(e instanceof Error ? e.message : "Device scan failed.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <main className="shell">
      <aside className="sidebar">
        <div className="brand"><span className="brand-mark">M</span><div><b>Mobile Flashing</b><small>TOOLKIT</small></div></div>
        <div className="nav-label">WORKSPACE</div>
        <div className="nav active">▦　Dashboard</div>
        <div className="nav">▣　Device Manager</div>
        <div className="nav">◇　Boot & Modes <em>Planned</em></div>
        <div className="nav">▤　Firmware Manager <em>Planned</em></div>
        <div className="nav">⚙　Flashing Engines <em>Planned</em></div>
        <div className="nav">☷　Operation Logs <em>Planned</em></div>
        <div className="sidebar-foot">PHASE 1 · FOUNDATION</div>
      </aside>

      <section className="content">
        <header><div><small>WORKSPACE / OVERVIEW</small><h1>Device Dashboard</h1><p>Detect connected Android devices using ADB and Fastboot.</p></div><span className="version">v0.1.0 · Phase 1</span></header>

        <section className="notice"><b>Phase 1 safety scope</b><span>Read-only device discovery only. No unlock, erase, write, or flashing commands are enabled.</span></section>

        <section className="scan-card">
          <div><h2>Device Detection</h2><p>Connect a phone by USB, enable the relevant supported mode, then scan.</p></div>
          <button onClick={scan} disabled={busy}>{busy ? "Scanning…" : "↻  Scan Devices"}</button>
        </section>

        {error && <section className="error">{error}</section>}

        <div className="cards">
          <article className="device-card">
            <div className="card-head"><span className="chip">ADB</span><span className="status">Android Debug Bridge</span></div>
            <h3>Android / ADB</h3>
            <pre>{result ? result.adb.output || result.adb.error || "No ADB devices detected." : "Not scanned yet."}</pre>
            {result && !result.adb.ok && <small className="err">{result.adb.error}</small>}
          </article>
          <article className="device-card">
            <div className="card-head"><span className="chip purple">FASTBOOT</span><span className="status">Bootloader interface</span></div>
            <h3>Fastboot Devices</h3>
            <pre>{result ? result.fastboot.output || result.fastboot.error || "No Fastboot devices detected." : "Not scanned yet."}</pre>
            {result && !result.fastboot.ok && <small className="err">{result.fastboot.error}</small>}
          </article>
        </div>

        <section className="bottom">
          <h2>Phase 1 checklist</h2>
          <ul><li>Desktop shell and secure preload bridge</li><li>ADB device discovery</li><li>Fastboot device discovery</li><li>Command allowlist and timeout handling</li><li>Next: USB identity, driver diagnostics, structured device model</li></ul>
          {result && <small>Last scan: {new Date(result.scannedAt).toLocaleString()}</small>}
        </section>
      </section>
    </main>
  );
}''',
"src/renderer/ui/styles.css": r'''*{box-sizing:border-box}body{margin:0;background:#10141c;color:#edf2f8;font-family:Inter,Segoe UI,Arial,sans-serif}.shell{display:flex;min-height:100vh}.sidebar{width:245px;background:#0b0f16;border-right:1px solid #242b38;padding:25px 16px;display:flex;flex-direction:column}.brand{display:flex;align-items:center;gap:11px;margin:2px 7px 45px}.brand-mark{width:38px;height:38px;border-radius:11px;background:#2563eb;display:grid;place-items:center;font-weight:800;font-size:20px}.brand small{display:block;color:#8290a4;letter-spacing:2px;font-size:9px;margin-top:3px}.nav-label{font-size:10px;color:#657187;letter-spacing:1.6px;margin:0 10px 12px}.nav{padding:13px 11px;color:#aab5c5;border-radius:8px;margin:2px 0;font-size:13px}.nav.active{background:#1c2c49;color:#fff}.nav em{float:right;color:#68758a;font-size:9px;font-style:normal}.sidebar-foot{margin-top:auto;color:#68758a;font-size:10px;letter-spacing:1px;padding:10px}.content{flex:1;padding:38px 45px;max-width:1500px;margin:auto;width:100%}header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:27px}header small{color:#8190a5;letter-spacing:1.4px;font-size:10px}h1{font-size:30px;margin:9px 0 7px}header p,.scan-card p{color:#94a1b4;margin:0;font-size:13px}.version{border:1px solid #303a4a;padding:8px 12px;border-radius:7px;color:#9eacc0;font-size:11px}.notice{background:#18263b;border:1px solid #29476f;border-radius:10px;padding:15px 18px;display:flex;gap:15px;align-items:center;font-size:12px;margin-bottom:22px}.notice b{color:#83b7ff;white-space:nowrap}.notice span{color:#b4c1d2}.scan-card{background:#171e29;border:1px solid #2b3443;border-radius:12px;padding:22px;display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}.scan-card h2,.bottom h2{font-size:16px;margin:0 0 7px}.scan-card button{background:#2563eb;color:white;border:0;border-radius:8px;padding:12px 17px;font-weight:600;cursor:pointer}.scan-card button:disabled{opacity:.6}.cards{display:grid;grid-template-columns:1fr 1fr;gap:18px}.device-card,.bottom{background:#171e29;border:1px solid #2b3443;border-radius:12px;padding:20px}.card-head{display:flex;align-items:center;justify-content:space-between}.chip{background:#183b32;color:#68d7a9;padding:6px 9px;border-radius:6px;font-size:10px;font-weight:700}.chip.purple{background:#30264b;color:#c5a6ff}.status{font-size:10px;color:#8190a5}.device-card h3{font-size:15px;margin:17px 0 10px}.device-card pre{white-space:pre-wrap;overflow-wrap:anywhere;min-height:65px;background:#0e131b;border-radius:7px;padding:13px;color:#b9c7d8;font-size:11px}.err,.error{color:#ff9b9b;font-size:11px}.error{padding:12px;background:#351e24;border-radius:8px;margin-bottom:15px}.bottom{margin-top:18px}.bottom ul{color:#aab6c6;font-size:12px;line-height:2;padding-left:18px}.bottom small{color:#74849a;font-size:10px}@media(max-width:900px){.sidebar{width:190px}.content{padding:25px}.cards{grid-template-columns:1fr}}''',
"README.md": r'''# Mobile Flashing Toolkit — Phase 1

Windows-first desktop foundation for an advanced multi-brand mobile service platform.

## Phase 1 scope

- Electron + React + TypeScript desktop shell
- Secure preload bridge
- Read-only ADB device discovery (`adb devices -l`)
- Read-only Fastboot device discovery (`fastboot devices`)
- Command allowlist, no shell execution, timeout handling

**No firmware writing, bootloader unlocking/relocking, partition erase, factory reset, FRP/activation-lock bypass, or flashing is implemented in Phase 1.**

## Requirements

- Windows 10/11 x64
- Node.js LTS and npm
- Android SDK Platform-Tools (`adb.exe`, `fastboot.exe`) installed and available on PATH
- USB drivers appropriate for the phone brand
- USB data cable

## Run

```bash
npm install
npm run dev
```

## Build

```bash
npm run typecheck
npm run build
```

## Test device discovery

1. Connect an Android phone with a data-capable USB cable.
2. Enable USB debugging on a device you own or are authorized to service.
3. Accept the USB debugging authorization prompt.
4. Click **Scan Devices**. The device should appear in the ADB panel.
5. For Fastboot discovery, use the device manufacturer's documented method to enter Fastboot mode, then scan again.

If the command is not found, install official Android SDK Platform-Tools and add its folder to the Windows PATH.

## Architecture rules

- Renderer never invokes shell or Node APIs.
- Main process exposes only fixed, allowlisted IPC operations.
- Use `spawn()` with `shell: false`; never concatenate user-controlled command strings.
- Firmware operations must be separate engines with device/model/region/build compatibility checks, explicit confirmation, signed audit logs, cancellation/timeout handling, and recovery guidance.
- Never attempt to bypass FRP, account locks, activation locks, passcodes, secure boot, or anti-rollback protections.

## Next Phase 1 tasks

1. Add USB VID/PID enumeration and driver status.
2. Normalize ADB/Fastboot output into a typed `Device` model.
3. Add multi-device selection and per-device session state.
4. Add structured logs and diagnostic export.
5. Add Windows installer packaging and clean-machine tests.
''',
".gitignore": r'''node_modules/
out/
dist/
release/
*.log
.DS_Store
.env
''',
"docs/TECHNICAL_SPEC.md": r'''# Technical Development Specification

## Product goal
A Windows desktop service platform for authorized firmware servicing and diagnostics across Xiaomi, Samsung, OPPO, vivo, realme, TECNO and Infinix devices.

## Core domains
1. Device discovery and transport sessions
2. Boot-mode detection and transition guidance
3. Firmware package ingestion, parsing, validation and catalog
4. Brand/chipset-specific service adapters
5. Operation orchestration, progress, cancellation and audit
6. Diagnostics, logs, backup/restore and recovery guidance

## Engine boundaries
- Android Standard: ADB and Fastboot through official Android Platform-Tools.
- Qualcomm: identify supported USB/EDL states; use only documented or authorized service interfaces and vendor-signed loaders.
- MediaTek: identify supported USB/BROM/Preloader states; no bypass of secure boot or authentication.
- Samsung: identify Download Mode and integrate official/vendor-authorized workflows; do not assume Odin is an open public API.
- Unisoc: detection and documented vendor-authorized service workflows.
- Apple: Recovery/DFU detection and Apple-supported restore workflows only.

## Firmware pipeline
Import -> archive inspection -> manifest parsing -> device/build/region match -> signature/hash validation -> partition map review -> risk classification -> user confirmation -> execution adapter -> verification -> audit report.

## Operation safety classes
- Read-only: scan, identify, parse, inspect, logs.
- Reversible/low risk: supported reboot or mode transition.
- Destructive: factory reset, erase, flash, bootloader state changes. Require explicit consent, compatibility gates, stable power/cable checks, backup warning and full audit.
- Prohibited: bypass account/activation locks, passcodes, FRP, secure boot, anti-rollback or unauthorized access controls.

## Planned phases
1. Foundation and read-only detection
2. USB identity, drivers and device model
3. Boot/recovery/fastboot mode workflows
4. Firmware package analyzer and catalog
5. Fastboot service adapter for explicitly supported devices
6. Qualcomm authorized-service adapter
7. MediaTek authorized-service adapter
8. Samsung and Unisoc authorized workflows
9. Apple-supported restore integration
10. QA, signing, packaging and release

## Acceptance criteria for Phase 1
- App starts on clean Windows 10/11 x64.
- Renderer has no Node integration and uses isolated preload API.
- ADB and Fastboot scan actions are fixed allowlisted commands.
- Multiple devices can be listed without implicit target selection.
- Missing tools and missing drivers produce actionable messages.
- No destructive device command is available.
'''
}

for rel, content in files.items():
    p = root / rel
    p.parent.mkdir(parents=True, exist_ok=True)
    p.write_text(content, encoding="utf-8")

zip_path = Path("/mnt/data/mobile-flashing-tool-phase1.zip")
with zipfile.ZipFile(zip_path, "w", zipfile.ZIP_DEFLATED) as z:
    for p in root.rglob("*"):
        if p.is_file():
            z.write(p, p.relative_to(root.parent))

print(f"Created starter project: {zip_path}")
print(f"Files: {len(files)}")
