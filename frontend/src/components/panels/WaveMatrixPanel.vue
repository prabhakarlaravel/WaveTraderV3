<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import axios from 'axios'
import { useChartStore } from '../../stores/useChartStore'

const chartStore = useChartStore()
const mtfData = ref(null)
const loading = ref(false)
const expandedTf = ref(null) // which TF's wave chart is shown
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
  } catch { /* ignore */ }
  finally { loading.value = false }
}

onMounted(fetchMtfWaves)
watch(() => chartStore.activeSymbolId, fetchMtfWaves)

// Confluence data from overlays
const confluence = computed(() => chartStore.overlays?.confluence || null)
const action = computed(() => {
  if (!confluence.value) return null
  const dir = confluence.value.direction
  const pct = confluence.value.pct || 0
  const act = confluence.value.action || ''
  return { direction: dir, pct, action: act, isBuy: dir === 'BULL', isSell: dir === 'BEAR' }
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

  const prices = waveLabels.map(w => w.price)
  const minP = Math.min(...prices)
  const maxP = Math.max(...prices)
  const range = maxP - minP || 1

  const svgW = 340
  const svgH = 100
  const padX = 15
  const padY = 12
  const usableW = svgW - padX * 2
  const usableH = svgH - padY * 2

  const points = waveLabels.map((w, i) => ({
    x: padX + (i / (waveLabels.length - 1)) * usableW,
    y: padY + (1 - (w.price - minP) / range) * usableH,
    label: w.label,
    price: w.price,
    isCorrection: w.isCorrection,
    isCurrent: i === waveLabels.length - 1,
  }))

  // Split into impulse (1-5) and correction (A-B-C) segments
  const impulsePoints = points.filter(p => !p.isCorrection)
  const correctionPoints = points.filter(p => p.isCorrection)

  // Build polyline strings
  const impLine = impulsePoints.map(p => `${p.x},${p.y}`).join(' ')

  // Correction connects from last impulse point
  let corLine = ''
  if (correctionPoints.length > 0 && impulsePoints.length > 0) {
    const lastImp = impulsePoints[impulsePoints.length - 1]
    corLine = [lastImp, ...correctionPoints].map(p => `${p.x},${p.y}`).join(' ')
  }

  return { points, impLine, corLine, minP, maxP, svgW, svgH }
}

function formatPrice(p) {
  return p ? parseFloat(p).toLocaleString('en-US', { maximumFractionDigits: 0 }) : '--'
}
</script>

<template>
  <div class="wm">
    <div class="wm-title">◈ WAVE MATRIX</div>

    <!-- Loading -->
    <div v-if="loading && !mtfData" class="loading">Loading wave analysis...</div>

    <template v-if="mtfData">
      <!-- Action badge -->
      <div v-if="action" class="action-row">
        <span class="action-badge" :class="action.isSell ? 'badge-sell' : action.isBuy ? 'badge-buy' : 'badge-wait'">
          {{ action.isSell ? '↘ SELL' : action.isBuy ? '↗ BUY' : '→ WAIT' }}
        </span>
        <div class="action-info">
          <div class="action-reason">{{ action.action }}</div>
          <div class="action-conf"><b>{{ action.pct }}%</b> confluence · {{ mtfData.alignment }} TFs aligned</div>
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
              <line x1="0" y1="25" :x2="buildWaveSvg(getTfRow(tf).waveLabels).svgW" y2="25" class="grid-line"/>
              <line x1="0" y1="56" :x2="buildWaveSvg(getTfRow(tf).waveLabels).svgW" y2="56" class="grid-line"/>
              <line x1="0" y1="87" :x2="buildWaveSvg(getTfRow(tf).waveLabels).svgW" y2="87" class="grid-line"/>

              <!-- Price labels -->
              <text x="2" y="23" class="price-lbl">{{ formatPrice(buildWaveSvg(getTfRow(tf).waveLabels).maxP) }}</text>
              <text x="2" y="85" class="price-lbl">{{ formatPrice(buildWaveSvg(getTfRow(tf).waveLabels).minP) }}</text>

              <!-- Impulse line -->
              <polyline v-if="buildWaveSvg(getTfRow(tf).waveLabels).impLine"
                :points="buildWaveSvg(getTfRow(tf).waveLabels).impLine"
                class="wave-line imp-line"/>

              <!-- Correction line -->
              <polyline v-if="buildWaveSvg(getTfRow(tf).waveLabels).corLine"
                :points="buildWaveSvg(getTfRow(tf).waveLabels).corLine"
                class="wave-line cor-line"/>

              <!-- Wave label circles -->
              <template v-for="(pt, idx) in buildWaveSvg(getTfRow(tf).waveLabels).points" :key="idx">
                <circle :cx="pt.x" :cy="pt.y" r="9"
                  :class="pt.isCorrection ? 'label-bg-cor' : 'label-bg-imp'"/>
                <text :x="pt.x" :y="pt.y + 3.5" text-anchor="middle"
                  :class="pt.isCorrection ? 'label-txt-cor' : 'label-txt-imp'">
                  {{ pt.label }}
                </text>

                <!-- Current position marker (pulsing) -->
                <circle v-if="pt.isCurrent" :cx="pt.x" :cy="pt.y" r="12"
                  fill="none" stroke="#fff" stroke-width="1.5" opacity="0.4">
                  <animate attributeName="r" values="10;14;10" dur="1.5s" repeatCount="indefinite"/>
                  <animate attributeName="opacity" values="0.2;0.6;0.2" dur="1.5s" repeatCount="indefinite"/>
                </circle>
              </template>
            </svg>

            <div v-else class="wc-empty">Not enough wave data for {{ tf }}</div>
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
.wm-title { font-size: 12px; font-weight: 700; color: var(--dim); margin-bottom: 10px; padding: 0 4px; }
.loading { text-align: center; padding: 20px; font-size: 11px; color: var(--dim); }

/* Action */
.action-row { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; padding: 0 4px; }
.action-badge { font-size: 13px; font-weight: 900; padding: 5px 14px; border-radius: 6px; letter-spacing: 1px; }
.badge-sell { background: rgba(239,83,80,0.12); color: #ef5350; border: 1px solid rgba(239,83,80,0.25); }
.badge-buy { background: rgba(0,220,130,0.12); color: #34d399; border: 1px solid rgba(0,220,130,0.25); }
.badge-wait { background: rgba(100,100,100,0.1); color: #888; border: 1px solid rgba(100,100,100,0.2); }
.action-info { flex: 1; }
.action-reason { font-size: 10px; color: var(--muted); }
.action-conf { font-size: 9px; color: var(--dim); margin-top: 2px; }
.action-conf b { color: #a78bfa; }

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
.wave-chart-panel { margin: 2px 0 4px; padding: 8px; background: var(--surface); border-radius: 6px; border: 1px solid var(--border); animation: slideDown 0.2s ease; }
@keyframes slideDown { from { opacity: 0; max-height: 0; } to { opacity: 1; max-height: 200px; } }
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
.wave-line { fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.imp-line { stroke: #8b5cf6; }
.cor-line { stroke: #f59e0b; stroke-dasharray: 4 3; }
.label-bg-imp { fill: rgba(139,92,246,0.15); stroke: rgba(139,92,246,0.4); stroke-width: 1; }
.label-bg-cor { fill: rgba(245,158,11,0.15); stroke: rgba(245,158,11,0.4); stroke-width: 1; }
.label-txt-imp { font-size: 8px; font-weight: 800; fill: #a78bfa; font-family: 'DM Sans', sans-serif; }
.label-txt-cor { font-size: 8px; font-weight: 800; fill: #fbbf24; font-family: 'DM Sans', sans-serif; }

/* SL/TP strip */
.sltp-strip { display: flex; gap: 4px; margin: 0 4px; }
.sltp-card { flex: 1; padding: 6px; background: var(--card); border-radius: 5px; border: 1px solid var(--border); text-align: center; }
.sltp-label { font-size: 7px; font-weight: 700; text-transform: uppercase; }
.sltp-price { font-size: 11px; font-weight: 700; font-family: var(--mono); margin-top: 1px; }
</style>
