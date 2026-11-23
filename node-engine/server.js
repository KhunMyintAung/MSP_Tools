const http = require("node:http");
const fs = require("node:fs");
const path = require("node:path");
const { execFile } = require("node:child_process");

const PORT = 8910;
const HOST = "127.0.0.1";
const PUBLIC_DIR = path.join(__dirname, "..", "public");

const KNOWN_MODES = [
  { vidPid: "VID_05C6&PID_9008", chipset: "Qualcomm", mode: "EDL 9008 Mode", engine: "QualcommEngine" },
  { vidPid: "VID_0E8D&PID_0003", chipset: "MediaTek", mode: "BROM Mode", engine: "MtkEngine" },
  { vidPid: "VID_0E8D&PID_2000", chipset: "MediaTek", mode: "Preloader Mode", engine: "MtkEngine" },
  { vidPid: "VID_04E8&PID_685D", chipset: "Samsung", mode: "Odin / Download Mode", engine: "SamsungEngine" },
  { vidPid: "VID_1782&PID_4D00", chipset: "Unisoc", mode: "SPRD Download Mode", engine: "UnisocEngine" },
  { vidPid: "VID_18D1&PID_D00D", chipset: "Android", mode: "Fastboot / Bootloader", engine: "AndroidStandardEngine" }
];

function runSafeCommand(bin, args, timeout = 8000) {
  return new Promise((resolve) => {
    execFile(bin, args, { timeout, windowsHide: true }, (error, stdout, stderr) => {
      if (error) {
        resolve({
          ok: false,
          output: (stdout || "").trim(),
          error: error.code === "ENOENT" ? `'${bin}' not found in system PATH.` : (stderr || error.message).trim()
        });
        return;
      }
      resolve({ ok: true, output: (stdout || "").trim(), error: null });
    });
  });
}

async function scanWindowsUsb() {
  const psScript = 'Get-PnpDevice -PresentOnly | Where-Object { $_.InstanceId -match "^USB" } | Select-Object FriendlyName, InstanceId, Status | ConvertTo-Json -Compress';
  const raw = await runSafeCommand("powershell", ["-NoProfile", "-Command", psScript]);
  if (!raw.ok || !raw.output) {
    return { ok: raw.ok, devices: [], error: raw.error };
  }

  let list = [];
  try {
    const parsed = JSON.parse(raw.output);
    list = Array.isArray(parsed) ? parsed : [parsed];
  } catch {
    return { ok: true, devices: [], error: null };
  }

  const matched = [];
  for (const item of list) {
    const instanceId = String(item.InstanceId || "").toUpperCase();
    for (const meta of KNOWN_MODES) {
      if (instanceId.includes(meta.vidPid)) {
        matched.push({
          name: item.FriendlyName || "Unknown USB Device",
          instanceId,
          status: item.Status || "Unknown",
          chipset: meta.chipset,
          mode: meta.mode,
          engine: meta.engine
        });
      }
    }
  }
  return { ok: true, devices: matched, error: null };
}

function sendJson(res, statusCode, data) {
  res.writeHead(statusCode, { "Content-Type": "application/json; charset=utf-8" });
  res.end(JSON.stringify(data));
}

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url || "/", `http://${HOST}:${PORT}`);

  if (req.method === "GET" && url.pathname === "/api/health") {
    return sendJson(res, 200, {
      ok: true,
      engine: `Node.js ${process.version}`,
      os: process.platform
    });
  }

  if (req.method === "GET" && url.pathname === "/api/scan") {
    const [adb, fastboot, usbModes] = await Promise.all([
      runSafeCommand("adb", ["devices", "-l"]),
      runSafeCommand("fastboot", ["devices"]),
      scanWindowsUsb()
    ]);
    return sendJson(res, 200, {
      ok: true,
      adb,
      fastboot,
      usbModes,
      scannedAt: new Date().toISOString()
    });
  }

  if (req.method === "POST" && url.pathname === "/api/execute") {
    let body = "";
    req.on("data", (chunk) => (body += chunk));
    req.on("end", async () => {
      let payload = {};
      try { payload = JSON.parse(body || "{}"); } catch {}

      const jobTitle = payload.jobTitle || "Diagnostic Job";
      const brand = payload.brand || "Unknown";
      const model = payload.model || "Unknown";

      const logs = [
        `Dispatched [${jobTitle}] to Node.js Engine (${process.version})`,
        `Target Profile: ${brand} -> ${model}`,
        "Checking USB Hardware Ports (EDL / BROM / Preloader / Odin / SPD)..."
      ];

      const [usb, adb, fastboot] = await Promise.all([
        scanWindowsUsb(),
        runSafeCommand("adb", ["devices", "-l"]),
        runSafeCommand("fastboot", ["devices"])
      ]);

      if (usb.devices.length > 0) {
        for (const dev of usb.devices) {
          logs.push(`FOUND USB PORT: ${dev.chipset} | ${dev.mode} -> ${dev.name}`);
        }
      } else {
        logs.push("USB Hardware Ports: No EDL / BROM / Odin port detected.");
      }

      logs.push(
        adb.ok && adb.output
          ? "ADB Interface: " + adb.output.replace(/\r?\n/g, " | ")
          : "ADB Interface: " + (adb.error || "No ADB device connected.")
      );

      logs.push(
        fastboot.ok && fastboot.output
          ? "Fastboot Interface: " + fastboot.output.replace(/\r?\n/g, " | ")
          : "Fastboot Interface: " + (fastboot.error || "No Fastboot device connected.")
      );

      logs.push("Operation finished successfully (Phase 1 Read-Only Safe Mode).");
      return sendJson(res, 200, { ok: true, logs });
    });
    return;
  }

  // Serve static UI
  const filePath = path.join(PUBLIC_DIR, url.pathname === "/" ? "index.html" : url.pathname);
  if (filePath.startsWith(PUBLIC_DIR) && fs.existsSync(filePath) && fs.statSync(filePath).isFile()) {
    const ext = path.extname(filePath);
    const mime = ext === ".css" ? "text/css" : ext === ".js" ? "application/javascript" : "text/html; charset=utf-8";
    res.writeHead(200, { "Content-Type": mime });
    fs.createReadStream(filePath).pipe(res);
    return;
  }

  res.writeHead(404, { "Content-Type": "text/plain" });
  res.end("Not Found");
});

server.listen(PORT, HOST, () => {
  console.log(`MSP Tools Node.js Engine running at http://${HOST}:${PORT}`);
});
