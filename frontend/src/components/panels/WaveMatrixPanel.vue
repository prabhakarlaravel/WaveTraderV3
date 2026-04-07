<script setup>
import { ref, inject, computed, watch, onMounted, onUnmounted } from 'vue'
import axios from 'axios'
import { useChartStore } from '../../stores/useChartStore'

const chartStore = useChartStore()
const signalGlow = inject('signalGlow', ref(false))
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
      timeout: 15000, // 15s timeout — avoid hanging on slow engine fallback
    })
    mtfData.value = data
    lastRefresh.value = new Date()
    // Push confluence to chartStore so LiveChart bias strip can use it as fallback
    if (data.confluence) {
      chartStore.setMtfConfluence(data.confluence)
    }
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
 * Priority: overlays.confluence (refreshed every 30s via poll/WS) > mtfData.confluence (from mtf-waves endpoint).
 */
const confluence = computed(() => chartStore.overlays?.confluence || mtfData.value?.confluence || null)

/**
 * Call/Put recommendation — 100% from backend ConfluenceEngine.
 * Simplified for basic users: BUY CALL / BUY PUT / WAIT only.
 * No HEDGE, no technical jargon. System handles complexity internally.
 */
const callPutRec = computed(() => {
  const c = confluence.value
  if (!c) return null

  const callPut = c.callPut || 'WAIT'
  const adjustedPct = c.adjustedPct ?? c.pct ?? 0
  const userReason = c.userReason || ''
  const marketTrend = c.marketTrend || { label: 'Analyzing...', emoji: '⏳', direction: 'NEUTRAL' }

  // Time decay (Layer 6): reduce confidence if data is stale
  // Use confluence's own computed_at (most accurate), fallback to overlay-level timestamp
  let finalPct = adjustedPct
  const computedAt = c.computed_at || chartStore.overlays?.computed_at
  if (computedAt) {
    const ageMs = Date.now() - new Date(computedAt).getTime()
    // Only apply staleness during market hours (>5 min = stale)
    // During market-closed hours, data can be hours old but still valid
    if (ageMs > 300000) {
      // >5 minutes: show data but with reduced confidence, not STALE
      finalPct = Math.max(30, finalPct - 10)
    } else if (ageMs > 120000) {
      finalPct = Math.max(30, finalPct - 5)
    }
  }

  // Confidence level label
  const confLevel = finalPct >= 70 ? 'high' : finalPct >= 55 ? 'medium' : finalPct >= 40 ? 'low' : 'none'

  if (callPut === 'BUY CALL') {
    return {
      rec: 'BUY CALL', emoji: '📈', conf: finalPct,
      color: '#10b981', bgClass: 'cp-call',
      reason: userReason || 'Bullish setup detected. Market favors CALL side.',
      trend: marketTrend, confLevel,
    }
  }

  if (callPut === 'BUY PUT') {
    return {
      rec: 'BUY PUT', emoji: '📉', conf: finalPct,
      color: '#ef4444', bgClass: 'cp-put',
      reason: userReason || 'Bearish setup detected. Market favors PUT side.',
      trend: marketTrend, confLevel,
    }
  }

  // WAIT
  return {
    rec: 'WAIT', emoji: '⏸', conf: finalPct,
    color: '#818cf8', bgClass: 'cp-wait',
    reason: userReason || 'No clear trade setup. Stay on the sideline.',
    trend: marketTrend, confLevel,
  }
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
      <!-- Call / Put Recommendation — Simple for basic users -->
      <div v-if="callPutRec" class="cp-block" :class="[callPutRec.bgClass, signalGlow ? 'cp-glow' : '']">
        <!-- Big recommendation + confidence -->
        <div class="cp-top">
          <span class="cp-emoji">{{ callPutRec.emoji }}</span>
          <div class="cp-main">
            <div class="cp-rec" :style="{ color: callPutRec.color }">{{ callPutRec.rec }}</div>
          </div>
          <div class="cp-conf-wrap">
            <div class="cp-conf" :style="{ color: callPutRec.color }">{{ callPutRec.conf }}%</div>
            <div class="cp-conf-level" :class="'level-' + callPutRec.confLevel">
              {{ callPutRec.confLevel === 'high' ? 'Strong' : callPutRec.confLevel === 'medium' ? 'Moderate' : callPutRec.confLevel === 'low' ? 'Weak' : '' }}
            </div>
          </div>
        </div>
        <!-- Confidence bar -->
        <div class="cp-bar-track">
          <div class="cp-bar-fill" :style="{ width: callPutRec.conf + '%', background: callPutRec.color }"></div>
        </div>
        <!-- Market trend pill -->
        <div class="cp-trend-row">
          <span class="cp-trend-emoji">{{ callPutRec.trend.emoji }}</span>
          <span class="cp-trend-label">Market: {{ callPutRec.trend.label }}</span>
        </div>
        <!-- Plain English reason -->
        <div class="cp-reason">{{ callPutRec.reason }}</div>
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

      <!-- Bottom cards: Market Trend + Signal Strength + Health — simple labels -->
      <div v-if="confluence" class="sltp-strip">
        <div class="sltp-card">
          <div class="sltp-label" style="color: var(--dim)">Market</div>
          <div class="sltp-price" :style="{ color: mtfData.htfBias === 'BULL' ? '#34d399' : mtfData.htfBias === 'BEAR' ? '#ef5350' : '#888' }">
            {{ mtfData.htfBias === 'BULL' ? '↑ UP' : mtfData.htfBias === 'BEAR' ? '↓ DOWN' : '↔ FLAT' }}
          </div>
        </div>
        <div class="sltp-card">
          <div class="sltp-label" style="color: var(--dim)">Strength</div>
          <div class="sltp-price" :style="{ color: (confluence.adjustedPct || 0) >= 60 ? '#34d399' : (confluence.adjustedPct || 0) >= 45 ? '#fbbf24' : '#ef5350' }">
            {{ (confluence.adjustedPct || 0) >= 60 ? 'STRONG' : (confluence.adjustedPct || 0) >= 45 ? 'MODERATE' : 'WEAK' }}
          </div>
        </div>
        <div class="sltp-card">
          <div class="sltp-label" style="color: var(--dim)">Reliability</div>
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

/* Call/Put recommendation block — simplified */
.cp-block { border-radius: 10px; margin-bottom: 10px; overflow: hidden; border: 1px solid rgba(255,255,255,0.06); }
.cp-call   { background: linear-gradient(160deg, #071a0f 0%, #0a1d12 100%); border-color: rgba(16,185,129,0.25); }
.cp-put    { background: linear-gradient(160deg, #1a0707 0%, #1c0a0a 100%); border-color: rgba(239,68,68,0.25); }
.cp-wait   { background: linear-gradient(160deg, #0f0d1a 0%, #100f1c 100%); border-color: rgba(99,102,241,0.2); }

.cp-top { display: flex; align-items: center; gap: 10px; padding: 12px 14px 6px; }
.cp-emoji { font-size: 28px; line-height: 1; flex-shrink: 0; }
.cp-main { flex: 1; }
.cp-rec   { font-size: 22px; font-weight: 900; line-height: 1; letter-spacing: 0.5px; }
.cp-conf-wrap { text-align: right; flex-shrink: 0; }
.cp-conf  { font-size: 20px; font-weight: 800; line-height: 1; }
.cp-conf-level { font-size: 9px; font-weight: 700; text-transform: uppercase; margin-top: 2px; letter-spacing: 0.5px; }
.level-high   { color: #10b981; }
.level-medium { color: #f59e0b; }
.level-low    { color: #ef4444; }
.level-none   { color: #6b7280; }

/* Confidence bar */
.cp-bar-track { height: 4px; background: rgba(255,255,255,0.06); margin: 0 14px 8px; border-radius: 2px; }
.cp-bar-fill  { height: 100%; border-radius: 2px; transition: width 0.5s ease; }

/* Market trend row */
.cp-trend-row {
  display: flex; align-items: center; gap: 6px; padding: 4px 14px;
  font-size: 11px; color: #9ca3af;
}
.cp-trend-emoji { font-size: 14px; }
.cp-trend-label { font-weight: 600; }

/* Plain English reason */
.cp-reason {
  padding: 6px 14px 10px; font-size: 11px; color: #6b7280; line-height: 1.4;
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

/* Signal glow effect when signal changes */
.cp-glow {
  animation: cpGlowPulse 1.2s ease-in-out 5;
}
.cp-glow.cp-call {
  box-shadow: 0 0 16px rgba(16, 185, 129, 0.35), 0 0 40px rgba(16, 185, 129, 0.15);
  border-color: rgba(16, 185, 129, 0.6);
}
.cp-glow.cp-put {
  box-shadow: 0 0 16px rgba(239, 68, 68, 0.35), 0 0 40px rgba(239, 68, 68, 0.15);
  border-color: rgba(239, 68, 68, 0.6);
}
@keyframes cpGlowPulse {
  0%, 100% { filter: brightness(1); }
  50% { filter: brightness(1.5); }
}
</style>
