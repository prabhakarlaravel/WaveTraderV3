import { useState, useEffect, useRef, useCallback, useMemo } from "react";

// ── Color System ──
const C = {
  bg: "#06090f", card: "#0c1221", cardAlt: "#101a2e", surface: "#080d18",
  border: "#162040", borderHi: "#1e3060",
  text: "#dfe6f2", muted: "#7b8ba8", dim: "#4a5978",
  bull: "#00dc82", bullFade: "rgba(0,220,130,0.06)", bullLine: "rgba(0,220,130,0.35)",
  bear: "#ff3b5c", bearFade: "rgba(255,59,92,0.06)", bearLine: "rgba(255,59,92,0.35)",
  wave: "#8b5cf6", waveBg: "rgba(139,92,246,0.10)", waveLine: "rgba(139,92,246,0.5)",
  ob: "#f59e0b", obBg: "rgba(245,158,11,0.08)", obLine: "rgba(245,158,11,0.3)",
  fvg: "#06b6d4", fvgBg: "rgba(6,182,212,0.07)", fvgLine: "rgba(6,182,212,0.25)",
  vwap: "#ec4899", vwapBg: "rgba(236,72,153,0.06)", vwapLine: "rgba(236,72,153,0.4)",
  bos: "#10b981", choch: "#f97316",
  accent: "#3b82f6", accentBg: "rgba(59,130,246,0.1)",
  impulse: "#8b5cf6", correction: "#f59e0b",
};
const MONO = "'JetBrains Mono','Fira Code',monospace";
const SANS = "'DM Sans','Segoe UI',sans-serif";

// ── Realistic candle generator with trends ──
function generateRealisticCandles(count = 200) {
  const candles = [];
  let price = 67800;
  // Create wave-like price movement
  const phases = [
    { len: 25, trend: 1, vol: 1.2 },   // wave 1 up
    { len: 15, trend: -0.6, vol: 0.8 }, // wave 2 down
    { len: 35, trend: 1.5, vol: 1.5 },  // wave 3 up (strongest)
    { len: 12, trend: -0.4, vol: 0.7 }, // wave 4 down (shallow)
    { len: 20, trend: 0.8, vol: 1.0 },  // wave 5 up
    { len: 18, trend: -0.7, vol: 0.9 }, // wave A down
    { len: 12, trend: 0.5, vol: 0.6 },  // wave B up
    { len: 20, trend: -0.9, vol: 1.1 }, // wave C down
    { len: 25, trend: 1.3, vol: 1.3 },  // new wave 1
    { len: 10, trend: -0.5, vol: 0.7 }, // new wave 2
    { len: 20, trend: 1.6, vol: 1.4 },  // new wave 3 developing
  ];
  let phaseIdx = 0, phaseCandle = 0;
  for (let i = 0; i < count; i++) {
    const phase = phases[phaseIdx % phases.length];
    const noise = (Math.random() - 0.5) * 120;
    const trendMove = phase.trend * (15 + Math.random() * 25);
    const change = trendMove + noise;
    const open = price;
    const close = price + change;
    const wick = 30 + Math.random() * 80;
    const high = Math.max(open, close) + Math.random() * wick;
    const low = Math.min(open, close) - Math.random() * wick;
    const vol = Math.floor((40 + Math.random() * 160) * phase.vol);
    const t = Date.now() - (count - i) * 900000; // 15m candles
    candles.push({ o: open, h: high, l: low, c: close, v: vol, t, i });
    price = close;
    phaseCandle++;
    if (phaseCandle >= phase.len) { phaseCandle = 0; phaseIdx++; }
  }
  return candles;
}

// ── Derive swing points for wave labels ──
function findSwingPoints(candles, strength = 5) {
  const swings = [];
  for (let i = strength; i < candles.length - strength; i++) {
    let isHigh = true, isLow = true;
    for (let j = 1; j <= strength; j++) {
      if (candles[i].h <= candles[i - j].h || candles[i].h <= candles[i + j].h) isHigh = false;
      if (candles[i].l >= candles[i - j].l || candles[i].l >= candles[i + j].l) isLow = false;
    }
    if (isHigh) swings.push({ idx: i, type: "HIGH", price: candles[i].h });
    if (isLow) swings.push({ idx: i, type: "LOW", price: candles[i].l });
  }
  return swings;
}

// ── Derive wave labels from swings ──
function deriveWaveLabels(swings) {
  if (swings.length < 5) return [];
  const labels = [];
  const waveSequence = ["1", "2", "3", "4", "5", "A", "B", "C"];
  let wIdx = 0;
  // Alternate high/low assignment
  let filtered = [swings[0]];
  for (let i = 1; i < swings.length; i++) {
    if (swings[i].type !== filtered[filtered.length - 1].type) {
      filtered.push(swings[i]);
    } else {
      // Same type: keep the more extreme
      const last = filtered[filtered.length - 1];
      if (swings[i].type === "HIGH" && swings[i].price > last.price) filtered[filtered.length - 1] = swings[i];
      if (swings[i].type === "LOW" && swings[i].price < last.price) filtered[filtered.length - 1] = swings[i];
    }
  }
  for (let i = 0; i < Math.min(filtered.length, waveSequence.length * 2); i++) {
    const label = waveSequence[wIdx % waveSequence.length];
    labels.push({ ...filtered[i], label, isCorrection: ["A", "B", "C"].includes(label) });
    wIdx++;
  }
  return labels;
}

// ── Derive Order Blocks ──
function deriveOrderBlocks(candles) {
  const obs = [];
  for (let i = 3; i < candles.length - 3; i++) {
    const c = candles[i];
    const prev = candles[i - 1];
    const next = candles[i + 1];
    // Bullish OB: down candle followed by strong up move
    if (c.c < c.o && next.c > next.o && (next.c - next.o) > (c.o - c.c) * 1.5) {
      if (Math.random() > 0.7) {
        obs.push({ startIdx: i, endIdx: i + 1, high: Math.max(c.o, c.h), low: c.l, type: "BULL", status: Math.random() > 0.5 ? "FRESH" : "TESTED" });
      }
    }
    // Bearish OB: up candle followed by strong down move
    if (c.c > c.o && next.c < next.o && (next.o - next.c) > (c.c - c.o) * 1.5) {
      if (Math.random() > 0.7) {
        obs.push({ startIdx: i, endIdx: i + 1, high: c.h, low: Math.min(c.o, c.l), type: "BEAR", status: Math.random() > 0.5 ? "FRESH" : "TESTED" });
      }
    }
  }
  return obs.slice(-6); // Keep last 6
}

// ── Derive FVG zones ──
function deriveFVGs(candles) {
  const fvgs = [];
  for (let i = 2; i < candles.length; i++) {
    const c0 = candles[i - 2], c2 = candles[i];
    // Bullish FVG: gap between candle 0 high and candle 2 low
    if (c2.l > c0.h && (c2.l - c0.h) > 20) {
      if (Math.random() > 0.85) fvgs.push({ idx: i - 1, high: c2.l, low: c0.h, type: "BULL" });
    }
    // Bearish FVG
    if (c0.l > c2.h && (c0.l - c2.h) > 20) {
      if (Math.random() > 0.85) fvgs.push({ idx: i - 1, high: c0.l, low: c2.h, type: "BEAR" });
    }
  }
  return fvgs.slice(-5);
}

// ── Derive BOS/CHOCH points ──
function deriveBosChoch(candles, swings) {
  const markers = [];
  for (let i = 1; i < swings.length - 1; i++) {
    const s = swings[i];
    // Check if next candles break this swing
    for (let j = s.idx + 1; j < Math.min(s.idx + 15, candles.length); j++) {
      if (s.type === "HIGH" && candles[j].c > s.price) {
        if (Math.random() > 0.6) markers.push({ idx: j, price: s.price, type: "BOS", dir: "BULL" });
        break;
      }
      if (s.type === "LOW" && candles[j].c < s.price) {
        if (Math.random() > 0.6) markers.push({ idx: j, price: s.price, type: "BOS", dir: "BEAR" });
        break;
      }
    }
  }
  // Add a CHOCH
  if (markers.length > 3) {
    const last = markers[markers.length - 1];
    markers.push({ ...last, type: "CHOCH", idx: last.idx + 5 < candles.length ? last.idx + 5 : last.idx });
  }
  return markers.slice(-6);
}

// ── Compute VWAP ──
function computeVwap(candles) {
  let cumTPV = 0, cumVol = 0;
  return candles.map((c, i) => {
    const tp = (c.h + c.l + c.c) / 3;
    cumTPV += tp * c.v;
    cumVol += c.v;
    const vwap = cumTPV / cumVol;
    // Simple std dev approximation for bands
    const slice = candles.slice(Math.max(0, i - 20), i + 1);
    const avg = slice.reduce((s, x) => s + (x.h + x.l + x.c) / 3, 0) / slice.length;
    const variance = slice.reduce((s, x) => s + Math.pow((x.h + x.l + x.c) / 3 - avg, 2), 0) / slice.length;
    const std = Math.sqrt(variance);
    return { vwap, upper1: vwap + std, lower1: vwap - std, upper2: vwap + std * 2, lower2: vwap - std * 2 };
  });
}

// ── Direction Arrow ──
function DirArrow({ dir, size = 14 }) {
  const color = dir === "BULL" ? C.bull : dir === "BEAR" ? C.bear : C.muted;
  const rot = dir === "BULL" ? -45 : dir === "BEAR" ? 45 : 0;
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" style={{ display: "block", transform: `rotate(${rot}deg)` }}>
      <path d="M5 12h14M13 6l6 6-6 6" fill="none" stroke={color} strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  );
}

// ── Wave Badge ──
function WaveBadge({ wave, of, small }) {
  const isCorr = ["A", "B", "C"].includes(wave);
  return (
    <span style={{
      display: "inline-flex", alignItems: "baseline", gap: "1px",
      background: isCorr ? "rgba(245,158,11,0.12)" : "rgba(139,92,246,0.12)",
      border: `1px solid ${isCorr ? "rgba(245,158,11,0.25)" : "rgba(139,92,246,0.25)"}`,
      borderRadius: "5px", padding: small ? "1px 6px" : "2px 8px", lineHeight: 1,
    }}>
      <span style={{ fontFamily: MONO, fontSize: small ? "11px" : "14px", fontWeight: 700, color: isCorr ? C.correction : C.impulse }}>{wave}</span>
      {of && <span style={{ fontFamily: MONO, fontSize: small ? "8px" : "9px", color: C.dim }}> of {of}</span>}
    </span>
  );
}

// ── Confidence Ring ──
function ConfRing({ value, size = 28 }) {
  const r = (size - 3) / 2, circ = 2 * Math.PI * r, offset = circ * (1 - value / 100);
  const color = value >= 75 ? C.bull : value >= 55 ? C.accent : value >= 35 ? C.correction : C.bear;
  return (
    <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} style={{ display: "block" }}>
      <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke={C.border} strokeWidth="2" />
      <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke={color} strokeWidth="2"
        strokeDasharray={circ} strokeDashoffset={offset} strokeLinecap="round"
        transform={`rotate(-90 ${size / 2} ${size / 2})`} />
      <text x={size / 2} y={size / 2 + 3.5} textAnchor="middle" fill={color} fontSize="8" fontWeight="700" fontFamily={MONO}>{value}</text>
    </svg>
  );
}

// ── Main Chart Component ──
function TradingChart({ candles, visibleRange, overlays, onRangeChange }) {
  const containerRef = useRef(null);
  const [tooltip, setTooltip] = useState(null);
  const [containerWidth, setContainerWidth] = useState(800);

  useEffect(() => {
    const el = containerRef.current;
    if (!el) return;
    const obs = new ResizeObserver(entries => {
      for (const e of entries) setContainerWidth(e.contentRect.width);
    });
    obs.observe(el);
    setContainerWidth(el.clientWidth);
    return () => obs.disconnect();
  }, []);

  const start = visibleRange[0], end = visibleRange[1];
  const visible = candles.slice(start, end);
  const width = containerWidth;
  const height = 420;
  const volH = 60;
  const pad = { t: 16, r: 62, b: 4, l: 4 };
  const chartH = height - volH - pad.t - pad.b;
  const cw = Math.max(2, (width - pad.l - pad.r) / visible.length);

  const maxP = Math.max(...visible.map(c => c.h));
  const minP = Math.min(...visible.map(c => c.l));
  const pRange = (maxP - minP) || 1;
  const maxV = Math.max(...visible.map(c => c.v));

  const y = (p) => pad.t + ((maxP - p) / pRange) * chartH;
  const x = (idx) => pad.l + (idx - start) * cw;

  // Derived data
  const swings = useMemo(() => findSwingPoints(candles, 5), [candles]);
  const waveLabels = useMemo(() => deriveWaveLabels(swings), [swings]);
  const orderBlocks = useMemo(() => deriveOrderBlocks(candles), [candles]);
  const fvgs = useMemo(() => deriveFVGs(candles), [candles]);
  const bosChoch = useMemo(() => deriveBosChoch(candles, swings), [candles, swings]);
  const vwapData = useMemo(() => computeVwap(candles), [candles]);

  // Filter to visible range
  const visWaves = waveLabels.filter(w => w.idx >= start && w.idx < end);
  const visOBs = orderBlocks.filter(o => o.endIdx >= start && o.startIdx < end);
  const visFVGs = fvgs.filter(f => f.idx >= start && f.idx < end);
  const visBos = bosChoch.filter(b => b.idx >= start && b.idx < end);
  const visVwap = vwapData.slice(start, end);

  // Wave connection line
  const waveLinePath = visWaves.length >= 2
    ? visWaves.map((w, i) => `${i === 0 ? "M" : "L"}${x(w.idx) + cw / 2},${y(w.price)}`).join(" ")
    : "";

  // VWAP paths
  const vwapPath = visVwap.map((v, i) => `${i === 0 ? "M" : "L"}${pad.l + i * cw + cw / 2},${y(v.vwap)}`).join(" ");
  const vwapU1 = visVwap.map((v, i) => `${i === 0 ? "M" : "L"}${pad.l + i * cw + cw / 2},${y(v.upper1)}`).join(" ");
  const vwapL1 = visVwap.map((v, i) => `${i === 0 ? "M" : "L"}${pad.l + i * cw + cw / 2},${y(v.lower1)}`).join(" ");
  // Band fill
  const vwapBandPath = visVwap.length > 1 ? (
    visVwap.map((v, i) => `${i === 0 ? "M" : "L"}${pad.l + i * cw + cw / 2},${y(v.upper1)}`).join(" ") +
    [...visVwap].reverse().map((v, i) => `L${pad.l + (visVwap.length - 1 - i) * cw + cw / 2},${y(v.lower1)}`).join(" ") + "Z"
  ) : "";

  // Price grid lines
  const gridSteps = 6;
  const gridLines = Array.from({ length: gridSteps }, (_, i) => {
    const p = minP + (pRange / (gridSteps - 1)) * i;
    return { price: p, y: y(p) };
  });

  // Current price
  const lastCandle = visible[visible.length - 1];
  const lastPrice = lastCandle?.c || 0;
  const lastBull = lastCandle ? lastCandle.c >= lastCandle.o : true;

  // Handle scroll
  const handleWheel = useCallback((e) => {
    e.preventDefault();
    const delta = e.deltaY > 0 ? 10 : -10;
    const newStart = Math.max(0, start + delta);
    const newEnd = Math.min(candles.length, end + delta);
    if (newEnd - newStart >= 30) onRangeChange([newStart, newEnd]);
  }, [start, end, candles.length, onRangeChange]);

  return (
    <div ref={containerRef} style={{ width: "100%", position: "relative", background: C.card, borderRadius: "10px", border: `1px solid ${C.border}`, overflow: "hidden" }}>
      <svg width="100%" height={height + volH} viewBox={`0 0 ${width} ${height + volH}`}
        style={{ display: "block", cursor: "crosshair" }}
        onWheel={handleWheel}
        onMouseMove={(e) => {
          const rect = e.currentTarget.getBoundingClientRect();
          const mx = (e.clientX - rect.left) * (width / rect.width);
          const idx = Math.floor((mx - pad.l) / cw) + start;
          if (idx >= start && idx < end && candles[idx]) {
            setTooltip({ x: e.clientX - rect.left, y: e.clientY - rect.top, candle: candles[idx], idx });
          } else setTooltip(null);
        }}
        onMouseLeave={() => setTooltip(null)}
      >
        {/* Grid */}
        {gridLines.map((g, i) => (
          <g key={i}>
            <line x1={pad.l} y1={g.y} x2={width - pad.r} y2={g.y} stroke={C.border} strokeWidth="0.5" />
            <text x={width - pad.r + 5} y={g.y + 3} fill={C.dim} fontSize="9" fontFamily={MONO}>{g.price.toFixed(0)}</text>
          </g>
        ))}

        {/* ── VWAP Bands ── */}
        {overlays.vwap && visVwap.length > 1 && (
          <g>
            <path d={vwapBandPath} fill={C.vwapBg} />
            <path d={vwapU1} fill="none" stroke={C.vwapLine} strokeWidth="0.6" strokeDasharray="3 2" />
            <path d={vwapL1} fill="none" stroke={C.vwapLine} strokeWidth="0.6" strokeDasharray="3 2" />
            <path d={vwapPath} fill="none" stroke={C.vwap} strokeWidth="1.2" opacity="0.7" />
          </g>
        )}

        {/* ── Order Blocks ── */}
        {overlays.ob && visOBs.map((ob, i) => {
          const obX = x(ob.startIdx);
          const obW = Math.max((end - ob.startIdx) * cw, cw * 3);
          const obY = y(ob.high);
          const obH = y(ob.low) - obY;
          return (
            <g key={`ob-${i}`}>
              <rect x={obX} y={obY} width={Math.min(obW, width - pad.r - obX)} height={Math.max(obH, 4)}
                fill={ob.type === "BULL" ? C.obBg : C.obBg} stroke={C.obLine} strokeWidth="0.5" rx="2" strokeDasharray={ob.status === "TESTED" ? "4 2" : "none"} />
              <rect x={obX} y={ob.type === "BULL" ? obY + obH - 2 : obY} width={Math.min(obW, width - pad.r - obX)} height="2"
                fill={ob.type === "BULL" ? C.bull : C.bear} opacity="0.5" rx="1" />
              <text x={obX + 4} y={obY + 11} fill={C.ob} fontSize="8" fontFamily={MONO} fontWeight="600" opacity="0.8">
                {ob.type === "BULL" ? "▲" : "▼"} OB {ob.status === "FRESH" ? "●" : "○"}
              </text>
            </g>
          );
        })}

        {/* ── FVG Zones ── */}
        {overlays.fvg && visFVGs.map((fvg, i) => {
          const fX = x(fvg.idx);
          const fW = Math.max((end - fvg.idx) * cw, cw * 2);
          return (
            <g key={`fvg-${i}`}>
              <rect x={fX} y={y(fvg.high)} width={Math.min(fW, width - pad.r - fX)}
                height={Math.max(y(fvg.low) - y(fvg.high), 3)}
                fill={C.fvgBg} stroke={C.fvgLine} strokeWidth="0.5" rx="1" />
              <text x={fX + 4} y={y(fvg.high) + 10} fill={C.fvg} fontSize="7" fontFamily={MONO} fontWeight="600" opacity="0.7">FVG</text>
            </g>
          );
        })}

        {/* ── Candles ── */}
        {visible.map((c, i) => {
          const bull = c.c >= c.o;
          const cx = pad.l + i * cw;
          const bodyTop = y(Math.max(c.o, c.c));
          const bodyBot = y(Math.min(c.o, c.c));
          const bodyH = Math.max(bodyBot - bodyTop, 1);
          return (
            <g key={i}>
              <line x1={cx + cw / 2} y1={y(c.h)} x2={cx + cw / 2} y2={y(c.l)}
                stroke={bull ? C.bull : C.bear} strokeWidth={cw > 6 ? "1" : "0.6"} opacity="0.7" />
              <rect x={cx + (cw > 6 ? 1 : 0.5)} y={bodyTop} width={Math.max(cw - (cw > 6 ? 2 : 1), 1)} height={bodyH}
                fill={bull ? C.bull : C.bear} opacity="0.85" rx={cw > 8 ? "1" : "0"} />
            </g>
          );
        })}

        {/* ── Wave Labels on Chart ── */}
        {overlays.waves && visWaves.length >= 2 && (
          <path d={waveLinePath} fill="none" stroke={C.waveLine} strokeWidth="1.5" strokeDasharray="6 3" />
        )}
        {overlays.waves && visWaves.map((w, i) => {
          const wx = x(w.idx) + cw / 2;
          const isAbove = w.type === "HIGH";
          const wy = isAbove ? y(w.price) - 18 : y(w.price) + 18;
          const isCorr = w.isCorrection;
          const bgColor = isCorr ? "rgba(245,158,11,0.9)" : "rgba(139,92,246,0.9)";
          return (
            <g key={`wl-${i}`}>
              <line x1={wx} y1={y(w.price)} x2={wx} y2={isAbove ? wy + 8 : wy - 8}
                stroke={isCorr ? C.correction : C.wave} strokeWidth="0.8" opacity="0.5" />
              <circle cx={wx} cy={wy} r="10" fill={C.card} stroke={isCorr ? C.correction : C.wave} strokeWidth="1.5" />
              <text x={wx} y={wy + 4} textAnchor="middle" fill={isCorr ? C.correction : C.wave}
                fontSize="11" fontWeight="700" fontFamily={MONO}>{w.label}</text>
            </g>
          );
        })}

        {/* ── BOS / CHOCH Markers ── */}
        {overlays.bos && visBos.map((b, i) => {
          const bx = x(b.idx) + cw / 2;
          const by = y(b.price);
          const isBos = b.type === "BOS";
          const color = isBos ? C.bos : C.choch;
          const lineStart = Math.max(pad.l, x(b.idx - 8));
          return (
            <g key={`bos-${i}`}>
              <line x1={lineStart} y1={by} x2={bx + cw * 3} y2={by}
                stroke={color} strokeWidth="1" strokeDasharray="4 2" opacity="0.6" />
              <rect x={bx - 20} y={by - 8} width="40" height="16" rx="4" fill={C.card} stroke={color} strokeWidth="1" />
              <text x={bx} y={by + 3.5} textAnchor="middle" fill={color}
                fontSize="8" fontWeight="700" fontFamily={MONO}>
                {b.type} {b.dir === "BULL" ? "↑" : "↓"}
              </text>
            </g>
          );
        })}

        {/* ── Current Price Line ── */}
        {lastCandle && (
          <g>
            <line x1={pad.l} y1={y(lastPrice)} x2={width - pad.r} y2={y(lastPrice)}
              stroke={lastBull ? C.bull : C.bear} strokeWidth="0.8" strokeDasharray="2 2" opacity="0.6" />
            <rect x={width - pad.r} y={y(lastPrice) - 10} width="58" height="20" rx="4"
              fill={lastBull ? C.bull : C.bear} />
            <text x={width - pad.r + 29} y={y(lastPrice) + 3.5} textAnchor="middle" fill="#fff"
              fontSize="10" fontWeight="700" fontFamily={MONO}>{lastPrice.toFixed(0)}</text>
          </g>
        )}

        {/* ── Volume Bars ── */}
        {visible.map((c, i) => {
          const bull = c.c >= c.o;
          const barH = (c.v / maxV) * (volH - 8);
          const vY = height + volH - barH;
          return (
            <rect key={`v-${i}`} x={pad.l + i * cw + (cw > 6 ? 1 : 0.5)} y={vY}
              width={Math.max(cw - (cw > 6 ? 2 : 1), 1)} height={barH}
              fill={bull ? C.bull : C.bear} opacity="0.2" rx={cw > 8 ? "1" : "0"} />
          );
        })}

        {/* Crosshair */}
        {tooltip && (
          <g>
            <line x1={tooltip.x * (width / containerWidth)} y1={pad.t} x2={tooltip.x * (width / containerWidth)} y2={height}
              stroke={C.dim} strokeWidth="0.5" strokeDasharray="2 2" />
          </g>
        )}
      </svg>

      {/* Tooltip */}
      {tooltip && tooltip.candle && (
        <div style={{
          position: "absolute", top: 8, left: 8, background: "rgba(12,18,33,0.95)",
          border: `1px solid ${C.border}`, borderRadius: "8px", padding: "8px 12px",
          pointerEvents: "none", zIndex: 10, backdropFilter: "blur(8px)",
        }}>
          <div style={{ display: "flex", gap: "12px", fontSize: "11px", fontFamily: MONO }}>
            <span style={{ color: C.muted }}>O <span style={{ color: C.text }}>{tooltip.candle.o.toFixed(1)}</span></span>
            <span style={{ color: C.muted }}>H <span style={{ color: C.text }}>{tooltip.candle.h.toFixed(1)}</span></span>
            <span style={{ color: C.muted }}>L <span style={{ color: C.text }}>{tooltip.candle.l.toFixed(1)}</span></span>
            <span style={{ color: C.muted }}>C <span style={{ color: tooltip.candle.c >= tooltip.candle.o ? C.bull : C.bear }}>{tooltip.candle.c.toFixed(1)}</span></span>
            <span style={{ color: C.muted }}>V <span style={{ color: C.text }}>{tooltip.candle.v}</span></span>
          </div>
        </div>
      )}
    </div>
  );
}

// ── Main App ──
export default function WaveTraderDashboard() {
  const [candles] = useState(() => generateRealisticCandles(200));
  const [visibleRange, setVisibleRange] = useState([80, 200]);
  const [selectedInst, setSelectedInst] = useState("BTCUSDT");
  const [selectedTf, setSelectedTf] = useState("15m");
  const [matrixOpen, setMatrixOpen] = useState(true);
  const [overlays, setOverlays] = useState({ waves: true, ob: true, fvg: true, bos: true, vwap: true });
  const [expandedWave, setExpandedWave] = useState(null);
  const [time, setTime] = useState(new Date());

  useEffect(() => {
    const l = document.createElement("link");
    l.href = "https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600;700&display=swap";
    l.rel = "stylesheet";
    document.head.appendChild(l);
    const t = setInterval(() => setTime(new Date()), 1000);
    return () => clearInterval(t);
  }, []);

  const toggle = (key) => setOverlays(p => ({ ...p, [key]: !p[key] }));

  const instruments = [
    { symbol: "BTCUSDT", name: "BTC/USDT", market: "CRYPTO" },
    { symbol: "BANKNIFTY", name: "BANK NIFTY", market: "INDEX" },
    { symbol: "NIFTY", name: "NIFTY 50", market: "INDEX" },
    { symbol: "XAUUSD", name: "GOLD", market: "FOREX" },
    { symbol: "EURUSD", name: "EUR/USD", market: "FOREX" },
    { symbol: "CRUDEOIL", name: "CRUDE", market: "COMMODITY" },
  ];

  const waveMatrix = [
    { tf: "1D", wave: "3", of: "", degree: "PRIMARY", phase: "IMPULSE", dir: "BULL", pct: 62, target: "72,400", fib: "1.618 ext", conf: 88, note: "Strong wave 3 — extended target" },
    { tf: "4H", wave: "5", of: "3", degree: "INTERMEDIATE", phase: "IMPULSE", dir: "BULL", pct: 78, target: "69,800", fib: "1.0 ext", conf: 82, note: "Nearing wave 5 top of larger 3" },
    { tf: "1H", wave: "3", of: "5", degree: "MINOR", phase: "IMPULSE", dir: "BULL", pct: 55, target: "69,200", fib: "1.618 ext", conf: 76, note: "Mid impulse — strong momentum" },
    { tf: "15m", wave: "4", of: "", degree: "MINUTE", phase: "CORRECTION", dir: "BEAR", pct: 40, target: "67,800", fib: "0.382 ret", conf: 68, note: "Shallow pullback — wave 4" },
    { tf: "5m", wave: "C", of: "4", degree: "MINUETTE", phase: "CORRECTION", dir: "BEAR", pct: 70, target: "67,900", fib: "1.0 of A", conf: 62, note: "Wave C completing" },
    { tf: "1m", wave: "5", of: "C", degree: "SUB-MIN", phase: "IMPULSE", dir: "BEAR", pct: 85, target: "67,850", fib: "0.618", conf: 55, note: "Final push — reversal near" },
  ];

  const htfBull = waveMatrix.slice(0, 3).filter(w => w.dir === "BULL").length;
  const ltfBull = waveMatrix.slice(3).filter(w => w.dir === "BULL").length;
  const htfDir = htfBull >= 2 ? "BULL" : "BEAR";
  const ltfDir = ltfBull >= 2 ? "BULL" : "BEAR";
  const aligned = htfDir === ltfDir;

  const lastCandle = candles[candles.length - 1];
  const prevCandle = candles[candles.length - 2];
  const priceChange = lastCandle && prevCandle ? ((lastCandle.c - prevCandle.c) / prevCandle.c * 100) : 0;
  const bull = priceChange >= 0;

  const overlayConfig = [
    { key: "waves", label: "Waves", color: C.wave },
    { key: "ob", label: "OB", color: C.ob },
    { key: "fvg", label: "FVG", color: C.fvg },
    { key: "bos", label: "BOS", color: C.bos },
    { key: "vwap", label: "VWAP", color: C.vwap },
  ];

  return (
    <div style={{ fontFamily: SANS, background: C.bg, color: C.text, minHeight: "100vh", display: "flex", flexDirection: "column" }}>
      {/* ── Top Bar ── */}
      <div style={{
        display: "flex", alignItems: "center", padding: "8px 12px", gap: "8px",
        borderBottom: `1px solid ${C.border}`, background: C.surface, flexWrap: "wrap",
      }}>
        {/* Logo */}
        <div style={{ display: "flex", alignItems: "center", gap: "6px", marginRight: "8px" }}>
          <div style={{
            width: "26px", height: "26px", borderRadius: "6px",
            background: "linear-gradient(135deg, #8b5cf6, #6366f1)",
            display: "flex", alignItems: "center", justifyContent: "center",
            fontSize: "13px", fontWeight: 800, color: "#fff", fontFamily: MONO,
          }}>W</div>
          <span style={{ fontSize: "13px", fontWeight: 700 }}>WaveTrader</span>
          <span style={{ fontSize: "9px", color: C.dim, fontFamily: MONO, background: C.card, padding: "1px 5px", borderRadius: "3px" }}>V3</span>
        </div>

        {/* Instrument tabs */}
        <div style={{ display: "flex", gap: "2px", background: C.card, borderRadius: "7px", padding: "2px", border: `1px solid ${C.border}` }}>
          {instruments.map(inst => (
            <button key={inst.symbol} onClick={() => setSelectedInst(inst.symbol)} style={{
              background: selectedInst === inst.symbol ? C.cardAlt : "transparent",
              color: selectedInst === inst.symbol ? C.text : C.dim,
              border: selectedInst === inst.symbol ? `1px solid ${C.borderHi}` : "1px solid transparent",
              borderRadius: "5px", padding: "3px 10px", fontSize: "11px",
              fontFamily: SANS, fontWeight: 600, cursor: "pointer",
            }}>{inst.name}</button>
          ))}
        </div>

        <div style={{ flex: 1 }} />

        {/* Price */}
        <div style={{ display: "flex", alignItems: "baseline", gap: "8px", marginRight: "12px" }}>
          <span style={{ fontFamily: MONO, fontSize: "18px", fontWeight: 700, color: bull ? C.bull : C.bear }}>{lastCandle?.c.toFixed(2)}</span>
          <span style={{ fontFamily: MONO, fontSize: "12px", color: bull ? C.bull : C.bear }}>{bull ? "+" : ""}{priceChange.toFixed(2)}%</span>
        </div>

        {/* Live badge */}
        <div style={{
          display: "flex", alignItems: "center", gap: "5px", padding: "3px 10px",
          background: C.card, borderRadius: "6px", border: `1px solid ${C.border}`,
        }}>
          <div style={{ width: "6px", height: "6px", borderRadius: "50%", background: C.bull, boxShadow: `0 0 6px ${C.bull}`, animation: "pulse 2s infinite" }} />
          <span style={{ fontSize: "10px", color: C.muted, fontFamily: MONO }}>LIVE</span>
          <span style={{ fontSize: "10px", color: C.dim, fontFamily: MONO }}>{time.toLocaleTimeString("en-IN", { hour12: false })}</span>
        </div>
      </div>

      {/* ── Chart toolbar ── */}
      <div style={{
        display: "flex", alignItems: "center", padding: "6px 12px", gap: "8px",
        borderBottom: `1px solid ${C.border}`, flexWrap: "wrap",
      }}>
        {/* Timeframe tabs */}
        <div style={{ display: "flex", gap: "1px", background: C.card, borderRadius: "6px", padding: "2px", border: `1px solid ${C.border}` }}>
          {["1m", "5m", "15m", "1H", "4H", "1D"].map(tf => (
            <button key={tf} onClick={() => setSelectedTf(tf)} style={{
              background: selectedTf === tf ? C.borderHi : "transparent",
              color: selectedTf === tf ? C.text : C.dim,
              border: "none", borderRadius: "4px", padding: "3px 10px", fontSize: "10px",
              fontFamily: MONO, fontWeight: 700, cursor: "pointer",
            }}>{tf}</button>
          ))}
        </div>

        <div style={{ width: "1px", height: "18px", background: C.border }} />

        {/* Overlay toggles */}
        {overlayConfig.map(o => (
          <button key={o.key} onClick={() => toggle(o.key)} style={{
            display: "flex", alignItems: "center", gap: "4px",
            background: overlays[o.key] ? `${o.color}15` : "transparent",
            border: `1px solid ${overlays[o.key] ? `${o.color}40` : C.border}`,
            borderRadius: "5px", padding: "3px 10px", cursor: "pointer",
            fontSize: "10px", fontFamily: MONO, fontWeight: 600,
            color: overlays[o.key] ? o.color : C.dim,
          }}>
            <div style={{ width: "6px", height: "6px", borderRadius: "2px", background: overlays[o.key] ? o.color : C.dim }} />
            {o.label}
          </button>
        ))}

        <div style={{ flex: 1 }} />

        {/* Matrix toggle */}
        <button onClick={() => setMatrixOpen(!matrixOpen)} style={{
          display: "flex", alignItems: "center", gap: "5px",
          background: matrixOpen ? C.accentBg : "transparent",
          border: `1px solid ${matrixOpen ? `${C.accent}40` : C.border}`,
          borderRadius: "6px", padding: "4px 12px", cursor: "pointer",
          fontSize: "11px", fontWeight: 600, color: matrixOpen ? C.accent : C.dim,
          fontFamily: SANS,
        }}>
          <span style={{ fontSize: "13px" }}>◈</span> Wave Matrix
        </button>
      </div>

      {/* ── Main Content ── */}
      <div style={{ flex: 1, display: "flex", overflow: "hidden" }}>
        {/* Chart */}
        <div style={{ flex: 1, padding: "8px", display: "flex", flexDirection: "column", gap: "6px", minWidth: 0 }}>
          <TradingChart candles={candles} visibleRange={visibleRange} overlays={overlays} onRangeChange={setVisibleRange} />

          {/* Bottom: Quick bias strip */}
          <div style={{
            display: "flex", gap: "6px", alignItems: "stretch",
          }}>
            {/* HTF bias */}
            <div style={{
              flex: 1, padding: "8px 12px", borderRadius: "8px",
              background: htfDir === "BULL" ? C.bullFade : C.bearFade,
              border: `1px solid ${htfDir === "BULL" ? C.bullLine : C.bearLine}`,
              display: "flex", alignItems: "center", gap: "8px",
            }}>
              <DirArrow dir={htfDir} size={16} />
              <div>
                <div style={{ fontSize: "10px", color: C.muted }}>HTF bias (1D · 4H · 1H)</div>
                <div style={{ fontFamily: MONO, fontSize: "12px", fontWeight: 700, color: htfDir === "BULL" ? C.bull : C.bear }}>
                  {htfDir === "BULL" ? "BULLISH" : "BEARISH"} — {htfDir === "BULL" ? "look for LONGS" : "look for SHORTS"}
                </div>
              </div>
            </div>
            {/* LTF state */}
            <div style={{
              flex: 1, padding: "8px 12px", borderRadius: "8px",
              background: ltfDir === "BULL" ? C.bullFade : C.bearFade,
              border: `1px solid ${ltfDir === "BULL" ? C.bullLine : C.bearLine}`,
              display: "flex", alignItems: "center", gap: "8px",
            }}>
              <DirArrow dir={ltfDir} size={16} />
              <div>
                <div style={{ fontSize: "10px", color: C.muted }}>LTF state (15m · 5m · 1m)</div>
                <div style={{ fontFamily: MONO, fontSize: "12px", fontWeight: 700, color: ltfDir === "BULL" ? C.bull : C.bear }}>
                  {ltfDir === "BULL" ? "BULLISH" : "BEARISH"} — {aligned ? "✓ aligned with HTF" : "⚠ counter-trend"}
                </div>
              </div>
            </div>
            {/* Action */}
            <div style={{
              padding: "8px 14px", borderRadius: "8px",
              background: aligned ? C.bullFade : "rgba(245,158,11,0.06)",
              border: `1px solid ${aligned ? C.bullLine : "rgba(245,158,11,0.25)"}`,
              display: "flex", alignItems: "center", gap: "8px", minWidth: "180px",
            }}>
              <span style={{ fontSize: "18px" }}>{aligned ? "🎯" : "⏳"}</span>
              <div>
                <div style={{ fontSize: "10px", color: C.muted }}>Action</div>
                <div style={{ fontFamily: MONO, fontSize: "11px", fontWeight: 700, color: aligned ? C.bull : C.correction }}>
                  {aligned ? `${htfDir === "BULL" ? "BUY" : "SELL"} ON OB RETEST` : "WAIT FOR ALIGNMENT"}
                </div>
              </div>
            </div>
          </div>
        </div>

        {/* ── Wave Matrix Panel ── */}
        {matrixOpen && (
          <div style={{
            width: "300px", borderLeft: `1px solid ${C.border}`, overflowY: "auto",
            background: C.surface, flexShrink: 0, display: "flex", flexDirection: "column",
          }}>
            {/* Matrix header */}
            <div style={{ padding: "10px 14px", borderBottom: `1px solid ${C.border}` }}>
              <div style={{ fontSize: "12px", fontWeight: 700, color: C.wave, display: "flex", alignItems: "center", gap: "6px" }}>
                <span>◈</span> Elliott Wave Matrix
              </div>
              <div style={{ fontSize: "10px", color: C.dim, marginTop: "2px" }}>All timeframes · {selectedInst}</div>
            </div>

            {/* Wave rows */}
            <div style={{ flex: 1, overflowY: "auto" }}>
              {waveMatrix.map((w, idx) => {
                const isHTF = idx < 3;
                const expanded = expandedWave === idx;
                return (
                  <div key={w.tf} style={{ borderBottom: `1px solid ${C.border}` }}>
                    <div onClick={() => setExpandedWave(expanded ? null : idx)} style={{
                      padding: "10px 14px", cursor: "pointer",
                      background: expanded ? C.cardAlt : "transparent",
                      transition: "background .1s",
                    }}
                      onMouseEnter={e => { if (!expanded) e.currentTarget.style.background = C.card; }}
                      onMouseLeave={e => { if (!expanded) e.currentTarget.style.background = "transparent"; }}
                    >
                      {/* Row 1: TF + Wave + Dir */}
                      <div style={{ display: "flex", alignItems: "center", gap: "8px", marginBottom: "6px" }}>
                        <div style={{
                          width: "3px", height: "22px", borderRadius: "2px",
                          background: isHTF ? C.accent : C.dim, opacity: isHTF ? 1 : 0.5,
                        }} />
                        <div style={{ minWidth: "34px" }}>
                          <div style={{ fontFamily: MONO, fontSize: "14px", fontWeight: 700 }}>{w.tf}</div>
                          <div style={{ fontSize: "8px", color: C.dim, fontFamily: MONO }}>{w.degree}</div>
                        </div>
                        <WaveBadge wave={w.wave} of={w.of} small />
                        <span style={{
                          fontSize: "9px", fontFamily: MONO, fontWeight: 600,
                          color: w.phase === "IMPULSE" ? C.impulse : C.correction,
                        }}>{w.phase.slice(0, 3)}</span>
                        <div style={{ flex: 1 }} />
                        <div style={{
                          display: "flex", alignItems: "center", gap: "3px",
                          padding: "2px 8px", borderRadius: "4px",
                          background: w.dir === "BULL" ? C.bullFade : C.bearFade,
                          border: `1px solid ${w.dir === "BULL" ? C.bullLine : C.bearLine}`,
                        }}>
                          <DirArrow dir={w.dir} size={10} />
                          <span style={{ fontFamily: MONO, fontSize: "9px", fontWeight: 700, color: w.dir === "BULL" ? C.bull : C.bear }}>{w.dir}</span>
                        </div>
                        <ConfRing value={w.conf} size={26} />
                      </div>

                      {/* Row 2: Progress + note */}
                      <div style={{ paddingLeft: "12px" }}>
                        <div style={{ display: "flex", alignItems: "center", gap: "6px", marginBottom: "3px" }}>
                          <div style={{ flex: 1, height: "3px", background: C.border, borderRadius: "2px" }}>
                            <div style={{
                              height: "100%", borderRadius: "2px", width: `${w.pct}%`,
                              background: w.dir === "BULL" ? C.bull : C.bear, transition: "width .3s",
                            }} />
                          </div>
                          <span style={{ fontFamily: MONO, fontSize: "9px", color: C.dim, minWidth: "26px" }}>{w.pct}%</span>
                        </div>
                        <div style={{ fontSize: "10px", color: C.dim, lineHeight: "1.3" }}>{w.note}</div>
                      </div>
                    </div>

                    {/* Expanded detail */}
                    {expanded && (
                      <div style={{ padding: "0 14px 12px", background: C.cardAlt }}>
                        <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: "6px" }}>
                          <div style={{ padding: "8px 10px", borderRadius: "6px", background: C.surface, border: `1px solid ${C.border}` }}>
                            <div style={{ fontSize: "8px", color: C.dim, textTransform: "uppercase", letterSpacing: "0.5px", marginBottom: "4px" }}>Target</div>
                            <div style={{ fontFamily: MONO, fontSize: "13px", fontWeight: 700, color: w.dir === "BULL" ? C.bull : C.bear }}>{w.target}</div>
                            {w.fib && <div style={{ fontFamily: MONO, fontSize: "10px", color: C.muted, marginTop: "2px" }}>{w.fib}</div>}
                          </div>
                          <div style={{
                            padding: "8px 10px", borderRadius: "6px",
                            background: w.dir === "BULL" ? C.bullFade : C.bearFade,
                            border: `1px solid ${w.dir === "BULL" ? C.bullLine : C.bearLine}`,
                          }}>
                            <div style={{ fontSize: "8px", color: C.dim, textTransform: "uppercase", letterSpacing: "0.5px", marginBottom: "4px" }}>Action</div>
                            <div style={{ display: "flex", alignItems: "center", gap: "4px" }}>
                              <DirArrow dir={w.dir} size={12} />
                              <span style={{ fontFamily: MONO, fontSize: "11px", fontWeight: 700, color: w.dir === "BULL" ? C.bull : C.bear }}>
                                Stay {w.dir === "BULL" ? "LONG" : "SHORT"}
                              </span>
                            </div>
                            <div style={{ fontSize: "9px", color: C.muted, marginTop: "3px" }}>
                              {w.phase === "IMPULSE"
                                ? `Ride ${w.dir === "BULL" ? "up" : "down"} — add on dips`
                                : `Correction — ${w.dir === "BULL" ? "wait to go long" : "wait to short"}`}
                            </div>
                          </div>
                        </div>
                      </div>
                    )}
                  </div>
                );
              })}
            </div>

            {/* Matrix footer: Confluence summary */}
            <div style={{ padding: "10px 14px", borderTop: `1px solid ${C.border}`, background: C.card }}>
              <div style={{ fontSize: "9px", color: C.dim, textTransform: "uppercase", letterSpacing: "0.5px", marginBottom: "8px" }}>Confluence summary</div>
              <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr 1fr", gap: "6px" }}>
                {[
                  { label: "Context", desc: "1D wave 3", score: 32, max: 35, ok: true },
                  { label: "Levels", desc: "OB + 0.618", score: 28, max: 35, ok: true },
                  { label: "Trigger", desc: "Wait BOS", score: 0, max: 30, ok: false },
                ].map(l => (
                  <div key={l.label} style={{
                    padding: "6px 8px", borderRadius: "6px", background: C.surface,
                    border: `1px solid ${l.ok ? C.bullLine : C.border}`,
                  }}>
                    <div style={{ fontSize: "9px", color: l.ok ? C.bull : C.dim, fontWeight: 600, marginBottom: "2px" }}>
                      {l.ok ? "✓" : "◌"} {l.label}
                    </div>
                    <div style={{ fontSize: "10px", color: C.muted }}>{l.desc}</div>
                    <div style={{ fontFamily: MONO, fontSize: "9px", color: C.dim, marginTop: "2px" }}>{l.score}/{l.max}</div>
                  </div>
                ))}
              </div>
              <div style={{
                marginTop: "8px", padding: "8px", borderRadius: "6px",
                background: "rgba(0,220,130,0.06)", border: `1px solid ${C.bullLine}`,
                textAlign: "center",
              }}>
                <span style={{ fontFamily: MONO, fontSize: "20px", fontWeight: 700, color: C.bull }}>60</span>
                <span style={{ fontFamily: MONO, fontSize: "12px", color: C.muted }}>%</span>
                <div style={{ fontSize: "10px", color: C.muted, marginTop: "2px" }}>Waiting for LTF trigger confirmation</div>
              </div>
            </div>
          </div>
        )}
      </div>

      <style>{`
        * { box-sizing: border-box; margin: 0; }
        button { transition: all .1s; }
        ::-webkit-scrollbar { width: 4px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: ${C.border}; border-radius: 4px; }
        @keyframes pulse { 0%,100% { opacity:1; } 50% { opacity:0.3; } }
      `}</style>
    </div>
  );
}
