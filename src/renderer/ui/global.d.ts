export {};

declare global {
  interface Window {
    mspTools: {
      checkHealth: () => Promise<{ ok: boolean; engine?: string; phase?: string; error?: string }>;
      scanDevices: () => Promise<{
        ok: boolean;
        adb?: { ok: boolean; output: string; error?: string };
        fastboot?: { ok: boolean; output: string; error?: string };
        usbModes?: { ok: boolean; devices: any[] };
        device?: any;
        error?: string;
      }>;
      getDeviceInfo: () => Promise<{ ok: boolean; device?: any; error?: string }>;
      executeJob: (payload: { jobId: string; brand?: string; model?: string }) => Promise<{
        ok: boolean;
        logs?: string[];
        device?: any;
        error?: string;
      }>;
    };
  }
}