import { defineStore } from 'pinia'
import { ref, watch } from 'vue'
import echo from '../echo'
import { useChartStore } from './useChartStore'

export const useRealtimeStore = defineStore('realtime', () => {
  const connected = ref(false)
  const lastUpdate = ref(null)
  const subscribedChannels = ref([])

  const chartStore = useChartStore()

  /**
   * Subscribe to all channels for the active symbol.
   */
  function subscribe(symbol, timeframe) {
    unsubscribeAll()

    if (!symbol) return

    // Candle updates
    const candleChannel = `candles.${symbol}.${timeframe}`
    echo.channel(candleChannel)
      .listen('CandleUpdated', (e) => {
        lastUpdate.value = new Date()
        onCandleUpdate(e)
      })
    subscribedChannels.value.push(candleChannel)

    // Signal updates
    const signalChannel = `signals.${symbol}`
    echo.channel(signalChannel)
      .listen('SignalGenerated', (e) => {
        lastUpdate.value = new Date()
        onSignalUpdate(e)
      })
      .listen('OrderBlockUpdated', (e) => {
        lastUpdate.value = new Date()
      })
      .listen('FVGUpdated', (e) => {
        lastUpdate.value = new Date()
      })
    subscribedChannels.value.push(signalChannel)

    // Wave updates
    const waveChannel = `waves.${symbol}`
    echo.channel(waveChannel)
      .listen('WaveUpdated', (e) => {
        lastUpdate.value = new Date()
      })
    subscribedChannels.value.push(waveChannel)

    connected.value = true
    console.log(`[Realtime] Subscribed to ${symbol} ${timeframe}`)
  }

  function unsubscribeAll() {
    subscribedChannels.value.forEach((ch) => {
      echo.leave(ch)
    })
    subscribedChannels.value = []
    connected.value = false
  }

  /**
   * Handle incoming candle update — append or update the last candle.
   */
  function onCandleUpdate(event) {
    const candle = event.candle
    if (!candle) return

    const candles = chartStore.candles
    if (!candles.length) return

    const lastCandle = candles[candles.length - 1]
    const newTimestamp = candle.timestamp

    if (lastCandle.timestamp === newTimestamp) {
      // Update existing candle
      Object.assign(candles[candles.length - 1], candle)
    } else {
      // Append new candle
      candles.push(candle)
    }

    // Trigger reactivity
    chartStore.candles = [...candles]
  }

  /**
   * Handle incoming signal — refresh overlays.
   */
  function onSignalUpdate() {
    chartStore.fetchOverlays()
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
    subscribe,
    unsubscribeAll,
  }
})
