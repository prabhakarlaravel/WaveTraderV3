import { defineStore } from 'pinia'
import { ref, watch, onUnmounted, computed } from 'vue'
import echo from '../echo'
import { useChartStore } from './useChartStore'

export const useRealtimeStore = defineStore('realtime', () => {
  const connected = ref(false)
  const lastUpdate = ref(null)
  const subscribedChannels = ref([])
  const pollingInterval = ref(null)
  const wsActive = ref(false)
  const fetchCount = ref(0)

  const chartStore = useChartStore()

  // Stale if no update in 90 seconds (rule #1: warn if >90s)
  const isStale = computed(() => {
    if (!lastUpdate.value) return true
    return Date.now() - lastUpdate.value.getTime() > 90000
  })

  const secondsSinceUpdate = computed(() => {
    if (!lastUpdate.value) return null
    return Math.floor((Date.now() - lastUpdate.value.getTime()) / 1000)
  })

  /**
   * Subscribe to all WebSocket channels for the active symbol.
   */
  function subscribe(symbol, timeframe) {
    unsubscribeAll()

    if (!symbol) return

    // Candle updates
    const candleChannel = `candles.${symbol}.${timeframe}`
    try {
      echo.channel(candleChannel)
        .listen('CandleUpdated', (e) => {
          wsActive.value = true
          lastUpdate.value = new Date()
          onCandleUpdate(e)
        })
      subscribedChannels.value.push(candleChannel)
    } catch (err) {
      console.warn('[Realtime] WebSocket subscribe failed:', err.message)
    }

    // Signal updates
    const signalChannel = `signals.${symbol}`
    try {
      echo.channel(signalChannel)
        .listen('SignalGenerated', (e) => {
          lastUpdate.value = new Date()
          onSignalUpdate(e)
        })
        .listen('OrderBlockUpdated', () => {
          lastUpdate.value = new Date()
        })
        .listen('FVGUpdated', () => {
          lastUpdate.value = new Date()
        })
      subscribedChannels.value.push(signalChannel)
    } catch (err) {
      console.warn('[Realtime] Signal channel failed:', err.message)
    }

    // Wave updates
    const waveChannel = `waves.${symbol}`
    try {
      echo.channel(waveChannel)
        .listen('WaveUpdated', () => {
          lastUpdate.value = new Date()
        })
      subscribedChannels.value.push(waveChannel)
    } catch (err) {
      console.warn('[Realtime] Wave channel failed:', err.message)
    }

    connected.value = true

    // Start fallback HTTP polling (30s interval per CLAUDE.md)
    startPolling()

    console.log(`[Realtime] Subscribed to ${symbol} ${timeframe}`)
  }

  function unsubscribeAll() {
    subscribedChannels.value.forEach((ch) => {
      try { echo.leave(ch) } catch {}
    })
    subscribedChannels.value = []
    connected.value = false
    stopPolling()
  }

  /**
   * 30-second HTTP polling fallback.
   * Always polls — ensures data freshness even if WebSocket is working
   * (WebSocket delivers individual candle ticks, polling ensures no gaps).
   */
  function startPolling() {
    stopPolling()

    pollingInterval.value = setInterval(async () => {
      try {
        await pollLatestCandles()
        fetchCount.value++
      } catch (err) {
        console.warn('[Realtime] Poll failed:', err.message)
      }
    }, 30000) // 30 seconds per CLAUDE.md
  }

  function stopPolling() {
    if (pollingInterval.value) {
      clearInterval(pollingInterval.value)
      pollingInterval.value = null
    }
  }

  /**
   * Fetch only the latest candles (delta) and merge into existing data.
   */
  async function pollLatestCandles() {
    if (!chartStore.activeSymbolId || !chartStore.activeTimeframe) return

    const existingCandles = chartStore.candles
    let fromParam = undefined

    // Only fetch candles after the last one we have
    if (existingCandles.length > 0) {
      const lastTs = existingCandles[existingCandles.length - 1].timestamp
      fromParam = lastTs
    }

    const { data: newCandles } = await import('axios').then(m =>
      m.default.get('/api/v1/chart/candles', {
        params: {
          symbol_id: chartStore.activeSymbolId,
          timeframe: chartStore.activeTimeframe,
          from: fromParam,
        },
      })
    )

    if (!newCandles || newCandles.length === 0) return

    // Merge: update last candle if same timestamp, append new ones
    let updated = false
    for (const nc of newCandles) {
      const existingIdx = existingCandles.findIndex(c => c.timestamp === nc.timestamp)
      if (existingIdx >= 0) {
        // Update existing candle (OHLCV may have changed)
        const old = existingCandles[existingIdx]
        if (old.close !== nc.close || old.high !== nc.high || old.low !== nc.low || old.volume !== nc.volume) {
          existingCandles[existingIdx] = nc
          updated = true
        }
      } else {
        // New candle — append
        existingCandles.push(nc)
        updated = true
      }
    }

    if (updated) {
      chartStore.candles = [...existingCandles]
      lastUpdate.value = new Date()

      // Also refresh overlays since engines should re-run on new data
      await chartStore.fetchOverlays()
    }
  }

  /**
   * Handle incoming WebSocket candle update — append or update the last candle.
   */
  function onCandleUpdate(event) {
    const candle = event.candle
    if (!candle) return

    const candles = chartStore.candles
    if (!candles.length) return

    const lastCandle = candles[candles.length - 1]
    const newTimestamp = candle.timestamp

    if (lastCandle.timestamp === newTimestamp) {
      Object.assign(candles[candles.length - 1], candle)
    } else {
      candles.push(candle)
    }

    chartStore.candles = [...candles]
  }

  /**
   * Handle incoming signal — refresh overlays.
   */
  function onSignalUpdate() {
    chartStore.fetchOverlays()
  }

  /**
   * Force an immediate refresh (manual trigger).
   */
  async function forceRefresh() {
    await pollLatestCandles()
    fetchCount.value++
  }

  // Auto-subscribe when active symbol changes
  watch(
    () => [chartStore.activeSymbol?.ticker, chartStore.activeTimeframe],
    ([ticker, tf]) => {
      if (ticker && tf) {
        subscribe(ticker, tf)
      }
    },
    { immediate: true }
  )

  return {
    connected,
    lastUpdate,
    subscribedChannels,
    wsActive,
    isStale,
    secondsSinceUpdate,
    fetchCount,
    subscribe,
    unsubscribeAll,
    forceRefresh,
    startPolling,
    stopPolling,
  }
})
