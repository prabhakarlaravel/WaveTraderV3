<script setup>
import { ref, onMounted, onUnmounted, watch, computed, nextTick } from 'vue'
import { createChart, CandlestickSeries, HistogramSeries, LineSeries } from 'lightweight-charts'
import { useChartStore } from '../stores/useChartStore'
import { useBacktestReplay } from '../composables/useBacktestReplay'
import { useChartOverlays } from '../composables/useChartOverlays'
import axios from 'axios'

const chartStore = useChartStore()
const replay = useBacktestReplay()

const chartContainer = ref(null)
const chartRef = ref(null)
const candleSeriesRef = ref(null)
let volumeSeries = null
let resizeObserver = null

const timeframes = ['1M', '5M', '15M', '1H', '4H', '1D']
const speeds = [0.5, 1, 2, 5, 10, 50]
const overlayToggles = ref({ waves: true, ob: true, fvg: true, bos: true, vwap: true })
const rightPanel = ref('trade') // 'trade' | 'matrix' | 'results'

// Config
const selectedSymbol = ref(null)
const selectedTf = ref('1H')
const fromDate = ref('')
const toDate = ref('')

// Trade form
const direction = ref('long')
const quantity = ref(1)
const slInput = ref('')
const tpInput = ref('')
const notesInput = ref('')

// Equity chart
const equityContainer = ref(null)
let equityChart = null

// Proxy object that mimics chartStore.overlays for the overlay composable
const overlayProxy = {
  get overlays() { return replay.filteredOverlays.value },
  get candles() { return replay.visibleCandles.value },
}

const { renderAll, cleanup, attachChartListeners, setContainer } = useChartOverlays(
  chartRef, candleSeriesRef, overlayProxy, overlayToggles
)

// Price display
const lastPrice = computed(() => {
  if (!replay.currentCandle.value) return { price: 0, change: 0, bull: true }
  const c = replay.visibleCandles.value
  if (c.length < 2) return { price: parseFloat(replay.currentCandle.value.close), change: 0, bull: true }
  const curr = parseFloat(c[c.length - 1].close)
  const prev = parseFloat(c[c.length - 2].close)
  const pct = prev > 0 ? ((curr - prev) / prev) * 100 : 0
  return { price: curr, change: Math.round(pct * 100) / 100, bull: curr >= prev }
})

const symbolName = computed(() => {
  const sym = chartStore.symbols.find(s => s.id === selectedSymbol.value)
  return sym?.ticker || ''
})

onMounted(async () => {
  await chartStore.fetchSymbols()
  if (chartStore.symbols.length) selectedSymbol.value = chartStore.symbols[0].id

  const now = new Date()
  toDate.value = now.toISOString().split('T')[0]
  const from = new Date(now); from.setDate(from.getDate() - 30)
  fromDate.value = from.toISOString().split('T')[0]
})

onUnmounted(() => {
  replay.reset()
  cleanup()
  if (resizeObserver) resizeObserver.disconnect()
  if (chartRef.value) { chartRef.value.remove(); chartRef.value = null }
  if (equityChart) { equityChart.remove(); equityChart = null }
})

function initChart() {
  if (!chartContainer.value) return
  if (chartRef.value) chartRef.value.remove()

  chartRef.value = createChart(chartContainer.value, {
    width: chartContainer.value.clientWidth,
    height: chartContainer.value.clientHeight,
    layout: { background: { color: '#1a2540' }, textColor: '#8b9bbf' },
    grid: { vertLines: { color: '#243354' }, horzLines: { color: '#243354' } },
    crosshair: { mode: 0, vertLine: { color: '#5a70a5', width: 1, style: 2 }, horzLine: { color: '#5a70a5', width: 1, style: 2 } },
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
  chartRef.value.priceScale('volume').applyOptions({ scaleMargins: { top: 0.85, bottom: 0 } })

  setContainer(chartContainer.value)
  attachChartListeners(chartRef.value)

  resizeObserver = new ResizeObserver(() => {
    if (chartRef.value && chartContainer.value) {
      chartRef.value.applyOptions({ width: chartContainer.value.clientWidth, height: chartContainer.value.clientHeight })
    }
  })
  resizeObserver.observe(chartContainer.value)
}

function updateChartData() {
  if (!candleSeriesRef.value || !replay.formattedVisible.value.length) return
  candleSeriesRef.value.setData(replay.formattedVisible.value)
  volumeSeries?.setData(replay.formattedVolume.value)
}

async function startReplay() {
  if (!selectedSymbol.value || !fromDate.value || !toDate.value) return
  await replay.load(selectedSymbol.value, selectedTf.value, fromDate.value, toDate.value)

  await nextTick()
  initChart()
  updateChartData()
  renderAll()
}

// Watch visible candles → update chart
watch(() => replay.currentBarIndex.value, () => {
  updateChartData()
  renderAll()
})

// Watch overlay toggles
watch(overlayToggles, () => renderAll(), { deep: true })

// Trade functions
function submitTrade() {
  replay.openTrade(
    direction.value,
    quantity.value,
    slInput.value || null,
    tpInput.value || null,
    notesInput.value,
  )
  slInput.value = ''
  tpInput.value = ''
  notesInput.value = ''
}

function closeTrade(id) {
  replay.closeTrade(id)
}

function tradePnl(trade) {
  const price = replay.currentPrice.value
  const mult = trade.direction === 'long' ? 1 : -1
  return ((price - trade.entry) * trade.quantity * mult).toFixed(2)
}

function formatPrice(v) {
  return typeof v === 'number' ? v.toFixed(2) : parseFloat(v || 0).toFixed(2)
}

function renderEquity() {
  if (!equityContainer.value) return
  if (equityChart) equityChart.remove()

  const curve = replay.results.value.equityCurve
  if (!curve?.length) return

  equityChart = createChart(equityContainer.value, {
    width: equityContainer.value.clientWidth, height: 150,
    layout: { background: { color: '#06090f' }, textColor: '#7b8ba8' },
    grid: { vertLines: { color: '#162040' }, horzLines: { color: '#162040' } },
    rightPriceScale: { borderColor: '#162040' },
    timeScale: { visible: false },
  })
  const series = equityChart.addSeries(LineSeries, { color: '#00dc82', lineWidth: 2, lastValueVisible: true, priceLineVisible: false })
  series.setData(curve.map((v, i) => ({ time: 1000000 + i, value: v })))
  equityChart.timeScale().fitContent()
}

watch(() => rightPanel.value, (v) => {
  if (v === 'results') nextTick(() => renderEquity())
})
</script>

<template>
  <div class="backtest-page">
    <!-- Toolbar Row 1: Symbol + Timeframe + Overlays -->
    <div class="toolbar">
      <div class="sym-group">
        <button v-for="s in chartStore.symbols" :key="s.id"
          :class="['sym-btn', { active: s.id === selectedSymbol }]"
          @click="selectedSymbol = s.id">{{ s.ticker }}</button>
      </div>

      <div class="toolbar-sep"></div>

      <div class="tf-group">
        <button v-for="tf in timeframes" :key="tf"
          :class="['tf-btn', { active: tf === selectedTf }]"
          @click="selectedTf = tf">{{ tf }}</button>
      </div>

      <div class="toolbar-sep"></div>

      <template v-for="o in [
        { key: 'waves', label: 'Waves', color: '#8b5cf6' },
        { key: 'ob', label: 'OB', color: '#f59e0b' },
        { key: 'fvg', label: 'FVG', color: '#06b6d4' },
        { key: 'bos', label: 'BOS', color: '#10b981' },
        { key: 'vwap', label: 'VWAP', color: '#ec4899' },
      ]" :key="o.key">
        <button :class="['overlay-btn', { active: overlayToggles[o.key] }]"
          :style="overlayToggles[o.key] ? `border-color: ${o.color}; color: ${o.color}` : ''"
          @click="overlayToggles[o.key] = !overlayToggles[o.key]">
          <span class="dot" :style="{ background: o.color }"></span> {{ o.label }}
        </button>
      </template>
    </div>

    <!-- Toolbar Row 2: Date range + Replay controls -->
    <div class="toolbar replay-bar">
      <div class="date-group">
        <label style="color: var(--dim); font-size: 10px">FROM</label>
        <input v-model="fromDate" type="date" class="date-input" />
        <label style="color: var(--dim); font-size: 10px">TO</label>
        <input v-model="toDate" type="date" class="date-input" />
      </div>

      <button v-if="!replay.loaded.value" @click="startReplay" :disabled="replay.loading.value"
        class="load-btn">{{ replay.loading.value ? 'Loading...' : 'Load & Start' }}</button>

      <template v-if="replay.loaded.value">
        <div class="toolbar-sep"></div>

        <!-- Playback controls -->
        <button class="ctrl-btn" @click="replay.stepBack()" title="Step back">&#9664;&#9664;</button>
        <button class="ctrl-btn play-btn" @click="replay.togglePlay()">
          {{ replay.isPlaying.value ? '&#9646;&#9646;' : '&#9654;' }}
        </button>
        <button class="ctrl-btn" @click="replay.stepForward()" title="Step forward">&#9654;&#9654;</button>

        <div class="toolbar-sep"></div>

        <!-- Speed selector -->
        <div class="speed-group">
          <button v-for="s in speeds" :key="s"
            :class="['speed-btn', { active: replay.speed.value === s }]"
            @click="replay.setSpeed(s)">{{ s }}x</button>
        </div>

        <div class="toolbar-sep"></div>

        <!-- Progress -->
        <div class="progress-group">
          <input type="range" :min="0" :max="replay.totalBars.value - 1"
            :value="replay.currentBarIndex.value"
            @input="replay.seekTo(parseInt($event.target.value))"
            class="progress-slider" />
          <span class="bar-counter">{{ replay.currentBarIndex.value }} / {{ replay.totalBars.value }}</span>
        </div>
      </template>

      <!-- Price display -->
      <div class="price-display" v-if="replay.loaded.value">
        <span class="price-val" :style="{ color: lastPrice.bull ? 'var(--bull)' : 'var(--bear)' }">
          {{ formatPrice(lastPrice.price) }}
        </span>
        <span class="price-chg" :style="{ color: lastPrice.bull ? 'var(--bull)' : 'var(--bear)' }">
          {{ lastPrice.change >= 0 ? '+' : '' }}{{ lastPrice.change }}%
        </span>
      </div>
    </div>

    <!-- Main area -->
    <div class="main-area" v-if="replay.loaded.value">
      <!-- Chart -->
      <div class="chart-col">
        <div ref="chartContainer" class="chart-container"></div>

        <!-- Bias strip -->
        <div class="bias-strip">
          <div class="bias-card">
            <div class="bias-label">Replay Mode</div>
            <div class="bias-val" style="color: var(--accent)">PRACTICE</div>
          </div>
          <div class="bias-card">
            <div class="bias-label">Open Positions</div>
            <div class="bias-val" :style="{ color: replay.openPositions.value.length > 0 ? 'var(--ob)' : 'var(--dim)' }">
              {{ replay.openPositions.value.length }}
            </div>
          </div>
          <div class="bias-card">
            <div class="bias-label">P&L</div>
            <div class="bias-val" :style="{ color: replay.results.value.netPnl >= 0 ? 'var(--bull)' : 'var(--bear)' }">
              {{ replay.results.value.netPnl >= 0 ? '+' : '' }}${{ replay.results.value.netPnl }}
            </div>
          </div>
        </div>
      </div>

      <!-- Right panel -->
      <div class="right-panel">
        <div class="panel-tabs">
          <button :class="['ptab', { active: rightPanel === 'trade' }]" @click="rightPanel = 'trade'">TRADE</button>
          <button :class="['ptab', { active: rightPanel === 'results' }]" @click="rightPanel = 'results'">RESULTS</button>
        </div>

        <!-- Trade tab -->
        <div v-if="rightPanel === 'trade'" class="panel-body">
          <div class="panel-title">PRACTICE TRADE</div>

          <div class="dir-toggle">
            <button :class="['dir-btn', { active: direction === 'long' }]"
              style="background: rgba(0,220,130,0.15); color: var(--bull)"
              @click="direction = 'long'">LONG</button>
            <button :class="['dir-btn', { active: direction === 'short' }]"
              style="background: rgba(255,59,92,0.15); color: var(--bear)"
              @click="direction = 'short'">SHORT</button>
          </div>

          <div class="field">
            <label>ENTRY (MARKET)</label>
            <input :value="formatPrice(replay.currentPrice.value)" readonly class="field-input" />
          </div>
          <div class="field">
            <label>QUANTITY</label>
            <input v-model.number="quantity" type="number" min="0.01" step="0.1" class="field-input" />
          </div>
          <div class="field-row">
            <div class="field">
              <label>STOP LOSS</label>
              <input v-model="slInput" type="number" step="any" placeholder="Optional" class="field-input" />
            </div>
            <div class="field">
              <label>TAKE PROFIT</label>
              <input v-model="tpInput" type="number" step="any" placeholder="Optional" class="field-input" />
            </div>
          </div>
          <div class="field">
            <label>NOTES</label>
            <input v-model="notesInput" placeholder="Trade reason..." class="field-input" />
          </div>

          <button @click="submitTrade" class="submit-btn"
            :style="{ background: direction === 'long' ? 'var(--bull)' : 'var(--bear)' }">
            {{ direction === 'long' ? 'BUY' : 'SELL' }} @ {{ formatPrice(replay.currentPrice.value) }}
          </button>

          <!-- Open positions -->
          <div class="positions-header">
            OPEN POSITIONS <span class="pos-count">{{ replay.openPositions.value.length }}</span>
          </div>
          <div v-if="!replay.openPositions.value.length" class="empty-pos">No open positions</div>
          <div v-for="t in replay.openPositions.value" :key="t.id" class="pos-card">
            <div class="pos-top">
              <span class="pos-dir" :style="{ color: t.direction === 'long' ? 'var(--bull)' : 'var(--bear)' }">
                {{ t.direction.toUpperCase() }}
              </span>
              <span class="pos-pnl" :style="{ color: parseFloat(tradePnl(t)) >= 0 ? 'var(--bull)' : 'var(--bear)' }">
                {{ parseFloat(tradePnl(t)) >= 0 ? '+' : '' }}${{ tradePnl(t) }}
              </span>
            </div>
            <div class="pos-details">
              Entry: {{ formatPrice(t.entry) }} | Qty: {{ t.quantity }}
              <span v-if="t.sl"> | SL: {{ formatPrice(t.sl) }}</span>
              <span v-if="t.tp"> | TP: {{ formatPrice(t.tp) }}</span>
            </div>
            <button @click="closeTrade(t.id)" class="close-btn">Close @ {{ formatPrice(replay.currentPrice.value) }}</button>
          </div>

          <!-- P&L summary -->
          <div class="pnl-footer">
            <div>
              <div class="pnl-label">Total P&L</div>
              <div class="pnl-val" :style="{ color: replay.results.value.netPnl >= 0 ? 'var(--bull)' : 'var(--bear)' }">
                {{ replay.results.value.netPnl >= 0 ? '+' : '' }}${{ replay.results.value.netPnl }}
              </div>
              <div class="pnl-sub">{{ replay.results.value.totalTrades }} closed · {{ replay.openPositions.value.length }} open</div>
            </div>
            <div class="text-right">
              <div class="pnl-label">Win Rate</div>
              <div class="pnl-val" style="color: var(--text)">{{ replay.results.value.winRate }}%</div>
            </div>
          </div>
        </div>

        <!-- Results tab -->
        <div v-if="rightPanel === 'results'" class="panel-body">
          <div class="panel-title">BACKTEST RESULTS</div>

          <div class="metrics-grid">
            <div v-for="m in [
              { label: 'Net P&L', value: `$${replay.results.value.netPnl}`, color: replay.results.value.netPnl >= 0 ? 'var(--bull)' : 'var(--bear)' },
              { label: 'Win Rate', value: `${replay.results.value.winRate}%`, color: replay.results.value.winRate >= 50 ? 'var(--bull)' : 'var(--bear)' },
              { label: 'Profit Factor', value: replay.results.value.profitFactor, color: replay.results.value.profitFactor >= 1 ? 'var(--bull)' : 'var(--bear)' },
              { label: 'Max DD', value: `${replay.results.value.maxDrawdown}%`, color: 'var(--bear)' },
              { label: 'Trades', value: replay.results.value.totalTrades, color: 'var(--text)' },
              { label: 'Capital', value: `$${replay.results.value.finalCapital}`, color: 'var(--text)' },
            ]" :key="m.label" class="metric-card">
              <div class="metric-val" :style="{ color: m.color }">{{ m.value }}</div>
              <div class="metric-label">{{ m.label }}</div>
            </div>
          </div>

          <!-- Equity curve -->
          <div class="equity-section">
            <div class="equity-title">EQUITY CURVE</div>
            <div ref="equityContainer" style="height: 150px"></div>
          </div>

          <!-- Trade log -->
          <div class="trade-log">
            <div class="log-title">TRADE LOG ({{ replay.trades.value.length }})</div>
            <div class="log-scroll">
              <div v-for="t in replay.trades.value" :key="t.id" class="log-row">
                <span class="log-dir" :style="{ color: t.direction === 'long' ? 'var(--bull)' : 'var(--bear)' }">
                  {{ t.direction === 'long' ? 'BUY' : 'SELL' }}
                </span>
                <span class="log-price">{{ formatPrice(t.entry) }} → {{ formatPrice(t.exit) }}</span>
                <span class="log-pnl" :style="{ color: t.pnl >= 0 ? 'var(--bull)' : 'var(--bear)' }">
                  {{ t.pnl >= 0 ? '+' : '' }}${{ t.pnl.toFixed(2) }}
                </span>
              </div>
              <div v-if="!replay.trades.value.length" class="empty-pos">No trades yet</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Empty state (before loading) -->
    <div v-else class="empty-state">
      <div class="empty-icon">&#9654;</div>
      <p class="empty-title">Practice Trading</p>
      <p class="empty-desc">Select a symbol, timeframe, and date range, then click "Load & Start" to begin replaying historical data bar-by-bar. Place trades as if it were live.</p>
    </div>
  </div>
</template>

<style scoped>
.backtest-page { display: flex; flex-direction: column; height: calc(100vh - 48px); overflow: hidden; }
.toolbar { display: flex; align-items: center; gap: 6px; padding: 6px 12px; background: var(--card); border-bottom: 1px solid var(--border); flex-wrap: wrap; }
.toolbar-sep { width: 1px; height: 20px; background: var(--border); margin: 0 4px; }

.sym-group, .tf-group, .speed-group { display: flex; gap: 2px; background: var(--surface); padding: 2px; border-radius: 6px; border: 1px solid var(--border); }
.sym-btn, .tf-btn, .speed-btn { border: none; background: transparent; color: var(--muted); padding: 3px 10px; border-radius: 4px; font-size: 11px; font-weight: 700; cursor: pointer; font-family: var(--mono); }
.sym-btn.active, .tf-btn.active, .speed-btn.active { background: var(--accent); color: #fff; }
.sym-btn:hover, .tf-btn:hover, .speed-btn:hover { color: var(--text); }

.overlay-btn { background: transparent; border: 1px solid var(--border); color: var(--dim); padding: 3px 10px; border-radius: 16px; font-size: 10px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 4px; }
.overlay-btn .dot { width: 6px; height: 6px; border-radius: 50%; }

.replay-bar { gap: 8px; }
.date-group { display: flex; align-items: center; gap: 6px; }
.date-input { background: var(--surface); border: 1px solid var(--border); color: var(--text); padding: 4px 8px; border-radius: 6px; font-size: 11px; font-family: var(--mono); }
.load-btn { background: var(--accent); color: #fff; border: none; padding: 5px 16px; border-radius: 6px; font-size: 12px; font-weight: 700; cursor: pointer; }
.load-btn:disabled { opacity: 0.5; cursor: not-allowed; }

.ctrl-btn { background: var(--surface); border: 1px solid var(--border); color: var(--text); padding: 4px 10px; border-radius: 6px; font-size: 13px; cursor: pointer; }
.ctrl-btn:hover { background: var(--border); }
.play-btn { background: var(--accent); color: #fff; border-color: var(--accent); font-size: 14px; padding: 4px 14px; }

.progress-group { display: flex; align-items: center; gap: 8px; flex: 1; min-width: 120px; }
.progress-slider { flex: 1; height: 4px; accent-color: var(--accent); cursor: pointer; }
.bar-counter { font-family: var(--mono); font-size: 10px; color: var(--dim); white-space: nowrap; }

.price-display { margin-left: auto; display: flex; align-items: baseline; gap: 6px; }
.price-val { font-family: var(--mono); font-size: 16px; font-weight: 700; }
.price-chg { font-family: var(--mono); font-size: 11px; }

.main-area { display: flex; flex: 1; overflow: hidden; }
.chart-col { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
.chart-container { flex: 1; min-height: 0; position: relative; }

.bias-strip { display: flex; gap: 8px; padding: 8px 12px; border-top: 1px solid var(--border); }
.bias-card { flex: 1; padding: 8px 12px; border-radius: 8px; background: var(--card); border: 1px solid var(--border); }
.bias-label { font-size: 9px; color: var(--dim); text-transform: uppercase; letter-spacing: 0.5px; }
.bias-val { font-size: 13px; font-weight: 700; font-family: var(--mono); margin-top: 2px; }

.right-panel { width: 300px; border-left: 1px solid var(--border); display: flex; flex-direction: column; overflow: hidden; }
.panel-tabs { display: flex; border-bottom: 1px solid var(--border); }
.ptab { flex: 1; padding: 8px; font-size: 10px; font-weight: 700; letter-spacing: 0.5px; border: none; background: transparent; color: var(--dim); cursor: pointer; }
.ptab.active { color: var(--accent); border-bottom: 2px solid var(--accent); }
.panel-body { flex: 1; overflow-y: auto; padding: 12px; }
.panel-title { font-size: 11px; font-weight: 700; color: var(--dim); letter-spacing: 0.5px; margin-bottom: 12px; }

.dir-toggle { display: flex; gap: 4px; margin-bottom: 12px; }
.dir-btn { flex: 1; padding: 8px; border: 1px solid var(--border); border-radius: 6px; font-size: 12px; font-weight: 700; cursor: pointer; text-align: center; opacity: 0.4; }
.dir-btn.active { opacity: 1; }

.field { margin-bottom: 10px; }
.field label { display: block; font-size: 9px; color: var(--dim); letter-spacing: 0.5px; margin-bottom: 3px; text-transform: uppercase; }
.field-input { width: 100%; background: var(--surface); border: 1px solid var(--border); color: var(--text); padding: 8px 10px; border-radius: 6px; font-size: 13px; font-family: var(--mono); box-sizing: border-box; }
.field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }

.submit-btn { width: 100%; padding: 10px; border: none; border-radius: 6px; color: #000; font-size: 13px; font-weight: 700; cursor: pointer; margin-bottom: 12px; }

.positions-header { display: flex; justify-content: space-between; align-items: center; font-size: 10px; font-weight: 700; color: var(--dim); letter-spacing: 0.5px; padding: 8px 0 6px; border-top: 1px solid var(--border); }
.pos-count { background: var(--surface); padding: 1px 6px; border-radius: 10px; font-size: 10px; }
.empty-pos { text-align: center; padding: 16px; font-size: 11px; color: var(--dim); }

.pos-card { padding: 8px; border-radius: 6px; background: var(--surface); border: 1px solid var(--border); margin-bottom: 6px; }
.pos-top { display: flex; justify-content: space-between; align-items: center; }
.pos-dir { font-size: 11px; font-weight: 700; }
.pos-pnl { font-family: var(--mono); font-size: 12px; font-weight: 700; }
.pos-details { font-size: 10px; color: var(--dim); font-family: var(--mono); margin: 4px 0; }
.close-btn { width: 100%; padding: 4px; border: 1px solid var(--border); border-radius: 4px; background: transparent; color: var(--bear); font-size: 10px; cursor: pointer; margin-top: 4px; }
.close-btn:hover { background: rgba(255,59,92,0.1); }

.pnl-footer { display: flex; justify-content: space-between; padding: 10px 0; border-top: 1px solid var(--border); margin-top: 8px; }
.pnl-label { font-size: 9px; color: var(--dim); text-transform: uppercase; }
.pnl-val { font-family: var(--mono); font-size: 14px; font-weight: 700; }
.pnl-sub { font-size: 9px; color: var(--dim); font-family: var(--mono); }

.metrics-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; margin-bottom: 12px; }
.metric-card { padding: 8px; border-radius: 6px; background: var(--surface); border: 1px solid var(--border); text-align: center; }
.metric-val { font-family: var(--mono); font-size: 13px; font-weight: 700; }
.metric-label { font-size: 9px; color: var(--dim); margin-top: 2px; }

.equity-section { margin-bottom: 12px; border-radius: 8px; overflow: hidden; background: var(--card); border: 1px solid var(--border); }
.equity-title { padding: 8px 10px; font-size: 9px; font-weight: 700; color: var(--dim); letter-spacing: 0.5px; border-bottom: 1px solid var(--border); }

.trade-log { border-radius: 8px; overflow: hidden; background: var(--card); border: 1px solid var(--border); }
.log-title { padding: 8px 10px; font-size: 9px; font-weight: 700; color: var(--dim); letter-spacing: 0.5px; border-bottom: 1px solid var(--border); }
.log-scroll { max-height: 200px; overflow-y: auto; }
.log-row { display: flex; justify-content: space-between; align-items: center; padding: 6px 10px; border-bottom: 1px solid rgba(22,32,64,0.3); font-size: 11px; font-family: var(--mono); }
.log-dir { font-weight: 700; width: 30px; }
.log-price { color: var(--muted); flex: 1; text-align: center; }
.log-pnl { font-weight: 700; text-align: right; }

.empty-state { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; }
.empty-icon { font-size: 48px; color: var(--accent); margin-bottom: 16px; }
.empty-title { font-size: 20px; font-weight: 700; color: var(--text); }
.empty-desc { font-size: 13px; color: var(--muted); max-width: 400px; text-align: center; margin-top: 8px; line-height: 1.5; }
</style>
