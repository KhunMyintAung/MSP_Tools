const http = require("node:http");
const fs = require("node:fs");
const path = require("node:path");
const { execFile } = require("node:child_process");

const PORT = 8910;
const HOST = "127.0.0.1";
const ROOT_DIR = path.join(__dirname, "..");
const PUBLIC_DIR = path.join(ROOT_DIR, "public");
const BIN_DIR = path.join(ROOT_DIR, "bin");

const KNOWN_MODES = [
  { vidPid: "VID_05C6&PID_9008", chipset: "Qualcomm", mode: "EDL 9008", engine: "QualcommEngine" },
  { vidPid: "VID_0E8D&PID_0003", chipset: "MediaTek", mode: "MTK BROM", engine: "MtkEngine" },
  { vidPid: "VID_0E8D&PID_2000", chipset: "MediaTek", mode: "MTK Preloader", engine: "MtkEngine" },
  { vidPid: "VID_04E8&PID_685D", chipset: "Samsung", mode: "ODIN DOWNLOAD", engine: "SamsungEngine" },
  { vidPid: "VID_1782&PID_4D00", chipset: "Unisoc", mode: "UNISOC SPD", engine: "UnisocEngine" }
];

function resolveBinary(name) {
  const localExe = path.join(BIN_DIR, `${name}.exe`);
  if (fs.existsSync(localExe)) return localExe;
  return name;
}

function runSafeCommand(binKey, args, timeout = 6000) {
  const bin = binKey === "adb" || binKey === "fastboot" ? resolveBinary(binKey) : binKey;
  return new Promise((resolve) => {
    execFile(bin, args, { timeout, windowsHide: true }, (error, stdout, stderr) => {
      const out = (stdout || "").trim();
      const err = (stderr || "").trim();
      if (error) {
        resolve({
          ok: false,
          output: out,
          stderr: err,
          error: error.code === "ENOENT" ? `'${binKey}' not found in bin/ or PATH.` : (err || error.message).trim()
        });
        return;
      }
      resolve({ ok: true, output: out, stderr: err, error: null });
    });
  });
}

async function scanWindowsUsb() {
  const psScript = 'Get-PnpDevice -PresentOnly | Where-Object { $_.InstanceId -match "^USB" } | Select-Object FriendlyName, InstanceId, Status | ConvertTo-Json -Compress';
  const raw = await runSafeCommand("powershell", ["-NoProfile", "-Command", psScript], 5000);
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
    const friendlyName = String(item.FriendlyName || "Unknown USB Device");
    const comMatch = friendlyName.match(/\((COM\d+)\)/i);
    for (const meta of KNOWN_MODES) {
      if (instanceId.includes(meta.vidPid)) {
        matched.push({
          name: friendlyName,
          comPort: comMatch ? comMatch[1].toUpperCase() : "USB-RAW",
          instanceId,
          status: item.Status || "OK",
          chipset: meta.chipset,
          mode: meta.mode,
          engine: meta.engine
        });
      }
    }
  }
  return { ok: true, devices: matched, error: null };
}

function parseAdbProps(rawText) {
  const props = {};
  for (const line of rawText.split(/\r?\n/)) {
    const m = line.match(/^\[([^\]]+)\]:\s*\[(.*)\]$/);
    if (m) props[m[1]] = m[2];
  }
  return props;
}

function parseFastbootVars(rawText) {
  const vars = {};
  for (const line of rawText.split(/\r?\n/)) {
    const cleaned = line.replace(/^\(bootloader\)\s*/i, "").trim();
    const idx = cleaned.indexOf(":");
    if (idx > 0) {
      const k = cleaned.slice(0, idx).trim().toLowerCase();
      const v = cleaned.slice(idx + 1).trim();
      vars[k] = v;
    }
  }
  return vars;
}

async function readLiveDeviceIdentity(includeUsbPorts = true) {
  const [adbList, fbList] = await Promise.all([
    runSafeCommand("adb", ["devices", "-l"], 3000),
    runSafeCommand("fastboot", ["devices"], 3000)
  ]);

  const adbLines = (adbList.output || "")
    .split(/\r?\n/)
    .slice(1)
    .map((l) => l.trim())
    .filter((l) => l && !l.startsWith("*"));

  const fbLines = (fbList.output || "")
    .split(/\r?\n/)
    .map((l) => l.trim())
    .filter(Boolean);

  // 1. ADB Mode
  if (adbLines.length > 0) {
    const first = adbLines[0].split(/\s+/);
    const serial = first[0];
    const state = first[1] || "unknown";
    if (state === "device") {
      const propRes = await runSafeCommand("adb", ["shell", "getprop"], 4000);
      const p = parseAdbProps(propRes.output || "");
      const brand = (p["ro.product.brand"] || p["ro.product.manufacturer"] || "ANDROID").toUpperCase();
      return {
        connected: true,
        mode: "ADB",
        transport: "ADB",
        serial,
        brand,
        model: p["ro.product.marketname"] || p["ro.product.model"] || "Android Device",
        codename: p["ro.product.device"] || p["ro.build.product"] || "-",
        chipset: p["ro.board.platform"] || p["ro.hardware"] || p["ro.soc.model"] || "Unknown SoC",
        androidVersion: p["ro.build.version.release"] || "-",
        securityPatch: p["ro.build.version.security_patch"] || "-",
        buildId: p["ro.build.display.id"] || p["ro.build.id"] || "-",
        slot: p["ro.boot.slot_suffix"] || "non-AB",
        bootloader: p["ro.boot.flash.locked"] === "0" || p["ro.boot.verifiedbootstate"] === "orange" ? "UNLOCKED" : "LOCKED",
        antiRollback: "-",
        comPort: "ADB-USB"
      };
    }
    return {
      connected: true,
      mode: "ADB",
      transport: `ADB (${state.toUpperCase()})`,
      serial,
      brand: "ANDROID",
      model: "Allow USB Debugging on phone screen",
      codename: "-",
      chipset: "-",
      androidVersion: "-",
      securityPatch: "-",
      buildId: "-",
      slot: "-",
      bootloader: "UNKNOWN",
      antiRollback: "-",
      comPort: "ADB-USB"
    };
  }

  // 2. Fastboot Mode
  if (fbLines.length > 0) {
    const serial = fbLines[0].split(/\s+/)[0];
    const [varAll, varVer, varProd] = await Promise.all([
      runSafeCommand("fastboot", ["getvar", "all"], 2500),
      runSafeCommand("fastboot", ["getvar", "version"], 1500),
      runSafeCommand("fastboot", ["getvar", "product"], 1500)
    ]);
    const v = parseFastbootVars([varAll.stderr, varAll.output, varVer.stderr, varVer.output, varProd.stderr, varProd.output].join("\n"));
    const product = v["product"] || v["variant"] || `Fastboot Device (${serial})`;
    return {
      connected: true,
      mode: "FASTBOOT",
      transport: "FASTBOOT",
      serial,
      brand: (v["manufacturer"] || "FASTBOOT").toUpperCase(),
      model: product,
      codename: product,
      chipset: v["platform"] || v["hw-revision"] || "Bootloader SoC",
      androidVersion: "Fastboot Mode",
      securityPatch: "-",
      buildId: v["version-bootloader"] || "-",
      slot: v["current-slot"] ? `_${v["current-slot"]}` : "non-AB",
      bootloader: v["unlocked"] === "yes" ? "UNLOCKED" : (v["unlocked"] === "no" ? "LOCKED" : "UNKNOWN"),
      antiRollback: v["anti"] || "-",
      comPort: "FASTBOOT-USB"
    };
  }

  // 3. Hardware Service Port (EDL / BROM / Odin / SPD)
  if (includeUsbPorts) {
    const usb = await scanWindowsUsb();
    if (usb.devices.length > 0) {
      const d = usb.devices[0];
      return {
        connected: true,
        mode: d.mode,
        transport: d.mode,
        serial: d.instanceId,
        brand: d.chipset.toUpperCase(),
        model: d.name,
        codename: d.engine,
        chipset: d.chipset,
        androidVersion: "Hardware Port",
        securityPatch: "-",
        buildId: d.status,
        slot: "-",
        bootloader: "HARDWARE-LEVEL",
        antiRollback: "-",
        comPort: d.comPort
      };
    }
  }

  return { connected: false, mode: "NONE" };
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
      phase: "Auto-Detect Active"
    });
  }

  if (req.method === "GET" && url.pathname === "/api/device-info") {
    const fullUsb = url.searchParams.get("usb") === "1";
    const identity = await readLiveDeviceIdentity(fullUsb);
    return sendJson(res, 200, { ok: true, device: identity });
  }

  if (req.method === "GET" && url.pathname === "/api/scan") {
    const [adb, fastboot, usbModes, identity] = await Promise.all([
      runSafeCommand("adb", ["devices", "-l"]),
      runSafeCommand("fastboot", ["devices"]),
      scanWindowsUsb(),
      readLiveDeviceIdentity(true)
    ]);
    return sendJson(res, 200, {
      ok: true,
      adb,
      fastboot,
      usbModes,
      device: identity,
      scannedAt: new Date().toISOString()
    });
  }

  if (req.method === "POST" && url.pathname === "/api/execute") {
    let body = "";
    req.on("data", (chunk) => (body += chunk));
    req.on("end", async () => {
      let payload = {};
      try { payload = JSON.parse(body || "{}"); } catch {}

      const jobId = payload.jobId || "read-info";
      const jobTitle = payload.jobTitle || "Diagnostic Job";
      const logs = [
        `Engine: Node.js ${process.version}`,
        `Executing Task: ${jobTitle}...`
      ];

      const identity = await readLiveDeviceIdentity(true);

      if (!identity.connected) {
        logs.push("[WARN] No active ADB / Fastboot / EDL / BROM device connected.");
        return sendJson(res, 200, { ok: true, device: identity, logs });
      }

      logs.push(`[ACTIVE MODE: ${identity.mode}] Serial: ${identity.serial} (${identity.comPort})`);
      logs.push(`  â€¢ Brand / Platform     : ${identity.brand}`);
      logs.push(`  â€¢ Model / Product      : ${identity.model}`);
      logs.push(`  â€¢ Codename             : ${identity.codename}`);
      logs.push(`  â€¢ Chipset / SoC        : ${identity.chipset}`);
      logs.push(`  â€¢ OS / Mode            : ${identity.androidVersion}`);
      logs.push(`  â€¢ Active Slot          : ${identity.slot}`);
      logs.push(`  â€¢ Bootloader State     : ${identity.bootloader}`);
      if (identity.antiRollback !== "-") {
        logs.push(`  â€¢ Anti-Rollback (ARB)  : ${identity.antiRollback}`);
      }

      if (jobId === "read-info" && identity.mode === "ADB") {
        const partRes = await runSafeCommand("adb", ["shell", "cat", "/proc/partitions"]);
        if (partRes.ok && partRes.output) {
          const lines = partRes.output.split(/\r?\n/).filter(Boolean).slice(0, 12);
          logs.push("  â€¢ Block Partitions (Top entries):");
          for (const l of lines) logs.push(`      ${l}`);
        }
      }

      logs.push("[OK] Operation completed.");
      return sendJson(res, 200, { ok: true, device: identity, logs });
    });
    return;
  }

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
  console.log(`MSP Tools Auto-Detect (Node.js) running at http://${HOST}:${PORT}`);
});

