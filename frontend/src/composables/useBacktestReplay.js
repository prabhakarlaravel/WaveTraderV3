import { ref, computed, watch } from 'vue'
import axios from 'axios'
import { toISTEpoch } from '../utils/timezone'

/**
 * Backtest replay state machine.
 * Loads candles + overlays for a date range, then replays bar-by-bar
 * with configurable speed. Overlays are pre-fetched and filtered by timestamp.
 */
export function useBacktestReplay() {
  // Data
  const allCandles = ref([])
  const allOverlays = ref(null)
  const visibleCandles = ref([])
  const filteredOverlays = ref({})

  // Replay state
  const currentBarIndex = ref(0)
  const isPlaying = ref(false)
  const speed = ref(1)
  const loading = ref(false)
  const loaded = ref(false)
  let intervalId = null

  // Config
  const symbolId = ref(null)
  const timeframe = ref('1H')
  const fromDate = ref('')
  const toDate = ref('')

  const totalBars = computed(() => allCandles.value.length)
  const progress = computed(() => totalBars.value > 0 ? (currentBarIndex.value / totalBars.value) * 100 : 0)
  const isComplete = computed(() => currentBarIndex.value >= totalBars.value - 1)

  const currentCandle = computed(() => {
    if (!allCandles.value.length || currentBarIndex.value < 0) return null
    return allCandles.value[currentBarIndex.value]
  })

  const currentPrice = computed(() => {
    if (!currentCandle.value) return 0
    return parseFloat(currentCandle.value.close)
  })

  // All times displayed in IST
  const toLocalEpoch = toISTEpoch

  const formattedVisible = computed(() =>
    visibleCandles.value.map(c => ({
      time: toLocalEpoch(c.timestamp),
      open: parseFloat(c.open),
      high: parseFloat(c.high),
      low: parseFloat(c.low),
      close: parseFloat(c.close),
    }))
  )

  const formattedVolume = computed(() =>
    visibleCandles.value.map(c => {
      const o = parseFloat(c.open)
      const cl = parseFloat(c.close)
      return {
        time: toLocalEpoch(c.timestamp),
        value: parseFloat(c.volume),
        color: cl >= o ? 'rgba(38, 166, 154, 0.3)' : 'rgba(239, 83, 80, 0.3)',
      }
    })
  )

  // --- Trade tracking ---
  const trades = ref([])
  const openPositions = ref([])
  const equity = ref([10000])
  const initialCapital = 10000
  let capital = initialCapital

  /**
   * Load all candles + overlays for the date range.
   */
  async function load(symId, tf, from, to) {
    loading.value = true
    loaded.value = false
    pause()

    symbolId.value = symId
    timeframe.value = tf
    fromDate.value = from
    toDate.value = to

    try {
      // Fetch all candles for range
      const { data: candles } = await axios.get('/api/v1/chart/candles', {
        params: { symbol_id: symId, timeframe: tf, from, to },
      })
      allCandles.value = candles

      // Fetch overlays for full range (pre-computed)
      const { data: overlays } = await axios.get('/api/v1/chart/overlays', {
        params: { symbol_id: symId, timeframe: tf },
      })
      allOverlays.value = overlays

      // Reset state
      currentBarIndex.value = Math.min(30, candles.length - 1) // Start with 30 bars visible
      trades.value = []
      openPositions.value = []
      capital = initialCapital
      equity.value = [initialCapital]

      updateVisible()
      loaded.value = true
    } catch (err) {
      console.error('Failed to load backtest data:', err)
    } finally {
      loading.value = false
    }
  }

  /**
   * Slice candles and filter overlays up to current bar.
   */
  function updateVisible() {
    const idx = currentBarIndex.value
    visibleCandles.value = allCandles.value.slice(0, idx + 1)

    if (!allOverlays.value) {
      filteredOverlays.value = {}
      return
    }

    const cutoffTime = allCandles.value[idx]?.timestamp
    if (!cutoffTime) return

    // Filter each overlay type by timestamp
    const o = allOverlays.value
    filteredOverlays.value = {
      signals: filterByTime(o.signals, cutoffTime),
      orderBlocks: filterByTime(o.orderBlocks, cutoffTime, 'formed_at'),
      fvgs: filterByTime(o.fvgs, cutoffTime, 'formed_at'),
      swings: filterByTime(o.swings, cutoffTime),
      waveLabels: filterByTime(o.waveLabels, cutoffTime),
      bos: filterByTime(o.bos, cutoffTime),
      vwap: filterByTime(o.vwap, cutoffTime),
      patterns: filterByTime(o.patterns, cutoffTime),
      fibTargets: o.fibTargets || [],
      liquidityPools: filterByTime(o.liquidityPools, cutoffTime),
      oteZones: filterByTime(o.oteZones, cutoffTime),
      premiumDiscount: o.premiumDiscount || [],
      inducements: filterByTime(o.inducements, cutoffTime),
      confluence: o.confluence || null,
      metadata: o.metadata || {},
    }
  }

  function filterByTime(arr, cutoff, tsKey = 'timestamp') {
    if (!Array.isArray(arr)) return []
    return arr.filter(item => {
      const ts = item[tsKey] || item.timestamp || item.candle_timestamp || item.created_at
      if (!ts) return true // Keep items without timestamps
      return ts <= cutoff
    })
  }

  // --- Playback controls ---

  function play() {
    if (isComplete.value) {
      currentBarIndex.value = Math.min(30, allCandles.value.length - 1)
    }
    isPlaying.value = true
    startInterval()
  }

  function pause() {
    isPlaying.value = false
    clearInterval(intervalId)
    intervalId = null
  }

  function togglePlay() {
    isPlaying.value ? pause() : play()
  }

  function stepForward() {
    if (currentBarIndex.value < totalBars.value - 1) {
      currentBarIndex.value++
      onBarAdvance()
    }
  }

  function stepBack() {
    if (currentBarIndex.value > 0) {
      currentBarIndex.value--
      updateVisible()
    }
  }

  function seekTo(index) {
    currentBarIndex.value = Math.max(0, Math.min(index, totalBars.value - 1))
    updateVisible()
  }

  function setSpeed(s) {
    speed.value = s
    if (isPlaying.value) {
      clearInterval(intervalId)
      startInterval()
    }
  }

  function startInterval() {
    clearInterval(intervalId)
    const ms = Math.max(16, 1000 / speed.value)
    intervalId = setInterval(() => {
      if (currentBarIndex.value >= totalBars.value - 1) {
        pause()
        return
      }
      currentBarIndex.value++
      onBarAdvance()
    }, ms)
  }

  /**
   * Called every time a new bar appears.
   */
  function onBarAdvance() {
    updateVisible()
    checkStops()
    trackEquity()
  }

  // --- Trade management ---

  function openTrade(direction, quantity, sl, tp, notes) {
    const price = currentPrice.value
    const trade = {
      id: Date.now(),
      direction,
      entry: price,
      quantity: parseFloat(quantity) || 1,
      sl: sl ? parseFloat(sl) : null,
      tp: tp ? parseFloat(tp) : null,
      notes: notes || '',
      entryBar: currentBarIndex.value,
      entryTime: currentCandle.value?.timestamp,
      pnl: 0,
      status: 'open',
    }
    openPositions.value.push(trade)
    return trade
  }

  function closeTrade(tradeId) {
    const idx = openPositions.value.findIndex(t => t.id === tradeId)
    if (idx < 0) return

    const trade = openPositions.value[idx]
    const exitPrice = currentPrice.value
    const mult = trade.direction === 'long' ? 1 : -1
    trade.exit = exitPrice
    trade.pnl = (exitPrice - trade.entry) * trade.quantity * mult
    trade.status = 'closed'
    trade.exitBar = currentBarIndex.value
    trade.exitTime = currentCandle.value?.timestamp

    capital += trade.pnl
    trades.value.push(trade)
    openPositions.value.splice(idx, 1)
  }

  function checkStops() {
    if (!currentCandle.value) return
    const high = parseFloat(currentCandle.value.high)
    const low = parseFloat(currentCandle.value.low)

    const toClose = []
    for (const pos of openPositions.value) {
      if (pos.direction === 'long') {
        if (pos.sl && low <= pos.sl) { pos.exit = pos.sl; toClose.push(pos.id) }
        else if (pos.tp && high >= pos.tp) { pos.exit = pos.tp; toClose.push(pos.id) }
      } else {
        if (pos.sl && high >= pos.sl) { pos.exit = pos.sl; toClose.push(pos.id) }
        else if (pos.tp && low <= pos.tp) { pos.exit = pos.tp; toClose.push(pos.id) }
      }
    }
    toClose.forEach(id => closeTrade(id))
  }

  function trackEquity() {
    let unrealized = 0
    const price = currentPrice.value
    for (const pos of openPositions.value) {
      const mult = pos.direction === 'long' ? 1 : -1
      unrealized += (price - pos.entry) * pos.quantity * mult
    }
    equity.value.push(Math.round((capital + unrealized) * 100) / 100)
  }

  // --- Results ---

  const results = computed(() => {
    const closed = trades.value
    const total = closed.length
    const wins = closed.filter(t => t.pnl > 0)
    const losses = closed.filter(t => t.pnl <= 0)
    const winCount = wins.length
    const winRate = total > 0 ? Math.round(winCount / total * 1000) / 10 : 0
    const grossProfit = wins.reduce((s, t) => s + t.pnl, 0)
    const grossLoss = Math.abs(losses.reduce((s, t) => s + t.pnl, 0))
    const profitFactor = grossLoss > 0 ? Math.round(grossProfit / grossLoss * 100) / 100 : 0
    const netPnl = Math.round((capital - initialCapital) * 100) / 100

    // Max drawdown
    let peak = initialCapital
    let maxDD = 0
    for (const eq of equity.value) {
      if (eq > peak) peak = eq
      const dd = peak > 0 ? (peak - eq) / peak * 100 : 0
      if (dd > maxDD) maxDD = dd
    }

    return {
      totalTrades: total,
      winRate,
      netPnl,
      profitFactor,
      maxDrawdown: Math.round(maxDD * 100) / 100,
      grossProfit: Math.round(grossProfit * 100) / 100,
      grossLoss: Math.round(grossLoss * 100) / 100,
      finalCapital: Math.round(capital * 100) / 100,
      equityCurve: equity.value,
      trades: closed,
    }
  })

  function reset() {
    pause()
    allCandles.value = []
    allOverlays.value = null
    visibleCandles.value = []
    filteredOverlays.value = {}
    currentBarIndex.value = 0
    trades.value = []
    openPositions.value = []
    capital = initialCapital
    equity.value = [initialCapital]
    loaded.value = false
  }

  return {
    // Data
    allCandles,
    visibleCandles,
    filteredOverlays,
    formattedVisible,
    formattedVolume,
    currentCandle,
    currentPrice,

    // State
    currentBarIndex,
    totalBars,
    progress,
    isPlaying,
    isComplete,
    speed,
    loading,
    loaded,

    // Controls
    load,
    play,
    pause,
    togglePlay,
    stepForward,
    stepBack,
    seekTo,
    setSpeed,
    reset,

    // Trades
    openPositions,
    trades,
    openTrade,
    closeTrade,
    results,
    equity,
  }
}
