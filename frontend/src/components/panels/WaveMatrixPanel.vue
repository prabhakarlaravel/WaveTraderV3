<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import axios from 'axios'
import { useChartStore } from '../../stores/useChartStore'

const chartStore = useChartStore()
const mtfData = ref(null)
const loading = ref(false)
const expandedTf = ref(null)
const lastRefresh = ref(null)
const timeframes = ['1D', '4H', '1H', '15M', '5M', '1M']

// Auto-expand the active timeframe
watch(() => chartStore.activeTimeframe, (tf) => {
  expandedTf.value = tf
}, { immediate: true })

async function fetchMtfWaves() {
  if (!chartStore.activeSymbolId) return
  loading.value = true
  try {
    const { data } = await axios.get('/api/v1/chart/mtf-waves', {
      params: {
        symbol_id: chartStore.activeSymbolId,
        timeframe: chartStore.activeTimeframe,
      },
    })
    mtfData.value = data
    lastRefresh.value = new Date()
  } catch { /* ignore */ }
  finally { loading.value = false }
}

onMounted(fetchMtfWaves)
watch(() => chartStore.activeSymbolId, fetchMtfWaves)
watch(() => chartStore.activeTimeframe, fetchMtfWaves)

// Auto-refresh MTF wave data every 30s (aligned with candle polling)
let refreshInterval = null
onMounted(() => {
  refreshInterval = setInterval(() => {
    if (!loading.value) fetchMtfWaves()
  }, 30000)
})
onUnmounted(() => {
  if (refreshInterval) clearInterval(refreshInterval)
})

// Also refresh when overlays update (triggered by WS candle events)
watch(() => chartStore.overlays, () => {
  // Debounce: only refresh if last refresh was >10s ago
  if (lastRefresh.value && (Date.now() - lastRefresh.value.getTime()) < 10000) return
  fetchMtfWaves()
}, { deep: false })

/**
 * Confluence — SINGLE SOURCE OF TRUTH from backend.
 * v3.2: Read from mtfData (which includes confluence from backend)
 * Falls back to overlays.confluence for backward compat.
 */
const confluence = computed(() => mtfData.value?.confluence || chartStore.overlays?.confluence || null)

/**
 * Call/Put recommendation — 100% from backend ConfluenceEngine.
 * Frontend does NOT recalculate direction or trend independently.
 * This eliminates the 3-brain conflict from v3.1.
 */
const callPutRec = computed(() => {
  const c = confluence.value
  if (!c) return null

  const callPut = c.callPut || 'WAIT'
  const adjustedPct = c.adjustedPct ?? c.pct ?? 0
  const direction = c.direction || 'NEUTRAL'
  const htfBias = c.htfBias || mtfData.value?.htfBias || 'NEUTRAL'
  const conflict = c.conflict || false
  const gatesOk = c.gatesOk ?? true
  const activeTf = getTfRow(chartStore.activeTimeframe)

  // Time decay (Layer 6): reduce confidence if data is stale
  let finalPct = adjustedPct
  const computedAt = chartStore.overlays?.computed_at
  if (computedAt) {
    const ageMs = Date.now() - new Date(computedAt).getTime()
    if (ageMs > 120000) {
      // >120s stale: show STALE
      return {
        rec: 'STALE', emoji: '⏳', conf: 0,
        color: '#6b7280', borderColor: 'rgba(107,114,128,0.3)', bgClass: 'cp-wait',
        trendLabel: '⏳ STALE DATA', trendColor: '#6b7280',
        signalLabel: '◈ REFRESH', signalColor: '#6b7280',
        trendDetail: 'Data older than 2 minutes',
        signalDetail: 'Waiting for engine refresh',
        why: 'Signal data is stale — wait for fresh computation.',
        conflict: false, gatesOk: false,
      }
    }
    if (ageMs > 60000) finalPct = Math.max(30, finalPct - 15)      // >60s: -15%
    else if (ageMs > 30000) finalPct = Math.max(30, finalPct - 5)   // >30s: -5%
  }

  // Map callPut to display properties
  const isBull = callPut.includes('CALL')
  const isBear = callPut.includes('PUT')
  const isWait = callPut === 'WAIT' || callPut === 'STALE'
  const isHedge = callPut.includes('HEDGE')

  const trendDir = htfBias === 'BULL' ? '▲ UP TREND' : htfBias === 'BEAR' ? '▼ DOWN TREND' : '↔ SIDEWAYS'
  const trendCol = htfBias === 'BULL' ? '#10b981' : htfBias === 'BEAR' ? '#ef4444' : '#818cf8'
  const sigDir = direction === 'BULL' ? '● BULLISH' : direction === 'BEAR' ? '● BEARISH' : '◈ MIXED'
  const sigCol = direction === 'BULL' ? '#34d399' : direction === 'BEAR' ? '#f87171' : '#818cf8'

  // Build wave context detail
  const waveCtx = activeTf?.wave ? `Wave ${activeTf.wave}` : ''
  const phaseCtx = activeTf?.phase || ''

  if (isWait) {
    const waitReason = !gatesOk
      ? 'Minimum conditions not met — avoid forced trades.'
      : conflict
        ? 'HTF conflicts with signal — wait for alignment.'
        : 'No clear directional edge.'
    return {
      rec: 'WAIT', emoji: '⏸', conf: finalPct,
      color: '#818cf8', borderColor: 'rgba(99,102,241,0.3)', bgClass: 'cp-wait',
      trendLabel: trendDir, trendColor: trendCol,
      signalLabel: sigDir, signalColor: sigCol,
      trendDetail: htfBias === 'NEUTRAL' ? 'No clear HTF direction' : `HTF ${htfBias.toLowerCase()} bias`,
      signalDetail: !gatesOk ? 'Gates not met' : 'Waiting for confluence',
      why: waitReason,
      conflict, gatesOk,
    }
  }

  if (isBull && !isHedge) {
    return {
      rec: callPut, emoji: '📈', conf: finalPct,
      color: '#10b981', borderColor: 'rgba(16,185,129,0.3)', bgClass: 'cp-call',
      trendLabel: trendDir, trendColor: trendCol,
      signalLabel: sigDir, signalColor: sigCol,
      trendDetail: 'HH + HL structure',
      signalDetail: `${waveCtx} impulse · BOS confirmed`.trim(),
      why: 'Trend & signal aligned bullish — stay on CALL side.',
      conflict, gatesOk,
    }
  }

  if (isBear && !isHedge) {
    return {
      rec: callPut, emoji: '📉', conf: finalPct,
      color: '#ef4444', borderColor: 'rgba(239,68,68,0.3)', bgClass: 'cp-put',
      trendLabel: trendDir, trendColor: trendCol,
      signalLabel: sigDir, signalColor: sigCol,
      trendDetail: 'LL + LH structure',
      signalDetail: `${waveCtx} declining · ${phaseCtx}`.trim(),
      why: 'Trend & signal aligned bearish — stay on PUT side.',
      conflict, gatesOk,
    }
  }

  // Hedge scenarios (conflict between HTF and signal)
  if (isHedge && isBull) {
    return {
      rec: callPut, emoji: '⚡', conf: finalPct,
      color: '#f59e0b', borderColor: 'rgba(245,158,11,0.3)', bgClass: 'cp-caution',
      trendLabel: trendDir, trendColor: trendCol,
      signalLabel: sigDir, signalColor: sigCol,
      trendDetail: `HTF ${htfBias.toLowerCase()} but signal bullish`,
      signalDetail: 'Counter-trend bounce detected',
      why: 'HTF conflict — reduced conviction hedge only.',
      conflict: true, gatesOk,
    }
  }

  if (isHedge && isBear) {
    return {
      rec: callPut, emoji: '⚡', conf: finalPct,
      color: '#f59e0b', borderColor: 'rgba(245,158,11,0.3)', bgClass: 'cp-caution',
      trendLabel: trendDir, trendColor: trendCol,
      signalLabel: sigDir, signalColor: sigCol,
      trendDetail: `HTF ${htfBias.toLowerCase()} but signal bearish`,
      signalDetail: 'Counter-trend pullback detected',
      why: 'HTF conflict — reduced conviction hedge only.',
      conflict: true, gatesOk,
    }
  }

  return null
})

function toggleTf(tf) {
  expandedTf.value = expandedTf.value === tf ? null : tf
}

function getTfRow(tf) {
  if (!mtfData.value) return null
  return mtfData.value.timeframes?.[tf] || null
}

function trendColor(trend) {
  return trend === 'bullish' ? '#34d399' : trend === 'bearish' ? '#ef5350' : '#666'
}

function trendArrow(trend) {
  return trend === 'bullish' ? '↗' : trend === 'bearish' ? '↘' : '→'
}

function healthColor(score) {
  return score >= 75 ? '#34d399' : score >= 50 ? '#fbbf24' : '#ef5350'
}

// Build SVG wave chart points from waveLabels
function buildWaveSvg(waveLabels) {
  if (!waveLabels || waveLabels.length < 3) return null

  let lastWave1Idx = -1
  let secondLastWave1Idx = -1
  for (let i = waveLabels.length - 1; i >= 0; i--) {
    if (waveLabels[i].label === '1') {
      if (lastWave1Idx === -1) {
        lastWave1Idx = i
      } else {
        secondLastWave1Idx = i
        break
      }
    }
  }

  const currentWave = waveLabels[waveLabels.length - 1]?.label
  let labels

  if (currentWave === '1' && secondLastWave1Idx >= 0) {
    labels = waveLabels.slice(secondLastWave1Idx)
  } else if (lastWave1Idx >= 0) {
    labels = waveLabels.slice(lastWave1Idx)
  } else {
    labels = waveLabels.slice(-8)
  }

  if (labels.length > 16) labels = labels.slice(-16)

  const prices = labels.map(w => w.price)
  const minP = Math.min(...prices)
  const maxP = Math.max(...prices)
  const range = maxP - minP || 1

  const svgW = 340
  const svgH = 130
  const padX = 20
  const padY = 16
  const usableW = svgW - padX * 2
  const usableH = svgH - padY * 2

  const points = labels.map((w, i) => ({
    x: padX + (i / Math.max(labels.length - 1, 1)) * usableW,
    y: padY + (1 - (w.price - minP) / range) * usableH,
    label: w.label,
    price: w.price,
    isCorrection: w.isCorrection,
    isCurrent: i === labels.length - 1,
  }))

  const fullLine = points.map(p => `${p.x},${p.y}`).join(' ')
  const impulsePoints = points.filter(p => !p.isCorrection)
  const correctionPoints = points.filter(p => p.isCorrection)

  const impLine = impulsePoints.map(p => `${p.x},${p.y}`).join(' ')

  let corLine = ''
  if (correctionPoints.length > 0 && impulsePoints.length > 0) {
    const lastImp = impulsePoints[impulsePoints.length - 1]
    corLine = [lastImp, ...correctionPoints].map(p => `${p.x},${p.y}`).join(' ')
  }

  return { points, fullLine, impLine, corLine, minP, maxP, svgW, svgH }
}

// Live timer for "last updated X seconds ago"
const timeSinceRefresh = ref('')
let timerInterval = null
onMounted(() => {
  timerInterval = setInterval(() => {
    if (!lastRefresh.value) { timeSinceRefresh.value = ''; return }
    const secs = Math.floor((Date.now() - lastRefresh.value.getTime()) / 1000)
    timeSinceRefresh.value = secs < 5 ? 'just now' : secs < 60 ? `${secs}s ago` : `${Math.floor(secs/60)}m ago`
  }, 1000)
})
onUnmounted(() => { if (timerInterval) clearInterval(timerInterval) })

function formatPrice(p) {
  return p ? parseFloat(p).toLocaleString('en-US', { maximumFractionDigits: 0 }) : '--'
}
</script>

<template>
  <div class="wm">
    <div class="wm-title">
      ◈ WAVE MATRIX
      <span v-if="lastRefresh" class="wm-live">
        🔴 LIVE · {{ timeSinceRefresh }}
      </span>
    </div>

    <!-- Loading -->
    <div v-if="loading && !mtfData" class="loading">Loading wave analysis...</div>

    <template v-if="mtfData">
      <!-- Call / Put Recommendation block — 100% from backend -->
      <div v-if="callPutRec" class="cp-block" :class="callPutRec.bgClass" :style="{ borderColor: callPutRec.borderColor }">
        <!-- Top row: emoji + recommendation + confidence -->
        <div class="cp-top">
          <span class="cp-emoji">{{ callPutRec.emoji }}</span>
          <div class="cp-main">
            <div class="cp-label">Recommendation</div>
            <div class="cp-rec" :style="{ color: callPutRec.color }">{{ callPutRec.rec }}</div>
          </div>
          <div class="cp-conf-wrap">
            <div class="cp-conf-label">Confidence</div>
            <div class="cp-conf" :style="{ color: callPutRec.color }">{{ callPutRec.conf }}%</div>
          </div>
        </div>
        <!-- Conflict warning banner -->
        <div v-if="callPutRec.conflict" class="cp-conflict">
          ⚠ HTF conflict — {{ confluence?.conflictNote || 'trend and signal disagree' }}
        </div>
        <!-- Gates warning -->
        <div v-if="!callPutRec.gatesOk && callPutRec.rec !== 'STALE'" class="cp-gates-warn">
          ◈ Minimum conditions not met ({{ confluence?.gatesPassed || 0 }}/5 gates passed)
        </div>
        <!-- Bottom row: trend cell + signal cell -->
        <div class="cp-cells">
          <div class="cp-cell">
            <div class="cp-cell-label">HTF Trend</div>
            <div class="cp-cell-val" :style="{ color: callPutRec.trendColor }">{{ callPutRec.trendLabel }}</div>
            <div class="cp-cell-detail">{{ callPutRec.trendDetail }}</div>
          </div>
          <div class="cp-cell cp-cell-right">
            <div class="cp-cell-label">Signal</div>
            <div class="cp-cell-val" :style="{ color: callPutRec.signalColor }">{{ callPutRec.signalLabel }}</div>
            <div class="cp-cell-detail">{{ callPutRec.signalDetail }}</div>
          </div>
        </div>
        <!-- Reason -->
        <div class="cp-why">{{ callPutRec.why }}</div>
      </div>

      <!-- Trend Progress — now dynamic from backend -->
      <div class="trend-gauge">
        <div class="gauge-top">
          <span>Trend Progress</span>
          <span :style="{ color: mtfData.trendProgress.stage === 'end' ? '#ef5350' : mtfData.trendProgress.stage === 'start' ? '#34d399' : '#fbbf24' }">
            {{ mtfData.trendProgress.label }} ({{ mtfData.trendProgress.pct }}%)
          </span>
        </div>
        <div class="gauge-track">
          <div class="gauge-fill" :style="{ width: mtfData.trendProgress.pct + '%' }"></div>
          <div class="gauge-marker" :style="{ left: mtfData.trendProgress.pct + '%' }"></div>
        </div>
        <div class="gauge-phases">
          <span :class="{ 'phase-active': mtfData.trendProgress.stage === 'start' }">🟢 Start</span>
          <span :class="{ 'phase-active': mtfData.trendProgress.stage === 'middle' }">🟡 Middle</span>
          <span :class="{ 'phase-active': mtfData.trendProgress.stage === 'end' }">🔴 End</span>
        </div>
      </div>

      <!-- Wave rows per TF -->
      <div class="wave-rows">
        <template v-for="tf in timeframes" :key="tf">
          <div class="wr" :class="{ active: tf === chartStore.activeTimeframe, selected: tf === expandedTf }"
            @click="toggleTf(tf)">
            <span class="wr-tf" :class="{ active: tf === chartStore.activeTimeframe }">{{ tf }}</span>
            <span v-if="getTfRow(tf)?.wave" class="wr-wave"
              :class="getTfRow(tf)?.phase === 'CORRECTION' ? 'wr-cor' : 'wr-imp'">
              {{ getTfRow(tf).wave }}
            </span>
            <span v-else class="wr-wave wr-none">–</span>
            <div class="wr-bar">
              <div class="wr-fill" :class="getTfRow(tf)?.trend === 'bullish' ? 'bull' : 'bear'"
                :style="{ width: (getTfRow(tf)?.health || 50) + '%' }"></div>
            </div>
            <span class="wr-arrow" :style="{ color: trendColor(getTfRow(tf)?.trend) }">
              {{ trendArrow(getTfRow(tf)?.trend) }}
            </span>
            <span class="wr-phase">{{ getTfRow(tf)?.degree || tf }}</span>
            <span class="wr-expand" :class="{ open: expandedTf === tf }">▸</span>
          </div>

          <!-- Expanded wave chart for this TF -->
          <div v-if="expandedTf === tf && getTfRow(tf)?.waveLabels?.length >= 3" class="wave-chart-panel">
            <div class="wc-header">
              <span class="wc-tf">{{ tf }} — {{ getTfRow(tf).degree }}</span>
              <span class="wc-phase" :class="getTfRow(tf).phase === 'CORRECTION' ? 'phase-cor' : 'phase-imp'">
                Wave {{ getTfRow(tf).wave }} · {{ getTfRow(tf).phase }}
              </span>
            </div>

            <svg v-if="buildWaveSvg(getTfRow(tf).waveLabels)"
              class="wave-svg" :viewBox="`0 0 ${buildWaveSvg(getTfRow(tf).waveLabels).svgW} ${buildWaveSvg(getTfRow(tf).waveLabels).svgH}`">

              <!-- Grid lines -->
              <line x1="20" y1="30" :x2="buildWaveSvg(getTfRow(tf).waveLabels).svgW - 10" y2="30" class="grid-line"/>
              <line x1="20" y1="65" :x2="buildWaveSvg(getTfRow(tf).waveLabels).svgW - 10" y2="65" class="grid-line"/>
              <line x1="20" y1="100" :x2="buildWaveSvg(getTfRow(tf).waveLabels).svgW - 10" y2="100" class="grid-line"/>

              <!-- Price labels -->
              <text x="3" y="33" class="price-lbl">{{ formatPrice(buildWaveSvg(getTfRow(tf).waveLabels).maxP) }}</text>
              <text x="3" y="103" class="price-lbl">{{ formatPrice(buildWaveSvg(getTfRow(tf).waveLabels).minP) }}</text>

              <!-- Full connected wave path (thin gray) -->
              <polyline :points="buildWaveSvg(getTfRow(tf).waveLabels).fullLine"
                fill="none" stroke="rgba(255,255,255,0.08)" stroke-width="1.5"/>

              <!-- Impulse segments (thick purple) -->
              <polyline v-if="buildWaveSvg(getTfRow(tf).waveLabels).impLine"
                :points="buildWaveSvg(getTfRow(tf).waveLabels).impLine"
                class="wave-line imp-line"/>

              <!-- Correction segments (dashed orange) -->
              <polyline v-if="buildWaveSvg(getTfRow(tf).waveLabels).corLine"
                :points="buildWaveSvg(getTfRow(tf).waveLabels).corLine"
                class="wave-line cor-line"/>

              <!-- Wave label circles + text -->
              <template v-for="(pt, idx) in buildWaveSvg(getTfRow(tf).waveLabels).points" :key="idx">
                <circle :cx="pt.x" :cy="pt.y" r="10"
                  :class="pt.isCorrection ? 'label-bg-cor' : 'label-bg-imp'"/>
                <text :x="pt.x" :y="pt.y + 4" text-anchor="middle"
                  :class="pt.isCorrection ? 'label-txt-cor' : 'label-txt-imp'">
                  {{ pt.label }}
                </text>

                <!-- Current position marker (pulsing white ring) -->
                <circle v-if="pt.isCurrent" :cx="pt.x" :cy="pt.y" r="14"
                  fill="none" stroke="#fff" stroke-width="1.5" opacity="0.4">
                  <animate attributeName="r" values="12;16;12" dur="1.5s" repeatCount="indefinite"/>
                  <animate attributeName="opacity" values="0.2;0.6;0.2" dur="1.5s" repeatCount="indefinite"/>
                </circle>
                <text v-if="pt.isCurrent" :x="pt.x + 14" :y="pt.y - 6"
                  style="font-size:7px;fill:#888;font-weight:600">◄ NOW</text>
              </template>
            </svg>

            <div v-else class="wc-empty">Not enough wave data for {{ tf }}</div>
          </div>
        </template>
      </div>

      <!-- Bottom cards: HTF Bias + Alignment + Health — unified with backend data -->
      <div v-if="confluence" class="sltp-strip">
        <div class="sltp-card">
          <div class="sltp-label" style="color: var(--dim)">HTF Bias</div>
          <div class="sltp-price" :style="{ color: mtfData.htfBias === 'BULL' ? '#34d399' : mtfData.htfBias === 'BEAR' ? '#ef5350' : '#888' }">
            {{ mtfData.htfBias }}
          </div>
        </div>
        <div class="sltp-card">
          <div class="sltp-label" style="color: var(--dim)">Alignment</div>
          <div class="sltp-price" style="color: #a78bfa">{{ mtfData.alignment }}</div>
        </div>
        <div class="sltp-card">
          <div class="sltp-label" style="color: var(--dim)">Health</div>
          <div class="sltp-price" :style="{ color: healthColor(getTfRow(chartStore.activeTimeframe)?.health || 0) }">
            {{ getTfRow(chartStore.activeTimeframe)?.health || 0 }}%
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.wm { height: 100%; overflow-y: auto; }
.wm-title { font-size: 12px; font-weight: 700; color: var(--dim); margin-bottom: 10px; padding: 0 4px; display: flex; align-items: center; justify-content: space-between; }
.wm-live { font-size: 8px; color: #ef5350; font-weight: 600; animation: live-pulse 2s infinite; }
@keyframes live-pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.5; } }
.loading { text-align: center; padding: 20px; font-size: 11px; color: var(--dim); }

/* Call/Put recommendation block */
.cp-block { border-radius: 8px; margin-bottom: 10px; overflow: hidden; border: 1px solid; }
.cp-call   { background: linear-gradient(160deg, #071a0f 0%, #0a1d12 100%); }
.cp-put    { background: linear-gradient(160deg, #1a0707 0%, #1c0a0a 100%); }
.cp-caution{ background: linear-gradient(160deg, #191000 0%, #1a1100 100%); }
.cp-wait   { background: linear-gradient(160deg, #0f0d1a 0%, #100f1c 100%); }

.cp-top { display: flex; align-items: center; gap: 8px; padding: 9px 11px; }
.cp-emoji { font-size: 24px; line-height: 1; flex-shrink: 0; }
.cp-main { flex: 1; }
.cp-label { font-size: 8px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; color: #4b5563; margin-bottom: 1px; }
.cp-rec   { font-size: 20px; font-weight: 900; line-height: 1; letter-spacing: 0.5px; }
.cp-conf-wrap { text-align: right; flex-shrink: 0; }
.cp-conf-label { font-size: 8px; color: #4b5563; margin-bottom: 1px; }
.cp-conf  { font-size: 17px; font-weight: 800; }

/* Conflict + gates warning banners */
.cp-conflict {
  padding: 4px 11px; font-size: 9px; font-weight: 600; color: #f59e0b;
  background: rgba(245,158,11,0.08); border-top: 1px solid rgba(245,158,11,0.15);
}
.cp-gates-warn {
  padding: 4px 11px; font-size: 9px; font-weight: 600; color: #818cf8;
  background: rgba(99,102,241,0.06); border-top: 1px solid rgba(99,102,241,0.12);
}

.cp-cells { display: grid; grid-template-columns: 1fr 1fr; border-top: 1px solid rgba(255,255,255,0.04); }
.cp-cell  { padding: 5px 10px; }
.cp-cell-right { border-left: 1px solid rgba(255,255,255,0.04); }
.cp-cell-label  { font-size: 8px; color: #374151; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 2px; }
.cp-cell-val    { font-size: 11px; font-weight: 700; line-height: 1; }
.cp-cell-detail { font-size: 8px; color: #374151; margin-top: 2px; }

/* Why explanation */
.cp-why {
  padding: 4px 11px 6px; font-size: 8px; color: #4b5563;
  border-top: 1px solid rgba(255,255,255,0.03); font-style: italic;
}

/* Trend gauge */
.trend-gauge { margin: 0 4px 10px; padding: 8px 10px; background: var(--card); border-radius: 6px; border: 1px solid var(--border); }
.gauge-top { display: flex; justify-content: space-between; font-size: 8px; color: var(--dim); font-weight: 700; text-transform: uppercase; margin-bottom: 4px; }
.gauge-track { height: 6px; background: var(--border); border-radius: 3px; position: relative; }
.gauge-fill { height: 100%; border-radius: 3px; background: linear-gradient(90deg, #34d399 0%, #fbbf24 50%, #ef5350 100%); transition: width 0.5s ease; }
.gauge-marker { position: absolute; top: -3px; width: 3px; height: 12px; background: #fff; border-radius: 2px; transform: translateX(-50%); box-shadow: 0 0 6px rgba(255,255,255,0.4); transition: left 0.5s ease; }
.gauge-phases { display: flex; justify-content: space-between; margin-top: 3px; font-size: 7px; color: #444; }
.phase-active { font-weight: 800; color: #ccc !important; }

/* Wave rows */
.wave-rows { display: flex; flex-direction: column; gap: 2px; margin: 0 4px 10px; }
.wr { display: flex; align-items: center; gap: 0; height: 26px; border-radius: 4px; cursor: pointer; transition: all 0.2s; border: 1px solid transparent; }
.wr:hover { background: rgba(255,255,255,0.02); border-color: var(--border); }
.wr.active { background: rgba(139,92,246,0.06); border-color: rgba(139,92,246,0.2); }
.wr.selected { background: rgba(59,130,246,0.04); border-color: rgba(59,130,246,0.2); }
.wr-tf { width: 28px; font-family: var(--mono); font-size: 9px; font-weight: 700; color: var(--dim); text-align: center; flex-shrink: 0; }
.wr-tf.active { color: #8b5cf6; }
.wr-wave { width: 18px; height: 18px; border-radius: 50%; font-size: 8px; font-weight: 800; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin: 0 5px; }
.wr-imp { background: rgba(139,92,246,0.12); color: #a78bfa; border: 1px solid rgba(139,92,246,0.3); }
.wr-cor { background: rgba(245,158,11,0.12); color: #fbbf24; border: 1px solid rgba(245,158,11,0.3); }
.wr-none { background: rgba(100,100,100,0.08); color: var(--dim); border: 1px solid var(--border); }
.wr-bar { flex: 1; height: 3px; background: var(--border); border-radius: 2px; overflow: hidden; }
.wr-fill { height: 100%; border-radius: 2px; }
.wr-fill.bear { background: linear-gradient(90deg, #ef5350, rgba(239,83,80,0.3)); }
.wr-fill.bull { background: linear-gradient(90deg, rgba(0,220,130,0.3), #34d399); }
.wr-arrow { width: 18px; font-size: 12px; text-align: center; flex-shrink: 0; }
.wr-phase { width: 60px; font-size: 8px; color: var(--dim); text-align: right; padding-right: 2px; flex-shrink: 0; }
.wr-expand { width: 12px; font-size: 8px; color: #444; text-align: center; flex-shrink: 0; transition: transform 0.2s; }
.wr-expand.open { transform: rotate(90deg); color: #8b5cf6; }

/* Wave chart panel */
.wave-chart-panel { margin: 2px 0 4px; padding: 8px; background: var(--surface); border-radius: 6px; border: 1px solid var(--border); animation: slideDown 0.3s ease; min-height: 160px; }
@keyframes slideDown { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: translateY(0); } }
.wc-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px; }
.wc-tf { font-size: 9px; font-weight: 700; color: #8b5cf6; }
.wc-phase { font-size: 8px; font-weight: 600; padding: 1px 5px; border-radius: 3px; }
.phase-imp { background: rgba(139,92,246,0.1); color: #a78bfa; }
.phase-cor { background: rgba(245,158,11,0.1); color: #fbbf24; }
.wc-empty { text-align: center; padding: 12px; font-size: 9px; color: var(--dim); }

/* SVG */
.wave-svg { width: 100%; display: block; }
.grid-line { stroke: rgba(255,255,255,0.04); stroke-width: 1; }
.price-lbl { font-size: 7px; fill: #444; font-family: monospace; }
.wave-line { fill: none; stroke-width: 2.5; stroke-linecap: round; stroke-linejoin: round; }
.imp-line { stroke: #8b5cf6; }
.cor-line { stroke: #f59e0b; stroke-dasharray: 5 3; }
.label-bg-imp { fill: rgba(139,92,246,0.2); stroke: rgba(139,92,246,0.5); stroke-width: 1.5; }
.label-bg-cor { fill: rgba(245,158,11,0.2); stroke: rgba(245,158,11,0.5); stroke-width: 1.5; }
.label-txt-imp { font-size: 9px; font-weight: 800; fill: #c4b5fd; font-family: 'DM Sans', sans-serif; }
.label-txt-cor { font-size: 9px; font-weight: 800; fill: #fcd34d; font-family: 'DM Sans', sans-serif; }

/* SL/TP strip */
.sltp-strip { display: flex; gap: 4px; margin: 0 4px; }
.sltp-card { flex: 1; padding: 6px; background: var(--card); border-radius: 5px; border: 1px solid var(--border); text-align: center; }
.sltp-label { font-size: 7px; font-weight: 700; text-transform: uppercase; }
.sltp-price { font-size: 11px; font-weight: 700; font-family: var(--mono); margin-top: 1px; }
</style>
