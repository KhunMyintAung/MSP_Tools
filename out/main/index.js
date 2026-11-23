"use strict";
const electron = require("electron");
const node_child_process = require("node:child_process");
const path = require("node:path");
let phpServer = null;
const PHP_PORT = 8910;
const PHP_BASE_URL = `http://127.0.0.1:${PHP_PORT}`;
function startPhpEngine() {
  const publicDir = path.join(electron.app.getAppPath(), "php-engine", "public");
  phpServer = node_child_process.spawn("php", ["-S", `127.0.0.1:${PHP_PORT}`, "-t", publicDir], {
    windowsHide: true,
    shell: false
  });
  phpServer.on("error", (err) => {
    console.error("Failed to start PHP engine:", err.message);
  });
}
async function callPhpApi(endpoint, init) {
  try {
    const res = await fetch(`${PHP_BASE_URL}${endpoint}`, init);
    if (!res.ok) {
      return { ok: false, error: `PHP Engine HTTP ${res.status}` };
    }
    return await res.json();
  } catch (error) {
    return {
      ok: false,
      error: `PHP Engine offline (${PHP_BASE_URL}). Ensure PHP 8.2+ is installed and in PATH. ${error instanceof Error ? error.message : ""}`
    };
  }
}
function createWindow() {
  const win = new electron.BrowserWindow({
    width: 1380,
    height: 860,
    minWidth: 1120,
    minHeight: 700,
    backgroundColor: "#0a0e0c",
    title: "MSP Tools — Multi-Brand Mobile Service Platform",
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
electron.app.whenReady().then(() => {
  startPhpEngine();
  electron.ipcMain.handle("msp:health", () => callPhpApi("/api/v1/health"));
  electron.ipcMain.handle("msp:scan", () => callPhpApi("/api/v1/devices/scan"));
  electron.ipcMain.handle(
    "msp:executeJob",
    (_event, payload) => callPhpApi("/api/v1/jobs/execute", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload)
    })
  );
  createWindow();
  electron.app.on("activate", () => {
    if (electron.BrowserWindow.getAllWindows().length === 0) createWindow();
  });
});
electron.app.on("will-quit", () => {
  if (phpServer) {
    phpServer.kill();
    phpServer = null;
  }
});
electron.app.on("window-all-closed", () => {
  if (process.platform !== "darwin") electron.app.quit();
});
