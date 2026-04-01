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
      params: { symbol_id: chartStore.activeSymbolId },
    })
    mtfData.value = data
    lastRefresh.value = new Date()
  } catch { /* ignore */ }
  finally { loading.value = false }
}

onMounted(fetchMtfWaves)
watch(() => chartStore.activeSymbolId, fetchMtfWaves)

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

// Refresh when overlays update (triggered by WS candle events or poll cycle)
// Use 3-second debounce to avoid hammering backend while still being responsive
let _mtfDebounce = null
watch(() => chartStore.overlays, () => {
  if (_mtfDebounce) clearTimeout(_mtfDebounce)
  _mtfDebounce = setTimeout(() => {
    if (!loading.value) fetchMtfWaves()
  }, 3000)
}, { deep: false })

// Confluence data from overlays
const confluence = computed(() => chartStore.overlays?.confluence || null)
const action = computed(() => {
  if (!confluence.value) return null
  const dir = confluence.value.direction
  const pct = confluence.value.pct || 0
  const act = confluence.value.action || ''
  return { direction: dir, pct, action: act, isBuy: dir === 'BULL', isSell: dir === 'BEAR' }
})

// Call/Put recommendation — uses TF-weighted HTF bias + wave position context + reversal detection
// Updates live via Reverb: overlays watcher → fetchMtfWaves() → mtfData + confluence refresh
const callPutRec = computed(() => {
  const signal  = confluence.value?.direction // 'BULL' | 'BEAR'
  const pct     = confluence.value?.pct ?? 0
  const isConflict = confluence.value?.conflict ?? false
  const waveContext = confluence.value?.waveContext ?? null
  const activeTf = getTfRow(chartStore.activeTimeframe)

  // TF-weighted HTF bias: 1D=3, 4H=2, 1H=1.5, 15M=1, 5M=0.5, 1M=0.5
  const tfWeights = { '1D': 3, '4H': 2, '1H': 1.5, '15M': 1, '5M': 0.5, '1M': 0.5 }
  let weightedBull = 0, weightedBear = 0
  for (const tf of ['1D', '4H', '1H', '15M', '5M', '1M']) {
    const row = getTfRow(tf)
    if (!row) continue
    const w = tfWeights[tf] || 1
    if (row.trend === 'bullish') weightedBull += w
    else if (row.trend === 'bearish') weightedBear += w
  }
  const trend = weightedBull > weightedBear ? 'BULL' : weightedBear > weightedBull ? 'BEAR' : 'NEUTRAL'

  // Check for reversal: wave C ending + strong counter-trend signal
  const htfWave = getTfRow('1D')?.wave || getTfRow('4H')?.wave || null
  const isReversalSetup = (htfWave === 'C' || waveContext === 'correction_ending') && pct >= 50
  const isImpulseEnding = (htfWave === '5' || waveContext === 'impulse_ending') && pct >= 50

  if (!signal || trend === 'NEUTRAL') {
    return {
      rec: 'WAIT', emoji: '⏸', conf: pct,
      color: '#818cf8', borderColor: 'rgba(99,102,241,0.3)', bgClass: 'cp-wait',
      trendLabel: '↔ SIDEWAYS', trendColor: '#818cf8',
      signalLabel: signal === 'BULL' ? '● BULLISH' : signal === 'BEAR' ? '● BEARISH' : '◈ MIXED',
      signalColor: '#818cf8',
      trendDetail: 'No clear direction',
      signalDetail: 'Wait for breakout',
      why: 'No directional edge — avoid forced trades.',
    }
  }

  // REVERSAL SETUP: After wave C, strong bullish signal → BUY CALL (reversal)
  if (isReversalSetup && signal === 'BULL') {
    return {
      rec: 'BUY CALL', emoji: '🔄', conf: Math.round(pct * 0.85),
      color: '#10b981', borderColor: 'rgba(16,185,129,0.3)', bgClass: 'cp-call',
      trendLabel: '🔄 REVERSAL', trendColor: '#10b981',
      signalLabel: '● BULLISH', signalColor: '#34d399',
      trendDetail: `Correction ending (Wave ${htfWave})`,
      signalDetail: 'New impulse forming · reversal setup',
      why: 'Wave C complete — new bullish impulse expected. BUY CALL.',
    }
  }
  if (isReversalSetup && signal === 'BEAR') {
    return {
      rec: 'BUY PUT', emoji: '🔄', conf: Math.round(pct * 0.85),
      color: '#ef4444', borderColor: 'rgba(239,68,68,0.3)', bgClass: 'cp-put',
      trendLabel: '🔄 REVERSAL', trendColor: '#ef4444',
      signalLabel: '● BEARISH', signalColor: '#f87171',
      trendDetail: `Correction ending (Wave ${htfWave})`,
      signalDetail: 'New bearish impulse forming',
      why: 'Wave C complete — new bearish impulse expected. BUY PUT.',
    }
  }

  // IMPULSE ENDING: Wave 5 → expect correction, caution
  if (isImpulseEnding) {
    return {
      rec: signal === 'BULL' ? 'CAUTION CALL' : 'BUY PUT', emoji: '⚠️', conf: Math.round(pct * 0.6),
      color: '#f59e0b', borderColor: 'rgba(245,158,11,0.3)', bgClass: 'cp-caution',
      trendLabel: '⚠ EXHAUSTION', trendColor: '#f59e0b',
      signalLabel: signal === 'BULL' ? '● BULLISH' : '● BEARISH', signalColor: signal === 'BULL' ? '#34d399' : '#f87171',
      trendDetail: `Wave 5 nearing end`,
      signalDetail: 'Correction expected soon — reduce position size',
      why: 'Impulse wave 5 ending — correction ABC imminent.',
    }
  }

  // UP trend + Bullish → strong CALL
  if (trend === 'BULL' && signal === 'BULL') {
    return {
      rec: 'BUY CALL', emoji: '📈', conf: pct,
      color: '#10b981', borderColor: 'rgba(16,185,129,0.3)', bgClass: 'cp-call',
      trendLabel: '▲ UP TREND', trendColor: '#10b981',
      signalLabel: '● BULLISH', signalColor: '#34d399',
      trendDetail: 'HH + HL structure',
      signalDetail: `${activeTf?.wave ? 'Wave ' + activeTf.wave : ''} impulse · BOS confirmed`.trim(),
      why: 'Trend & signal both bullish — stay on CALL side.',
    }
  }

  // UP trend + Bearish → pullback, hedge with PUT (but check if it's a strong reversal signal)
  if (trend === 'BULL' && signal === 'BEAR') {
    const isStrongBearish = pct >= 70
    return {
      rec: isStrongBearish ? 'BUY PUT' : 'HEDGE PUT',
      emoji: isStrongBearish ? '📉' : '⚡',
      conf: Math.round(pct * (isStrongBearish ? 0.8 : 0.65)),
      color: isStrongBearish ? '#ef4444' : '#f59e0b',
      borderColor: isStrongBearish ? 'rgba(239,68,68,0.3)' : 'rgba(245,158,11,0.3)',
      bgClass: isStrongBearish ? 'cp-put' : 'cp-caution',
      trendLabel: '▲ UP TREND', trendColor: '#10b981',
      signalLabel: '● BEARISH', signalColor: '#f87171',
      trendDetail: 'Broader trend bullish',
      signalDetail: `${activeTf?.wave ? 'Wave ' + activeTf.wave : ''} pullback in progress`.trim(),
      why: isStrongBearish
        ? 'Strong bearish signal in uptrend — short-term PUT.'
        : 'Mild pullback in uptrend — small hedge PUT only.',
    }
  }

  // DOWN trend + Bullish → check for genuine reversal vs dead cat bounce
  if (trend === 'BEAR' && signal === 'BULL') {
    const isStrongBullish = pct >= 70
    if (isStrongBullish) {
      return {
        rec: 'BUY CALL', emoji: '🔄', conf: Math.round(pct * 0.75),
        color: '#10b981', borderColor: 'rgba(16,185,129,0.3)', bgClass: 'cp-call',
        trendLabel: '▼ DOWN TREND', trendColor: '#ef4444',
        signalLabel: '● STRONG BULL', signalColor: '#34d399',
        trendDetail: 'Trend bearish but momentum shifting',
        signalDetail: 'Strong buy signals — possible trend reversal',
        why: 'Strong bullish momentum in downtrend — reversal CALL setup.',
      }
    }
    return {
      rec: 'BUY PUT', emoji: '📉', conf: Math.round(pct * 0.7),
      color: '#ef4444', borderColor: 'rgba(239,68,68,0.3)', bgClass: 'cp-put',
      trendLabel: '▼ DOWN TREND', trendColor: '#ef4444',
      signalLabel: '● BULLISH', signalColor: '#34d399',
      trendDetail: 'LL + LH structure',
      signalDetail: 'Bounce in downtrend — weak signal',
      why: 'Weak bullish signal in downtrend — sell the rally, PUT.',
    }
  }

  // DOWN trend + Bearish → strong PUT
  if (trend === 'BEAR' && signal === 'BEAR') {
    return {
      rec: 'BUY PUT', emoji: '📉', conf: pct,
      color: '#ef4444', borderColor: 'rgba(239,68,68,0.3)', bgClass: 'cp-put',
      trendLabel: '▼ DOWN TREND', trendColor: '#ef4444',
      signalLabel: '● BEARISH', signalColor: '#f87171',
      trendDetail: 'LL + LH structure',
      signalDetail: `${activeTf?.wave ? 'Wave ' + activeTf.wave : ''} declining · CHOCH detected`.trim(),
      why: 'Trend & signal both bearish — stay on PUT side.',
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
// Case 1 (default): show only the most recent set starting from wave 1 (max 8 labels)
// Case 2: if current wave is 1 (first wave plotting), show 2 full sets (up to 16)
// Case 3: if current wave is 2, show 1 set starting from wave 1
function buildWaveSvg(waveLabels) {
  if (!waveLabels || waveLabels.length < 3) return null

  // Find the start of the most recent wave cycle (last "1" label)
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
    // Case 2: at wave 1 of new cycle — show previous full set + current wave 1
    labels = waveLabels.slice(secondLastWave1Idx)
  } else if (lastWave1Idx >= 0) {
    // Case 1 & 3: show from most recent wave 1 onwards (single set)
    labels = waveLabels.slice(lastWave1Idx)
  } else {
    // Fallback: last 8 labels
    labels = waveLabels.slice(-8)
  }

  // Cap at 16 max just in case
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

  // Build a single connected polyline through all points (the wave path)
  const fullLine = points.map(p => `${p.x},${p.y}`).join(' ')

  // Also build separate impulse and correction segments for styling
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

// Cached SVG data per timeframe — avoids calling buildWaveSvg() 3× in template
const waveSvgCache = computed(() => {
  const cache = {}
  for (const tf of timeframes) {
    const row = getTfRow(tf)
    cache[tf] = row?.waveLabels?.length >= 3 ? buildWaveSvg(row.waveLabels) : null
  }
  return cache
})

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
      <!-- Call / Put Recommendation block -->
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
        <!-- Bottom row: trend cell + signal cell -->
        <div class="cp-cells">
          <div class="cp-cell">
            <div class="cp-cell-label">Trend</div>
            <div class="cp-cell-val" :style="{ color: callPutRec.trendColor }">{{ callPutRec.trendLabel }}</div>
            <div class="cp-cell-detail">{{ callPutRec.trendDetail }}</div>
          </div>
          <div class="cp-cell cp-cell-right">
            <div class="cp-cell-label">Signal</div>
            <div class="cp-cell-val" :style="{ color: callPutRec.signalColor }">{{ callPutRec.signalLabel }}</div>
            <div class="cp-cell-detail">{{ callPutRec.signalDetail }}</div>
          </div>
        </div>
      </div>

      <!-- Trend Progress -->
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

          <!-- Expanded wave chart for this TF (uses cached SVG data) -->
          <div v-if="expandedTf === tf && waveSvgCache[tf]" class="wave-chart-panel">
            <div class="wc-header">
              <span class="wc-tf">{{ tf }} — {{ getTfRow(tf).degree }}</span>
              <span class="wc-phase" :class="getTfRow(tf).phase === 'CORRECTION' ? 'phase-cor' : 'phase-imp'">
                Wave {{ getTfRow(tf).wave }} · {{ getTfRow(tf).phase }}
              </span>
            </div>

            <svg class="wave-svg" :viewBox="`0 0 ${waveSvgCache[tf].svgW} ${waveSvgCache[tf].svgH}`">

              <!-- Grid lines -->
              <line x1="20" y1="30" :x2="waveSvgCache[tf].svgW - 10" y2="30" class="grid-line"/>
              <line x1="20" y1="65" :x2="waveSvgCache[tf].svgW - 10" y2="65" class="grid-line"/>
              <line x1="20" y1="100" :x2="waveSvgCache[tf].svgW - 10" y2="100" class="grid-line"/>

              <!-- Price labels -->
              <text x="3" y="33" class="price-lbl">{{ formatPrice(waveSvgCache[tf].maxP) }}</text>
              <text x="3" y="103" class="price-lbl">{{ formatPrice(waveSvgCache[tf].minP) }}</text>

              <!-- Full connected wave path (thin gray) -->
              <polyline :points="waveSvgCache[tf].fullLine"
                fill="none" stroke="rgba(255,255,255,0.08)" stroke-width="1.5"/>

              <!-- Impulse segments (thick purple) -->
              <polyline v-if="waveSvgCache[tf].impLine"
                :points="waveSvgCache[tf].impLine"
                class="wave-line imp-line"/>

              <!-- Correction segments (dashed orange) -->
              <polyline v-if="waveSvgCache[tf].corLine"
                :points="waveSvgCache[tf].corLine"
                class="wave-line cor-line"/>

              <!-- Wave label circles + text -->
              <template v-for="(pt, idx) in waveSvgCache[tf].points" :key="idx">
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
                <!-- "You are here" label for current -->
                <text v-if="pt.isCurrent" :x="pt.x + 14" :y="pt.y - 6"
                  style="font-size:7px;fill:#888;font-weight:600">◄ NOW</text>
              </template>
            </svg>
          </div>
          <div v-else-if="expandedTf === tf" class="wave-chart-panel">
            <div class="wc-empty">Not enough wave data for {{ tf }}</div>
          </div>
        </template>
      </div>

      <!-- SL/TP from confluence -->
      <div v-if="confluence?.breakdown" class="sltp-strip">
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

.cp-cells { display: grid; grid-template-columns: 1fr 1fr; border-top: 1px solid rgba(255,255,255,0.04); }
.cp-cell  { padding: 5px 10px; }
.cp-cell-right { border-left: 1px solid rgba(255,255,255,0.04); }
.cp-cell-label  { font-size: 8px; color: #374151; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 2px; }
.cp-cell-val    { font-size: 11px; font-weight: 700; line-height: 1; }
.cp-cell-detail { font-size: 8px; color: #374151; margin-top: 2px; }

/* Trend gauge */
.trend-gauge { margin: 0 4px 10px; padding: 8px 10px; background: var(--card); border-radius: 6px; border: 1px solid var(--border); }
.gauge-top { display: flex; justify-content: space-between; font-size: 8px; color: var(--dim); font-weight: 700; text-transform: uppercase; margin-bottom: 4px; }
.gauge-track { height: 6px; background: var(--border); border-radius: 3px; position: relative; }
.gauge-fill { height: 100%; border-radius: 3px; background: linear-gradient(90deg, #34d399 0%, #fbbf24 50%, #ef5350 100%); }
.gauge-marker { position: absolute; top: -3px; width: 3px; height: 12px; background: #fff; border-radius: 2px; transform: translateX(-50%); box-shadow: 0 0 6px rgba(255,255,255,0.4); }
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
