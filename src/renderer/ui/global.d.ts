export {};

declare global {
  interface Window {
    mspTools: {
      checkHealth: () => Promise<{ ok: boolean; service?: string; phpVersion?: string; error?: string }>;
      scanDevices: () => Promise<{
        ok: boolean;
        adb?: { ok: boolean; output: string; error?: string };
        fastboot?: { ok: boolean; output: string; error?: string };
        usbModes?: { ok: boolean; devices: Array<{ name: string; chipset: string; mode: string; engine: string }> };
        error?: string;
      }>;
      executeJob: (payload: { jobId: string; brand: string; model: string }) => Promise<{
        ok: boolean;
        logs?: string[];
        error?: string;
      }>;
    };
  }
}
