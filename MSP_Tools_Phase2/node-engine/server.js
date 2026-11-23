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
      const out = (stdout || "").trim();
      const err = (stderr || "").trim();
      if (error) {
        resolve({
          ok: false,
          output: out,
          stderr: err,
          error: error.code === "ENOENT" ? `'${bin}' not found in system PATH.` : (err || error.message).trim()
        });
        return;
      }
      resolve({ ok: true, output: out, stderr: err, error: null });
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

async function readLiveDeviceIdentity() {
  const [adbList, fbList, usb] = await Promise.all([
    runSafeCommand("adb", ["devices", "-l"]),
    runSafeCommand("fastboot", ["devices"]),
    scanWindowsUsb()
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

  // 1. Check authorized ADB device
  if (adbLines.length > 0) {
    const first = adbLines[0].split(/\s+/);
    const serial = first[0];
    const state = first[1] || "unknown";
    if (state === "device") {
      const propRes = await runSafeCommand("adb", ["shell", "getprop"]);
      const p = parseAdbProps(propRes.output || "");
      return {
        connected: true,
        transport: "ADB",
        serial,
        brand: (p["ro.product.brand"] || p["ro.product.manufacturer"] || "Android").toUpperCase(),
        model: p["ro.product.marketname"] || p["ro.product.model"] || "Unknown Model",
        codename: p["ro.product.device"] || p["ro.build.product"] || "-",
        chipset: p["ro.board.platform"] || p["ro.hardware"] || p["ro.soc.model"] || "Unknown SoC",
        androidVersion: p["ro.build.version.release"] || "-",
        securityPatch: p["ro.build.version.security_patch"] || "-",
        buildId: p["ro.build.display.id"] || p["ro.build.id"] || "-",
        slot: p["ro.boot.slot_suffix"] || "non-AB",
        bootloader: p["ro.boot.flash.locked"] === "0" ? "UNLOCKED" : (p["ro.boot.verifiedbootstate"] === "orange" ? "UNLOCKED" : "LOCKED"),
        comPort: "ADB-USB"
      };
    }
    return {
      connected: true,
      transport: `ADB (${state.toUpperCase()})`,
      serial,
      brand: "ANDROID",
      model: "Authorize USB Debugging on phone screen",
      codename: "-",
      chipset: "-",
      androidVersion: "-",
      securityPatch: "-",
      buildId: "-",
      slot: "-",
      bootloader: "UNKNOWN",
      comPort: "ADB-USB"
    };
  }

  // 2. Check Fastboot device
  if (fbLines.length > 0) {
    const serial = fbLines[0].split(/\s+/)[0];
    const varRes = await runSafeCommand("fastboot", ["getvar", "all"]);
    // fastboot getvar all outputs to stderr
    const v = parseFastbootVars((varRes.stderr || "") + "\n" + (varRes.output || ""));
    return {
      connected: true,
      transport: "FASTBOOT",
      serial,
      brand: (v["product"] || "FASTBOOT DEVICE").toUpperCase(),
      model: v["product"] || "Bootloader Target",
      codename: v["product"] || v["variant"] || "-",
      chipset: v["platform"] || v["hw-revision"] || "Fastboot Interface",
      androidVersion: "Bootloader Mode",
      securityPatch: "-",
      buildId: v["version-bootloader"] || "-",
      slot: v["current-slot"] ? `_${v["current-slot"]}` : "non-AB",
      bootloader: v["unlocked"] === "yes" ? "UNLOCKED" : (v["unlocked"] === "no" ? "LOCKED" : "UNKNOWN"),
      comPort: "FASTBOOT-USB"
    };
  }

  // 3. Check Hardware EDL / BROM / Odin / SPD Port
  if (usb.devices.length > 0) {
    const d = usb.devices[0];
    return {
      connected: true,
      transport: d.mode,
      serial: d.instanceId,
      brand: d.chipset.toUpperCase(),
      model: d.name,
      codename: d.engine,
      chipset: d.chipset,
      androidVersion: "Hardware Service Port",
      securityPatch: "-",
      buildId: d.status,
      slot: "-",
      bootloader: "HARDWARE-LEVEL",
      comPort: d.comPort
    };
  }

  return { connected: false };
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
      phase: "Phase 2"
    });
  }

  if (req.method === "GET" && url.pathname === "/api/device-info") {
    const identity = await readLiveDeviceIdentity();
    return sendJson(res, 200, { ok: true, device: identity });
  }

  if (req.method === "GET" && url.pathname === "/api/scan") {
    const [adb, fastboot, usbModes, identity] = await Promise.all([
      runSafeCommand("adb", ["devices", "-l"]),
      runSafeCommand("fastboot", ["devices"]),
      scanWindowsUsb(),
      readLiveDeviceIdentity()
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
      const selectedProfile = `${payload.brand || ""} / ${payload.model || ""}`;

      const logs = [
        `Engine: Node.js ${process.version} (Phase 2 Inspector)`,
        `Selected Profile: ${selectedProfile}`,
        `Executing Task: ${jobTitle}...`
      ];

      const identity = await readLiveDeviceIdentity();

      if (!identity.connected) {
        logs.push("[WARN] No active Android / EDL / BROM / Odin device detected on USB.");
        logs.push("Tip: Connect device via USB data cable and click SCAN PORTS.");
        return sendJson(res, 200, { ok: true, device: identity, logs });
      }

      logs.push(`[CONNECTED] Transport: ${identity.transport} | Port: ${identity.comPort}`);
      logs.push(`  • Brand / Manufacturer : ${identity.brand}`);
      logs.push(`  • Market Model         : ${identity.model}`);
      logs.push(`  • Device Codename      : ${identity.codename}`);
      logs.push(`  • Chipset / Platform   : ${identity.chipset}`);
      logs.push(`  • Android / Mode       : ${identity.androidVersion}`);
      logs.push(`  • Security Patch       : ${identity.securityPatch}`);
      logs.push(`  • Active Slot          : ${identity.slot}`);
      logs.push(`  • Bootloader State     : ${identity.bootloader}`);

      if (jobId === "read-info" && identity.transport === "ADB") {
        const partRes = await runSafeCommand("adb", ["shell", "cat", "/proc/partitions"]);
        if (partRes.ok && partRes.output) {
          const lines = partRes.output.split(/\r?\n/).filter(Boolean).slice(0, 12);
          logs.push("  • Block Partitions (Top entries):");
          for (const l of lines) logs.push(`      ${l}`);
        }
      }

      logs.push("[OK] Phase 2 Real Device Identity inspection completed.");
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
  console.log(`MSP Tools Phase 2 (Node.js) running at http://${HOST}:${PORT}`);
});
