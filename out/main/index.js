"use strict";
const electron = require("electron");
const node_child_process = require("node:child_process");
const path = require("node:path");
let phpServer = null;
const PORT = 8910;
const BASE_URL = `http://127.0.0.1:${PORT}`;
function startPhpEngine() {
  const router = path.join(electron.app.getAppPath(), "php-engine", "router.php");
  const publicDir = path.join(electron.app.getAppPath(), "public");
  phpServer = node_child_process.spawn("php", ["-S", `127.0.0.1:${PORT}`, "-t", publicDir, router], {
    windowsHide: true,
    shell: false
  });
  phpServer.on("error", (err) => console.error("Failed to start PHP engine:", err.message));
}
async function callApi(endpoint, init) {
  try {
    const res = await fetch(`${BASE_URL}${endpoint}`, init);
    const text = await res.text();
    try {
      return JSON.parse(text);
    } catch {
      return { ok: false, error: `Invalid JSON: ${text.slice(0, 180)}` };
    }
  } catch (error) {
    return { ok: false, error: `PHP offline (${BASE_URL}). ${error instanceof Error ? error.message : ""}` };
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
  if (process.env.ELECTRON_RENDERER_URL) void win.loadURL(process.env.ELECTRON_RENDERER_URL);
  else void win.loadFile(path.join(__dirname, "../renderer/index.html"));
}
electron.app.whenReady().then(() => {
  startPhpEngine();
  electron.ipcMain.handle("msp:health", () => callApi("/api/health"));
  electron.ipcMain.handle("msp:scan", () => callApi("/api/scan"));
  electron.ipcMain.handle("msp:deviceInfo", () => callApi("/api/device-info?usb=1"));
  electron.ipcMain.handle(
    "msp:executeJob",
    (_event, payload) => callApi("/api/execute", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload)
    })
  );
  setTimeout(() => createWindow(), 800);
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
