<script setup>
import { ref, provide, onMounted, onUnmounted, watch, computed } from 'vue'
import { createChart, CandlestickSeries, HistogramSeries } from 'lightweight-charts'
import { useChartStore } from '../stores/useChartStore'
import { useRealtimeStore } from '../stores/useRealtimeStore'
import { useChartOverlays } from '../composables/useChartOverlays'
import SymbolSelector from '../components/shared/SymbolSelector.vue'
import SignalFeed from '../components/panels/SignalFeed.vue'
import TradePanel from '../components/panels/TradePanel.vue'
import WaveMatrixPanel from '../components/panels/WaveMatrixPanel.vue'
import SignalAlert from '../components/alerts/SignalAlert.vue'
import { useTradeStore } from '../stores/useTradeStore'
import { useSignalAlert } from '../composables/useSignalAlert'

const chartStore = useChartStore()
const realtime = useRealtimeStore()
const tradeStore = useTradeStore()
const rightPanel = ref('matrix') // 'matrix' | 'trade'

const chartContainer = ref(null)
const chartRef = ref(null)
const candleSeriesRef = ref(null)
let volumeSeries = null
let resizeObserver = null

// Sync timer — ticks every second to show "Xs ago"
const nowTick = ref(Date.now())
let syncTickInterval = null
const syncAgoText = computed(() => {
  if (!realtime.lastUpdate) return ''
  const sec = Math.floor((nowTick.value - new Date(realtime.lastUpdate).getTime()) / 1000)
  if (sec < 2) return 'just now'
  if (sec < 60) return `${sec}s ago`
  const min = Math.floor(sec / 60)
  return `${min}m ${sec % 60}s ago`
})

const timeframes = ['1M', '5M', '15M', '1H', '4H', '1D']
const showMatrix = ref(true)
const overlayToggles = ref({ waves: true, legs: true, ob: true, fvg: false, bos: false, vwap: false, signals: true, fibRetrace: true, fibExt: true })

const overlayConfig = [
  { key: 'waves', label: 'Waves', color: '#8b5cf6' },
  { key: 'legs', label: 'Legs', color: '#a78bfa' },
  { key: 'ob', label: 'OB', color: '#f59e0b' },
  { key: 'fvg', label: 'FVG', color: '#06b6d4' },
  { key: 'bos', label: 'BOS', color: '#10b981' },
  { key: 'vwap', label: 'VWAP', color: '#ec4899' },
  { key: 'fibRetrace', label: 'Fib', color: '#d4a054' },
  { key: 'fibExt', label: 'Ext', color: '#b08840' },
]

/**
 * Confluence — single source of truth from backend ConfluenceEngine v3.2.
 * Reads from chartStore.overlays.confluence (populated by overlay API / WS).
 * Falls back to mtfData.confluence (populated by WaveMatrixPanel's mtf-waves call).
 * This ensures bias strip always has data even when overlay cache is computing.
 */
const confluence = computed(() => {
  return chartStore.overlays?.confluence || chartStore.mtfConfluence || null
})

// Signal alert system — fires when BUY CALL / BUY PUT changes
const { alertState, signalGlow, dismissAlert } = useSignalAlert(confluence)
provide('signalGlow', signalGlow)

const lastPrice = ref({ price: 0, change: 0, bull: true })

function updatePrice() {
  const c = chartStore.candles
  if (!c.length) return
  const last = c[c.length - 1]
  const prev = c.length > 1 ? c[c.length - 2] : last
  const price = parseFloat(last.close)
  const prevPrice = parseFloat(prev.close)
  const change = prevPrice ? ((price - prevPrice) / prevPrice * 100) : 0
  lastPrice.value = { price, change, bull: change >= 0 }

  // Dynamic title bar: "NIFTY BANK 51,489.80 (+0.12%) — WaveTrader"
  const ticker = chartStore.activeSymbol?.ticker || ''
  const formatted = price.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
  const sign = change >= 0 ? '+' : ''
  document.title = `${ticker} ${formatted} (${sign}${change.toFixed(2)}%) — WaveTrader`
}

/**
 * HTF/LTF direction — derived from backend confluence (single source of truth).
 * htfBias comes from ConfluenceEngine which reads cached 1D+4H+1H trends.
 * Signal direction is the weighted engine consensus.
 */
const htfDir = computed(() => confluence.value?.htfBias || 'NEUTRAL')
const signalDir = computed(() => confluence.value?.direction || 'NEUTRAL')
const conflict = computed(() => confluence.value?.conflict || false)
const aligned = computed(() => !conflict.value && htfDir.value !== 'NEUTRAL')

function initChart() {
  if (!chartContainer.value) return
  chartRef.value = createChart(chartContainer.value, {
    width: chartContainer.value.clientWidth,
    height: chartContainer.value.clientHeight,
    layout: { background: { color: '#1a2540' }, textColor: '#8b9bbf' },
    grid: { vertLines: { color: '#243354' }, horzLines: { color: '#243354' } },
    crosshair: {
      mode: 0,
      vertLine: { color: '#5a70a5', width: 1, style: 2 },
      horzLine: { color: '#5a70a5', width: 1, style: 2 },
    },
    rightPriceScale: { borderColor: '#243354' },
    timeScale: { borderColor: '#243354', timeVisible: true, secondsVisible: false },
  })

  candleSeriesRef.value = chartRef.value.addSeries(CandlestickSeries, {
    upColor: '#00dc82', downColor: '#ff3b5c',
    borderDownColor: '#ff3b5c', borderUpColor: '#00dc82',
    wickDownColor: '#ff3b5c', wickUpColor: '#00dc82',
  })

  volumeSeries = chartRef.value.addSeries(HistogramSeries, {
    priceFormat: { type: 'volume' }, priceScaleId: 'volume',
  })
  chartRef.value.priceScale('volume').applyOptions({ scaleMargins: { top: 0.8, bottom: 0 } })

  resizeObserver = new ResizeObserver(() => {
    if (chartRef.value && chartContainer.value) {
      chartRef.value.resize(chartContainer.value.clientWidth, chartContainer.value.clientHeight)
    }
  })
  resizeObserver.observe(chartContainer.value)
}

const { renderAll, cleanup, attachChartListeners, setContainer } = useChartOverlays(chartRef, candleSeriesRef, chartStore, overlayToggles)

// Debounced render to consolidate multiple rapid triggers into one frame
let renderRAF = null
function debouncedRender() {
  if (renderRAF) return
  renderRAF = requestAnimationFrame(() => {
    renderRAF = null
    renderAll()
  })
}

let lastSetDataLength = 0
let lastRenderedSymbolId = null
let lastRenderedTimeframe = null

function updateChartData() {
  if (!candleSeriesRef.value) return
  const candles = chartStore.formattedCandles
  const volume = chartStore.formattedVolume
  if (candles.length === 0) return

  // Detect symbol or timeframe change — always do full setData
  const symbolChanged = chartStore.activeSymbolId !== lastRenderedSymbolId
  const tfChanged = chartStore.activeTimeframe !== lastRenderedTimeframe

  // Full setData on: initial load, symbol switch, TF switch, or significant candle count change
  // Use update() only for live 30s ticks — smooth, no flicker
  if (symbolChanged || tfChanged || Math.abs(candles.length - lastSetDataLength) > 2 || lastSetDataLength === 0) {
    candleSeriesRef.value.setData(candles)
    volumeSeries.setData(volume)
    chartRef.value.timeScale().scrollToRealTime()
    lastSetDataLength = candles.length
    lastRenderedSymbolId = chartStore.activeSymbolId
    lastRenderedTimeframe = chartStore.activeTimeframe
  } else {
    // Live update: update the current forming candle + append new ones
    const last = candles[candles.length - 1]
    const lastVol = volume[volume.length - 1]
    try {
      candleSeriesRef.value.update(last)
      volumeSeries.update(lastVol)
    } catch {
      // Fallback to full setData if update() fails (e.g. time order issue)
      candleSeriesRef.value.setData(candles)
      volumeSeries.setData(volume)
    }
    lastSetDataLength = candles.length
  }
  updatePrice()
  // confluence is now a computed — auto-updates from chartStore
}

// Pause sync timer when tab is hidden to save CPU
let isTabVisible = true
function onVisibilityChange() {
  isTabVisible = document.visibilityState === 'visible'
  if (isTabVisible) {
    nowTick.value = Date.now()
    debouncedRender() // re-render overlays when tab becomes visible
  }
}

onMounted(async () => {
  // 1. Data fetch first — must succeed even if chart init fails
  try {
    await chartStore.fetchSymbols()
    await chartStore.fetchCandles()
    if (typeof tradeStore.loadTrades === 'function') tradeStore.loadTrades()
  } catch (err) {
    console.error('[LiveChart] Data fetch error:', err)
  }

  // 2. Chart init — may fail on first mount if container not ready
  try {
    initChart()
    setContainer(chartContainer.value)
    attachChartListeners()
    updateChartData()
    renderAll()
  } catch (err) {
    console.error('[LiveChart] Chart init error:', err)
    // Retry once after next frame — container may not have been measured yet
    requestAnimationFrame(() => {
      try {
        if (!chartRef.value) {
          initChart()
          setContainer(chartContainer.value)
          attachChartListeners()
        }
        updateChartData()
        renderAll()
      } catch (retryErr) {
        console.error('[LiveChart] Chart retry failed:', retryErr)
      }
    })
  }

  // 3. Timers — always start regardless of chart state
  syncTickInterval = setInterval(() => { if (isTabVisible) nowTick.value = Date.now() }, 1000)
  document.addEventListener('visibilitychange', onVisibilityChange)
})

onUnmounted(() => {
  cleanup()
  resizeObserver?.disconnect()
  chartRef.value?.remove()
  clearInterval(syncTickInterval)
  document.removeEventListener('visibilitychange', onVisibilityChange)
  if (renderRAF) cancelAnimationFrame(renderRAF)
})

// Consolidated watchers — all use debouncedRender to avoid multiple renderAll() per frame
watch(() => chartStore.formattedCandles, () => { updateChartData(); debouncedRender() })
watch(overlayToggles, () => debouncedRender(), { deep: true })
</script>

<template>
  <div class="dashboard">
    <!-- Signal change alert overlay -->
    <SignalAlert :alert-state="alertState" @dismiss="dismissAlert" />

    <!-- Chart toolbar -->
    <div class="chart-toolbar">
      <!-- Symbol selector -->
      <SymbolSelector
        :symbols="chartStore.symbols"
        :model-value="chartStore.activeSymbolId"
        @update:model-value="chartStore.setSymbol($event)"
        compact
      />

      <div class="toolbar-sep"></div>

      <!-- Timeframe tabs -->
      <div class="tf-group">
        <button v-for="tf in timeframes" :key="tf" @click="chartStore.setTimeframe(tf)"
          :class="['tf-btn', { active: chartStore.activeTimeframe === tf }]">{{ tf }}</button>
      </div>

      <div class="toolbar-sep"></div>

      <!-- Overlay toggles -->
      <button v-for="o in overlayConfig" :key="o.key" @click="overlayToggles[o.key] = !overlayToggles[o.key]"
        :class="['overlay-btn', { active: overlayToggles[o.key] }]"
        :style="overlayToggles[o.key] ? `--oc: ${o.color}; background: ${o.color}15; border-color: ${o.color}40; color: ${o.color}` : ''">
        <span class="overlay-dot" :style="{ background: overlayToggles[o.key] ? o.color : 'var(--dim)' }"></span>
        {{ o.label }}
      </button>

      <div class="toolbar-spacer"></div>

      <!-- Price display -->
      <div class="price-display">
        <span class="price-value" :style="{ color: lastPrice.bull ? 'var(--bull)' : 'var(--bear)' }">
          {{ lastPrice.price.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}
        </span>
        <span class="price-change" :style="{ color: lastPrice.bull ? 'var(--bull)' : 'var(--bear)' }">
          {{ lastPrice.bull ? '+' : '' }}{{ lastPrice.change.toFixed(2) }}%
        </span>
      </div>

      <div class="toolbar-sep"></div>

      <!-- Market status + Live badge -->
      <div v-if="!realtime.marketOpen && realtime.marketStatus" class="market-closed-badge" :title="realtime.marketMessage">
        <div class="closed-dot"></div>
        <span class="closed-text">{{ realtime.marketType === 'nse' ? 'NSE CLOSED' : realtime.marketType === 'forex' ? 'FOREX CLOSED' : 'CLOSED' }}</span>
        <span class="closed-sub">{{ realtime.marketStatus?.session || '' }}</span>
      </div>
      <div class="live-badge" :title="realtime.isStale ? 'Data may be stale (>90s since last update)' : realtime.marketOpen ? 'Polling every 30s' : 'Market closed — showing last available data'">
        <div class="live-dot" :style="!realtime.marketOpen
          ? 'background: var(--dim); box-shadow: none; animation: none'
          : realtime.isStale
            ? 'background: var(--bear); box-shadow: 0 0 6px var(--bear)'
            : realtime.connected
              ? ''
              : 'background: var(--ob); box-shadow: 0 0 6px var(--ob)'
        "></div>
        <span class="live-text" :style="!realtime.marketOpen ? 'color: var(--dim)' : realtime.isStale ? 'color: var(--bear)' : ''">
          {{ !realtime.marketOpen ? 'DB' : realtime.isStale ? 'STALE' : 'LIVE' }}
        </span>
        <span v-if="realtime.lastUpdate" class="sync-timer" :style="realtime.isStale ? 'color: var(--bear)' : ''">
          {{ syncAgoText }}
        </span>
        <span class="live-text" style="color: var(--dim); cursor: pointer" @click="realtime.forceRefresh()" title="Force refresh now">&#8635;</span>
      </div>

      <!-- Wave Matrix toggle -->
      <button @click="showMatrix = !showMatrix" :class="['matrix-toggle', { active: showMatrix }]">
        <span>&#9672;</span> Wave Matrix
      </button>
    </div>

    <!-- Main content: Chart + Matrix panel -->
    <div class="main-area">
      <!-- Chart column -->
      <div class="chart-col">
        <div ref="chartContainer" class="chart-container">
          <!-- Loading overlay — shown during symbol/TF switch -->
          <div v-if="chartStore.loading" class="chart-loading-overlay">
            <div class="chart-spinner"></div>
            <div class="chart-loading-text">Loading chart...</div>
          </div>
          <!-- Overlays computing indicator — subtle, non-blocking -->
          <div v-else-if="chartStore.overlaysLoading" class="chart-computing-badge">
            <div class="chart-spinner-sm"></div>
            <span>Loading waves & signals...</span>
          </div>
        </div>

        <!-- Bias strip below chart — simplified for basic users -->
        <div class="bias-strip">
          <!-- Market Trend card -->
          <div :class="['bias-card', htfDir === 'BULL' ? 'bull' : htfDir === 'BEAR' ? 'bear' : 'warn']">
            <span class="bias-emoji">{{ confluence?.marketTrend?.emoji || '⏳' }}</span>
            <div>
              <div class="bias-label">Market Trend</div>
              <div class="bias-value" :style="{ color: htfDir === 'BULL' ? 'var(--bull)' : htfDir === 'BEAR' ? 'var(--bear)' : 'var(--dim)' }">
                {{ confluence?.marketTrend?.label || (htfDir === 'BULL' ? 'Uptrend' : htfDir === 'BEAR' ? 'Downtrend' : 'Sideways') }}
              </div>
            </div>
          </div>
          <!-- Recommendation card -->
          <div :class="['bias-card', 'bias-action',
            confluence?.callPut === 'BUY CALL' ? 'bull' :
            confluence?.callPut === 'BUY PUT' ? 'bear' : 'warn',
            signalGlow ? 'bias-glow' : '']"
            style="min-width: 240px">
            <span class="bias-emoji" style="font-size: 22px">
              {{ confluence?.callPut === 'BUY CALL' ? '📈' : confluence?.callPut === 'BUY PUT' ? '📉' : '⏸' }}
            </span>
            <div style="flex: 1">
              <div class="bias-label">Recommendation</div>
              <div class="bias-value" :style="{
                color: confluence?.callPut === 'BUY CALL' ? 'var(--bull)' :
                       confluence?.callPut === 'BUY PUT' ? 'var(--bear)' : 'var(--ob)',
                fontSize: '14px'
              }">
                {{ confluence?.callPut || 'ANALYZING...' }}
              </div>
            </div>
            <div class="bias-conf" :style="{
              color: confluence?.callPut === 'BUY CALL' ? 'var(--bull)' :
                     confluence?.callPut === 'BUY PUT' ? 'var(--bear)' : 'var(--dim)'
            }">
              {{ confluence?.adjustedPct || 0 }}%
            </div>
          </div>
        </div>
      </div>

      <!-- Right Panel: Trade / Matrix tabs -->
      <div v-if="showMatrix" class="matrix-panel">
        <!-- Panel tabs -->
        <div class="flex" style="border-bottom: 1px solid var(--border)">
          <button @click="rightPanel = 'matrix'" class="flex-1 px-3 py-2 text-[10px] font-semibold uppercase tracking-wider transition"
            :style="rightPanel === 'matrix' ? 'color: var(--wave); border-bottom: 2px solid var(--wave)' : 'color: var(--dim)'">
            Wave Matrix
          </button>
          <button @click="rightPanel = 'trade'" class="flex-1 px-3 py-2 text-[10px] font-semibold uppercase tracking-wider transition"
            :style="rightPanel === 'trade' ? 'color: var(--bull); border-bottom: 2px solid var(--bull)' : 'color: var(--dim)'">
            Trade
          </button>
        </div>

        <!-- Matrix panel content -->
        <div v-if="rightPanel === 'matrix'" class="flex-1 overflow-y-auto">
          <WaveMatrixPanel />
        </div><!-- close matrix content -->

        <!-- Trade panel -->
        <div v-if="rightPanel === 'trade'" class="flex-1 overflow-hidden flex flex-col">
          <TradePanel />
        </div>
      </div><!-- close matrix-panel -->
    </div><!-- close main-area -->
  </div><!-- close dashboard -->
</template>

<style scoped>
.dashboard { display: flex; flex-direction: column; height: calc(100vh - 44px); overflow: hidden; }

/* ── Chart toolbar ── */
.chart-toolbar {
  display: flex; align-items: center; padding: 6px 12px; gap: 8px;
  border-bottom: 1px solid var(--border); flex-wrap: wrap;
}
.tf-group {
  display: flex; gap: 1px; background: var(--card); border-radius: 6px;
  padding: 2px; border: 1px solid var(--border);
}
.tf-btn {
  background: transparent; color: var(--dim); border: none; border-radius: 4px;
  padding: 3px 10px; font-size: 10px; font-family: var(--mono); font-weight: 700; cursor: pointer;
}
.tf-btn.active { background: var(--border-hi); color: var(--text); }
.toolbar-sep { width: 1px; height: 18px; background: var(--border); }
.toolbar-spacer { flex: 1; }

.overlay-btn {
  display: flex; align-items: center; gap: 4px;
  background: transparent; border: 1px solid var(--border); border-radius: 5px;
  padding: 3px 10px; cursor: pointer; font-size: 10px; font-family: var(--mono);
  font-weight: 600; color: var(--dim);
}
.overlay-dot { width: 6px; height: 6px; border-radius: 2px; }

.price-display { display: flex; align-items: baseline; gap: 8px; margin: 0 4px; }
.price-value { font-family: var(--mono); font-size: 18px; font-weight: 700; }
.price-change { font-family: var(--mono); font-size: 12px; }

.live-badge {
  display: flex; align-items: center; gap: 5px; padding: 3px 10px;
  background: var(--card); border-radius: 6px; border: 1px solid var(--border);
}
.live-dot {
  width: 6px; height: 6px; border-radius: 50%; background: var(--bull);
  box-shadow: 0 0 6px var(--bull); animation: pulse 2s infinite;
}
.live-text { font-size: 10px; color: var(--muted); font-family: var(--mono); }
.sync-timer { font-size: 10px; color: var(--dim); font-family: var(--mono); min-width: 55px; }

.market-closed-badge {
  display: flex; align-items: center; gap: 5px; padding: 3px 10px;
  background: rgba(245,158,11,0.08); border-radius: 6px; border: 1px solid rgba(245,158,11,0.25);
}
.closed-dot {
  width: 6px; height: 6px; border-radius: 50%; background: var(--ob);
}
.closed-text { font-size: 10px; color: var(--ob); font-family: var(--mono); font-weight: 700; }
.closed-sub { font-size: 9px; color: var(--dim); font-family: var(--mono); }

.matrix-toggle {
  display: flex; align-items: center; gap: 5px;
  background: transparent; border: 1px solid var(--border); border-radius: 6px;
  padding: 4px 12px; cursor: pointer; font-size: 11px; font-weight: 600;
  color: var(--dim); font-family: var(--sans);
}
.matrix-toggle.active { background: var(--accent-bg); border-color: rgba(59,130,246,0.4); color: var(--accent); }

/* ── Main area ── */
.main-area { flex: 1; display: flex; overflow: hidden; }
.chart-col { flex: 1; display: flex; flex-direction: column; gap: 6px; padding: 8px; min-width: 0; }
.chart-container { flex: 1; background: var(--bg); border-radius: 10px; border: 1px solid var(--border); overflow: hidden; position: relative; }

/* ── Chart loading overlay ── */
.chart-loading-overlay {
  position: absolute; inset: 0; z-index: 20;
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  background: rgba(10, 15, 30, 0.85); backdrop-filter: blur(4px);
}
.chart-loading-text {
  margin-top: 12px; font-size: 11px; color: #6a7a9a;
  text-transform: uppercase; letter-spacing: 1px; font-weight: 600;
}
.chart-spinner {
  width: 32px; height: 32px; border: 3px solid #1a2844;
  border-top-color: #3b82f6; border-radius: 50%;
  animation: chart-spin 0.8s linear infinite;
}
.chart-computing-badge {
  position: absolute; top: 10px; left: 50%; transform: translateX(-50%); z-index: 15;
  display: flex; align-items: center; gap: 8px;
  padding: 5px 14px; border-radius: 20px;
  background: rgba(15, 23, 42, 0.9); border: 1px solid #243354;
  font-size: 10px; color: #6a7a9a; letter-spacing: 0.3px;
  animation: chart-fade-in 0.3s ease;
}
.chart-spinner-sm {
  width: 14px; height: 14px; border: 2px solid #1a2844;
  border-top-color: #3b82f6; border-radius: 50%;
  animation: chart-spin 0.8s linear infinite;
}
@keyframes chart-spin { to { transform: rotate(360deg); } }
@keyframes chart-fade-in { from { opacity: 0; transform: translateX(-50%) translateY(-6px); } to { opacity: 1; transform: translateX(-50%) translateY(0); } }

/* ── Bias strip ── */
.bias-strip { display: flex; gap: 6px; }
.bias-card {
  flex: 1; padding: 10px 14px; border-radius: 10px;
  display: flex; align-items: center; gap: 10px;
}
.bias-action { flex: 1.5; }
.bias-card.bull { background: var(--bull-fade); border: 1px solid var(--bull-line); }
.bias-card.bear { background: var(--bear-fade); border: 1px solid var(--bear-line); }
.bias-card.warn { background: rgba(245,158,11,0.06); border: 1px solid rgba(245,158,11,0.25); }
.bias-emoji { font-size: 18px; flex-shrink: 0; }
.bias-label { font-size: 10px; color: var(--muted); margin-bottom: 1px; }
.bias-value { font-family: var(--mono); font-size: 13px; font-weight: 700; }
.bias-conf { font-family: var(--mono); font-size: 18px; font-weight: 800; flex-shrink: 0; }

/* Signal alert glow on bias card */
.bias-glow {
  animation: biasGlowPulse 1.2s ease-in-out 5;
}
.bias-glow.bull {
  box-shadow: 0 0 12px rgba(16, 185, 129, 0.3), 0 0 30px rgba(16, 185, 129, 0.1);
  border-color: rgba(16, 185, 129, 0.6) !important;
}
.bias-glow.bear {
  box-shadow: 0 0 12px rgba(239, 68, 68, 0.3), 0 0 30px rgba(239, 68, 68, 0.1);
  border-color: rgba(239, 68, 68, 0.6) !important;
}
@keyframes biasGlowPulse {
  0%, 100% { filter: brightness(1); }
  50% { filter: brightness(1.4); }
}

/* ── Wave Matrix Panel ── */
.matrix-panel {
  width: 300px; border-left: 1px solid var(--border); overflow-y: auto;
  background: var(--surface); flex-shrink: 0; display: flex; flex-direction: column;
}
.matrix-header { padding: 10px 14px; border-bottom: 1px solid var(--border); }
.matrix-title { font-size: 12px; font-weight: 700; color: var(--wave); display: flex; align-items: center; gap: 6px; }
.matrix-sub { font-size: 10px; color: var(--dim); margin-top: 2px; }

.matrix-rows { flex: 1; overflow-y: auto; }
.matrix-row-wrap { border-bottom: 1px solid var(--border); }
.matrix-row { padding: 10px 14px; cursor: pointer; transition: background .1s; }
.matrix-row:hover { background: var(--card); }
.matrix-row.expanded { background: var(--card-alt); }

.matrix-row-top { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; }
.matrix-bar { width: 3px; height: 22px; border-radius: 2px; }
.matrix-tf { min-width: 34px; }
.tf-label { font-family: var(--mono); font-size: 14px; font-weight: 700; color: var(--text); }
.degree-label { font-size: 8px; color: var(--dim); font-family: var(--mono); }
.matrix-spacer { flex: 1; }

.wave-badge {
  display: inline-flex; align-items: baseline; gap: 1px; border-radius: 5px;
  padding: 1px 6px; font-family: var(--mono); font-size: 11px; font-weight: 700; line-height: 1;
}
.wave-badge.imp { background: rgba(139,92,246,0.12); border: 1px solid rgba(139,92,246,0.25); color: var(--wave); }
.wave-badge.corr { background: rgba(245,158,11,0.12); border: 1px solid rgba(245,158,11,0.25); color: var(--ob); }
.wave-of { font-size: 8px; color: var(--dim); }

.phase-label { font-size: 9px; font-family: var(--mono); font-weight: 600; }
.phase-label.imp { color: var(--wave); }
.phase-label.corr { color: var(--ob); }

.dir-badge {
  display: flex; align-items: center; gap: 3px; padding: 2px 8px; border-radius: 4px;
  font-family: var(--mono); font-size: 9px; font-weight: 700;
}
.dir-badge.bull { background: var(--bull-fade); border: 1px solid var(--bull-line); color: var(--bull); }
.dir-badge.bear { background: var(--bear-fade); border: 1px solid var(--bear-line); color: var(--bear); }
.dir-arrow { display: inline-block; font-size: 10px; }

.matrix-row-bottom { display: flex; align-items: center; gap: 6px; padding-left: 12px; margin-bottom: 3px; }
.progress-bar { flex: 1; height: 3px; background: var(--border); border-radius: 2px; }
.progress-fill { height: 100%; border-radius: 2px; transition: width .3s; }
.pct-label { font-family: var(--mono); font-size: 9px; color: var(--dim); min-width: 26px; }
.matrix-note { padding-left: 12px; font-size: 10px; color: var(--dim); line-height: 1.3; }

.matrix-detail { padding: 0 14px 12px; background: var(--card-alt); }
.detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
.detail-card { padding: 8px 10px; border-radius: 6px; background: var(--surface); border: 1px solid var(--border); }
.detail-card.bull { background: var(--bull-fade); border-color: var(--bull-line); }
.detail-card.bear { background: var(--bear-fade); border-color: var(--bear-line); }
.detail-label { font-size: 8px; color: var(--dim); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; }
.detail-value { font-family: var(--mono); font-size: 13px; font-weight: 700; }

/* ── Matrix footer ── */
.matrix-footer { padding: 10px 14px; border-top: 1px solid var(--border); background: var(--card); }
.conf-label { font-size: 9px; color: var(--dim); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; }
.conf-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 6px; }
.conf-card { padding: 6px 8px; border-radius: 6px; background: var(--surface); border: 1px solid var(--border); }
.conf-card.ok { border-color: var(--bull-line); }
.conf-card-label { font-size: 9px; font-weight: 600; margin-bottom: 2px; color: var(--dim); }
.conf-card.ok .conf-card-label { color: var(--bull); }
.conf-card-desc { font-size: 10px; color: var(--muted); }
.conf-card-score { font-family: var(--mono); font-size: 9px; color: var(--dim); margin-top: 2px; }
.conf-total {
  margin-top: 8px; padding: 8px; border-radius: 6px; text-align: center;
  background: rgba(0,220,130,0.06); border: 1px solid var(--bull-line);
}
.conf-total-num { font-family: var(--mono); font-size: 20px; font-weight: 700; color: var(--bull); }
.conf-total-pct { font-family: var(--mono); font-size: 12px; color: var(--muted); }
.conf-total-text { font-size: 10px; color: var(--muted); margin-top: 2px; }

@keyframes pulse { 0%,100% { opacity:1; } 50% { opacity:0.3; } }
</style>
