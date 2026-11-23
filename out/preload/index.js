"use strict";
const electron = require("electron");
electron.contextBridge.exposeInMainWorld("mspTools", {
  checkHealth: () => electron.ipcRenderer.invoke("msp:health"),
  scanDevices: () => electron.ipcRenderer.invoke("msp:scan"),
  executeJob: (payload) => electron.ipcRenderer.invoke("msp:executeJob", payload)
});
