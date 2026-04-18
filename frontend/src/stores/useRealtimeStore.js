import { defineStore } from 'pinia'
import { ref, watch, computed } from 'vue'
import axios from 'axios'
import echo from '../echo'
import { useChartStore } from './useChartStore'

export const useRealtimeStore = defineStore('realtime', () => {
  const connected = ref(false)
  const lastUpdate = ref(null)
  const subscribedChannels = ref([])
  const pollingInterval = ref(null)
  const wsActive = ref(false)
  const fetchCount = ref(0)

  // Track when overlays were last received via WebSocket (to avoid redundant HTTP fetches)
  let lastOverlayWsTime = 0

  // Market status from backend
  const marketStatus = ref(null)
  const marketOpen = computed(() => marketStatus.value?.open ?? true)
  const marketMessage = computed(() => marketStatus.value?.message ?? '')
  const marketType = computed(() => marketStatus.value?.marketType ?? 'unknown')
  const nextMarketOpen = computed(() => marketStatus.value?.nextOpen ?? null)

  const chartStore = useChartStore()

  // Stale if no update in 90 seconds (rule #1: warn if >90s)
  // But only show stale warning if market is open
  const isStale = computed(() => {
    if (!marketOpen.value) return false // Market closed — not stale, just closed
    if (!lastUpdate.value) return true
    return Date.now() - lastUpdate.value.getTime() > 90000
  })

  const secondsSinceUpdate = computed(() => {
    if (!lastUpdate.value) return null
    return Math.floor((Date.now() - lastUpdate.value.getTime()) / 1000)
  })

  /**
   * Check if overlays were recently received via WebSocket.
   * Used to skip redundant HTTP overlay fetches.
   */
  function overlaysReceivedViaWsRecently(withinMs = 5000) {
    return (Date.now() - lastOverlayWsTime) < withinMs
  }

  /**
   * Fetch market status from backend for current symbol.
   * Determines if market is open, session info, next open time.
   */
  async function fetchMarketStatus() {
    if (!chartStore.activeSymbolId) return
    try {
      const { data } = await axios.get('/api/v1/chart/market-status', {
        params: { symbol_id: chartStore.activeSymbolId },
      })
      marketStatus.value = data
      console.log(`[Realtime] Market status: ${data.marketType} — ${data.open ? 'OPEN' : 'CLOSED'} — ${data.message}`)
    } catch (err) {
      console.warn('[Realtime] Market status fetch failed:', err.message)
      marketStatus.value = null
    }
  }

  /**
   * Sanitize symbol ticker for use in broadcast channel names.
   * Reverb/Pusher channel names cannot contain spaces.
   * e.g. "NIFTY BANK" -> "nifty-bank"
   */
  function sanitizeChannel(sym) {
    return sym.replace(/\s+/g, '-').toLowerCase()
  }

  /**
   * Subscribe to all WebSocket channels for the active symbol.
   */
  function subscribe(symbol, timeframe) {
    unsubscribeAll()

    if (!symbol) return

    // Reset WS overlay timestamp on new subscription
    lastOverlayWsTime = 0

    // Fetch market status first
    fetchMarketStatus()

    const safeSymbol = sanitizeChannel(symbol)

    // Candle updates
    const candleChannel = `candles.${safeSymbol}.${timeframe}`
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
    const signalChannel = `signals.${safeSymbol}`
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
    const waveChannel = `waves.${safeSymbol}`
    try {
      echo.channel(waveChannel)
        .listen('WaveUpdated', () => {
          lastUpdate.value = new Date()
        })
      subscribedChannels.value.push(waveChannel)
    } catch (err) {
      console.warn('[Realtime] Wave channel failed:', err.message)
    }

    // Overlay updates — full payload pushed from RunEnginesJob via Redis cache
    const overlayChannel = `overlays.${safeSymbol}.${timeframe}`
    try {
      echo.channel(overlayChannel)
        .listen('OverlaysUpdated', (e) => {
          if (e.overlays) {
            chartStore.overlays = e.overlays
            lastOverlayWsTime = Date.now()
            lastUpdate.value = new Date()
            console.log(`[WS] OverlaysUpdated via Reverb: ${e.timeframe} @ ${e.computedAt}`)
          }
        })
      subscribedChannels.value.push(overlayChannel)
    } catch (err) {
      console.warn('[Realtime] Overlay channel failed:', err.message)
    }

    connected.value = true

    // Start polling (respects market hours)
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
   * Smart polling — adapts interval based on market type:
   * - Crypto: 30s (24/7, always active)
   * - NSE: 30s during market hours, 5min outside (just for status refresh)
   * - Forex: 30s during sessions, 5min on weekends
   */
  function startPolling() {
    stopPolling()

    // Immediate first poll
    pollLatestCandles().then(() => { fetchCount.value++ }).catch(() => {})

    pollingInterval.value = setInterval(async () => {
      try {
        await pollLatestCandles()
        fetchCount.value++

        // Refresh market status every 5 minutes (market might open/close)
        if (fetchCount.value % 10 === 0) {
          await fetchMarketStatus()
        }
      } catch (err) {
        console.warn('[Realtime] Poll failed:', err.message)
      }
    }, 30000) // 30 seconds — backend handles market hours check
  }

  function stopPolling() {
    if (pollingInterval.value) {
      clearInterval(pollingInterval.value)
      pollingInterval.value = null
    }
  }

  /**
   * Fetch latest candles via the market-specific LiveFeed endpoint.
   * Backend handles market hours, IST/UTC conversion, and returns
   * DB candles if market is closed.
   *
   * Skips overlay HTTP fetch if overlays were recently pushed via WebSocket
   * to avoid redundant double-fetch (WS push + HTTP poll both triggering overlays).
   */
  async function pollLatestCandles() {
    if (!chartStore.activeSymbolId || !chartStore.activeTimeframe) return

    const { data: newCandles } = await axios.get('/api/v1/chart/candles/latest', {
      params: {
        symbol_id: chartStore.activeSymbolId,
        timeframe: chartStore.activeTimeframe,
        limit: 10,
      },
    })

    if (!newCandles || newCandles.length === 0) return

    // Merge: update last candle if same timestamp, append new ones
    const existing = chartStore.candles
    let updated = false
    for (const nc of newCandles) {
      const existingIdx = existing.findIndex(c => c.timestamp === nc.timestamp)
      if (existingIdx >= 0) {
        const old = existing[existingIdx]
        if (old.close !== nc.close || old.high !== nc.high || old.low !== nc.low || old.volume !== nc.volume) {
          existing[existingIdx] = nc
          updated = true
        }
      } else {
        existing.push(nc)
        updated = true
      }
    }

    if (updated) {
      chartStore.candles = [...existing]
      lastUpdate.value = new Date()

      // Only fetch overlays via HTTP if WebSocket hasn't delivered them recently.
      // When Reverb is working, OverlaysUpdated arrives ~1-2s after candle update,
      // so a 10s window is generous enough to avoid double-fetch.
      if (!overlaysReceivedViaWsRecently(10000)) {
        await chartStore.fetchOverlays()
        console.log('[Poll] Overlays fetched via HTTP (no recent WS delivery)')
      }
    }
  }

  /**
   * Handle incoming WebSocket candle update — append or update the last candle.
   * Overlays will arrive separately via OverlaysUpdated event from RunEnginesJob.
   * Only schedule an HTTP overlay fetch as fallback if WS overlay doesn't arrive
   * within 5 seconds.
   */
  let overlayRefreshTimer = null
  function scheduleOverlayRefresh() {
    if (overlayRefreshTimer) clearTimeout(overlayRefreshTimer)
    // Wait 5s — if OverlaysUpdated hasn't arrived via WS by then, fetch via HTTP
    overlayRefreshTimer = setTimeout(() => {
      if (!overlaysReceivedViaWsRecently(5000)) {
        chartStore.fetchOverlays()
        console.log('[WS] Overlays fallback HTTP fetch (WS overlay not received in 5s)')
      }
    }, 5000)
  }

  function onCandleUpdate(event) {
    const candle = event.candle
    if (!candle) return

    const candles = chartStore.candles
    if (!candles.length) return

    const lastCandle = candles[candles.length - 1]
    const newTimestamp = candle.timestamp

    if (lastCandle.timestamp === newTimestamp) {
      Object.assign(candles[candles.length - 1], candle)
    } else if (newTimestamp > lastCandle.timestamp) {
      candles.push(candle)
    }

    chartStore.candles = [...candles]
    lastUpdate.value = new Date()
    console.log(`[WS] CandleUpdated via Reverb: ${event.timeframe} @ ${newTimestamp}`)

    scheduleOverlayRefresh()
  }

  function onSignalUpdate() {
    // Signals are part of the overlay payload pushed via OverlaysUpdated.
    // Only fetch if WS overlay hasn't arrived recently.
    if (!overlaysReceivedViaWsRecently(5000)) {
      chartStore.fetchOverlays()
    }
  }

  async function forceRefresh() {
    await fetchMarketStatus()
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
    marketStatus,
    marketOpen,
    marketMessage,
    marketType,
    nextMarketOpen,
    subscribe,
    unsubscribeAll,
    forceRefresh,
    fetchMarketStatus,
    startPolling,
    stopPolling,
  }
})
