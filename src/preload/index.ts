import { contextBridge, ipcRenderer } from "electron";

contextBridge.exposeInMainWorld("mspTools", {
  checkHealth: () => ipcRenderer.invoke("msp:health"),
  scanDevices: () => ipcRenderer.invoke("msp:scan"),
  executeJob: (payload: { jobId: string; brand: string; model: string }) =>
    ipcRenderer.invoke("msp:executeJob", payload)
});
