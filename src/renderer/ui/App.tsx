import { useEffect, useState } from "react";

type BrandItem = { id: string; label: string; color: string };
type ModelItem = { id: string; brand: string; name: string; soc: string; modes: string[] };
type JobOp = { id: string; tab: string; title: string; desc: string; risk: "READ-ONLY" | "AUTHORIZED" };

const BRANDS: BrandItem[] = [
  { id: "XIAOMI", label: "MI / XIAOMI", color: "#ea580c" },
  { id: "SAMSUNG", label: "SAMSUNG", color: "#1d4ed8" },
  { id: "OPPO", label: "OPPO", color: "#15803d" },
  { id: "VIVO", label: "vivo", color: "#0284c7" },
  { id: "REALME", label: "realme", color: "#ca8a04" },
  { id: "TECNO", label: "TECNO", color: "#2563eb" },
  { id: "INFINIX", label: "Infinix", color: "#16a34a" },
  { id: "QUALCOMM", label: "Qualcomm EDL", color: "#b91c1c" },
  { id: "MEDIATEK", label: "MEDIATEK", color: "#0369a1" },
  { id: "UNISOC", label: "UNISOC / SPD", color: "#7e22ce" }
];

const MODELS: ModelItem[] = [
  { id: "auto-detect", brand: "XIAOMI", name: "Auto-Detect Connected Device", soc: "Live ADB / Fastboot / EDL / BROM / META / Sideload", modes: ["adb", "fastboot", "edl", "brom", "meta", "sideload"] }
];

const OPERATIONS: JobOp[] = [
  { id: "read-info", tab: "FUNCTIONS", title: "[DIAG] READ FULL DEVICE & GPT INFO", desc: "Read Model, Chipset, Anti-Rollback & Partition Table via PHP Engine", risk: "READ-ONLY" },
  { id: "check-bl", tab: "FUNCTIONS", title: "[BOOT] CHECK BOOTLOADER & SECURITY STATE", desc: "Verify OEM unlock state and firmware build metadata", risk: "READ-ONLY" },
  { id: "reboot-edl", tab: "BOOT & MODES", title: "[MODE] REBOOT TO EDL / BROM / DOWNLOAD", desc: "Authorized interface transition from ADB/Fastboot", risk: "AUTHORIZED" },
  { id: "safe-wipe", tab: "BOOT & MODES", title: "[RESET] SAFE FACTORY RESET (AUTHORIZED)", desc: "Format userdata & cache with owner consent verification", risk: "AUTHORIZED" },
  { id: "verify-fw", tab: "FIRMWARE", title: "[PKG] VERIFY FIRMWARE & SHA-256 HASH", desc: "Validate Scatter / XML / Tar.md5 / PAC integrity before flashing", risk: "READ-ONLY" }
];

export default function App() {
  const [selectedBrand, setSelectedBrand] = useState("XIAOMI");
  const [search, setSearch] = useState("");
  const [selectedModel, setSelectedModel] = useState<ModelItem>(MODELS[0]);
  const [subTab, setSubTab] = useState("FUNCTIONS");
  const [stagedJob, setStagedJob] = useState<JobOp | null>(null);
  const [phpStatus, setPhpStatus] = useState("Checking PHP...");
  const [logs, setLogs] = useState<string[]>([]);
  const [progress, setProgress] = useState(0);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    window.mspTools?.checkHealth().then((res) => {
      if (res.ok) {
        setPhpStatus(`PHP ${res.phpVersion} Online`);
      } else {
        setPhpStatus("PHP Offline");
      }
    });
  }, []);

  const filteredModels = MODELS.filter((m) => {
    const matchBrand = ["QUALCOMM", "MEDIATEK", "UNISOC"].includes(selectedBrand)
      ? true
      : m.brand === selectedBrand;
    const matchText =
      m.name.toLowerCase().includes(search.toLowerCase()) ||
      m.soc.toLowerCase().includes(search.toLowerCase());
    return matchBrand && matchText;
  });

  async function handleDoJob() {
    if (!stagedJob || busy) return;
    setBusy(true);
    setProgress(25);
    setLogs([
      `==========================================`,
      `EXECUTING: ${stagedJob.title}`,
      `TARGET: ${selectedModel.name} (${selectedModel.soc})`,
      `==========================================`
    ]);

    const res = await window.mspTools.executeJob({
      jobId: stagedJob.id,
      brand: selectedBrand,
      model: selectedModel.name
    });

    setProgress(85);
    if (res.ok && res.logs) {
      setLogs((prev) => [...prev, ...res.logs!]);
    } else {
      setLogs((prev) => [...prev, `[ERROR] ${res.error || "Execution failed"}`]);
    }
    setProgress(100);
    setBusy(false);
  }

  async function handleQuickScan() {
    if (busy) return;
    setBusy(true);
    setProgress(40);
    const res = await window.mspTools.scanDevices();
    if (res.ok) {
      const usbCount = res.usbModes?.devices.length ?? 0;
      setLogs([
        `[USB SCAN] Found ${usbCount} service port(s) (EDL/BROM/Odin/Fastboot)`,
        `[ADB] ${res.adb?.output || res.adb?.error || "No ADB devices"}`,
        `[FASTBOOT] ${res.fastboot?.output || res.fastboot?.error || "No Fastboot devices"}`
      ]);
    } else {
      setLogs([`[ERROR] ${res.error}`]);
    }
    setProgress(100);
    setBusy(false);
  }

  return (
    <div className="app-shell">
      <div className="titlebar">
        <div className="brand-group">
          <span className="logo-badge">M</span>
          <span className="title-gold">MSP TOOLS</span>
          <span className="tag-pill">v1.0.0 · MULTI-BRAND SERVICE PLATFORM</span>
        </div>
        <span className="tag-pill">{phpStatus}</span>
      </div>

      <div className="brand-grid">
        {BRANDS.map((b) => (
          <button
            key={b.id}
            className={`brand-tile ${selectedBrand === b.id ? "active" : ""}`}
            style={{ backgroundColor: b.color }}
            onClick={() => {
              setSelectedBrand(b.id);
              const first = MODELS.find((m) => m.brand === b.id);
              if (first) setSelectedModel(first);
            }}
          >
            {b.label}
          </button>
        ))}
      </div>

      <div className="subbar">
        <div className="tabs">
          {["FUNCTIONS", "BOOT & MODES", "FIRMWARE"].map((t) => (
            <button
              key={t}
              className={`tab-btn ${subTab === t ? "active" : ""}`}
              onClick={() => setSubTab(t)}
            >
              {t}
            </button>
          ))}
        </div>
        <div className="quick-tools">
          <button className="tool-btn" onClick={handleQuickScan}>SCAN PORTS</button>
          <button className="tool-btn" onClick={() => { setLogs([]); setProgress(0); }}>CLEAR LOG</button>
        </div>
      </div>

      <div className="workspace">
        {/* Left: Model & SoC Selector */}
        <section className="col-models">
          <div className="search-box">
            <input
              placeholder="Search model or chipset..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
          </div>
          <div className="model-list">
            {filteredModels.map((m) => (
              <div
                key={m.id}
                className={`model-item ${selectedModel.id === m.id ? "selected" : ""}`}
                onClick={() => setSelectedModel(m)}
              >
                <div className="model-soc">{m.soc}</div>
                <div className="model-name">{m.name}</div>
                <div className="mode-badges">
                  {m.modes.map((md) => (
                    <span key={md} className="mode-tag">{md}</span>
                  ))}
                </div>
              </div>
            ))}
          </div>
        </section>

        {/* Center: Operation Selection + Staged DO JOB Bar */}
        <section className="col-ops">
          <div className="subtabs">
            <span className="tag-pill">SELECTED: {selectedModel.name}</span>
          </div>
          <div className="ops-body">
            {OPERATIONS.filter((o) => o.tab === subTab).map((op) => (
              <button
                key={op.id}
                className={`op-card ${stagedJob?.id === op.id ? "selected" : ""}`}
                onClick={() => setStagedJob(op)}
              >
                <div>
                  <div className="op-title">{op.title}</div>
                  <div className="op-desc">{op.desc}</div>
                </div>
                <span className="tag-pill">{op.risk}</span>
              </button>
            ))}
          </div>
          <div className="staged-panel">
            <div style={{ fontSize: 11 }}>
              <div style={{ color: "#64748b", fontWeight: 700 }}>STAGED JOB READY:</div>
              <div style={{ color: stagedJob ? "#fbbf24" : "#94a3b8", fontWeight: 700 }}>
                {stagedJob ? stagedJob.title : "Select an operation above, then click DO JOB"}
              </div>
            </div>
            <button
              className="do-job-btn"
              disabled={!stagedJob || busy}
              onClick={handleDoJob}
            >
              {busy ? "RUNNING..." : "▶ DO JOB"}
            </button>
          </div>
        </section>

        {/* Right: Live Terminal & Dual Progress Bars */}
        <section className="col-term">
          <div className="port-bar">
            <div className="port-row">
              <span style={{ color: "#94a3b8", fontWeight: 700 }}>USB:</span>
              <span style={{ color: "#6ee7b7" }}>Auto-Detect (EDL / BROM / Odin / ADB)</span>
            </div>
            <div className="port-row">
              <span style={{ color: "#94a3b8", fontWeight: 700 }}>ENGINE:</span>
              <span>127.0.0.1:8910 (PHP Service)</span>
            </div>
          </div>
          <div className="terminal-out">
            {logs.length === 0 ? (
              <div className="term-empty">
                <div style={{ fontWeight: 700, color: "#64748b" }}>MSP TOOLS CONSOLE IDLE</div>
                <div style={{ fontSize: 10, marginTop: 4 }}>
                  Select a model & function, then press <b>▶ DO JOB</b>
                </div>
              </div>
            ) : (
              logs.map((line, i) => <div key={i}>{line}</div>)
            )}
          </div>
          <div className="progress-box">
            <div style={{ display: "flex", gap: 8 }}>
              <div style={{ flex: 1 }}>
                <div className="pbar-track">
                  <div className="pbar-fill" style={{ width: `${progress}%` }} />
                  <div className="pbar-label">{progress} %</div>
                </div>
                <div className="pbar-track" style={{ marginBottom: 0 }}>
                  <div className="pbar-fill" style={{ width: `${progress}%` }} />
                  <div className="pbar-label">{progress} %</div>
                </div>
              </div>
              <button className="stop-btn" onClick={() => setBusy(false)}>STOP</button>
            </div>
          </div>
        </section>
      </div>

      <div className="statusbar">
        <span>MSP Tools · https://github.com/KhunMyintAung/MSP_Tools</span>
        <span>Authorized Multi-Brand Service Platform</span>
      </div>
    </div>
  );
}
