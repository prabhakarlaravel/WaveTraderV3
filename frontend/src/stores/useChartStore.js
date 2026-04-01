import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import axios from 'axios'
import { toISTEpoch } from '../utils/timezone'

const PERSIST_KEY = 'wt3_active_symbol'
const PERSIST_TF_KEY = 'wt3_active_timeframe'
const DEFAULT_SYMBOL_TICKER = 'NIFTY BANK' // Default instrument when no persisted selection

export const useChartStore = defineStore('chart', () => {
  const symbols = ref([])
  const activeSymbolId = ref(
    (() => { try { const v = localStorage.getItem(PERSIST_KEY); return v ? Number(v) : null } catch { return null } })()
  )
  const activeTimeframe = ref(
    (() => { try { return localStorage.getItem(PERSIST_TF_KEY) || '5M' } catch { return '5M' } })()
  )
  const candles = ref([])
  const overlays = ref({ signals: [], orderBlocks: [], fvgs: [] })
  const loading = ref(false)

  const activeSymbol = computed(() =>
    symbols.value.find((s) => s.id === activeSymbolId.value)
  )

  // Cached formatted arrays — only reformat when candles actually change
  // Uses a generation counter to avoid full O(n) remap on every live tick
  let _cachedCandles = []
  let _cachedVolume = []
  let _cachedLength = 0
  let _cachedGeneration = 0

  const formattedCandles = computed(() => {
    const raw = candles.value
    const len = raw.length
    if (len === 0) { _cachedCandles = []; _cachedLength = 0; return _cachedCandles }

    // Full remap only when array size changes significantly (symbol/TF switch)
    if (Math.abs(len - _cachedLength) > 2 || _cachedLength === 0) {
      _cachedCandles = raw.map((c) => ({
        time: toISTEpoch(c.timestamp),
        open: parseFloat(c.open),
        high: parseFloat(c.high),
        low: parseFloat(c.low),
        close: parseFloat(c.close),
      }))
      _cachedLength = len
    } else {
      // Incremental: update only the last candle (live tick) and append new ones
      while (_cachedCandles.length < len) {
        const c = raw[_cachedCandles.length]
        _cachedCandles.push({
          time: toISTEpoch(c.timestamp),
          open: parseFloat(c.open),
          high: parseFloat(c.high),
          low: parseFloat(c.low),
          close: parseFloat(c.close),
        })
      }
      // Always update the last candle (forming candle)
      const last = raw[len - 1]
      _cachedCandles[len - 1] = {
        time: toISTEpoch(last.timestamp),
        open: parseFloat(last.open),
        high: parseFloat(last.high),
        low: parseFloat(last.low),
        close: parseFloat(last.close),
      }
      _cachedLength = len
    }
    return _cachedCandles
  })

  const formattedVolume = computed(() => {
    const raw = candles.value
    const len = raw.length
    if (len === 0) { _cachedVolume = []; return _cachedVolume }

    if (Math.abs(len - _cachedVolume.length) > 2 || _cachedVolume.length === 0) {
      _cachedVolume = raw.map((c) => {
        const open = parseFloat(c.open)
        const close = parseFloat(c.close)
        return {
          time: toISTEpoch(c.timestamp),
          value: parseFloat(c.volume),
          color: close >= open ? 'rgba(38, 166, 154, 0.3)' : 'rgba(239, 83, 80, 0.3)',
        }
      })
    } else {
      while (_cachedVolume.length < len) {
        const c = raw[_cachedVolume.length]
        const open = parseFloat(c.open)
        const close = parseFloat(c.close)
        _cachedVolume.push({
          time: toISTEpoch(c.timestamp),
          value: parseFloat(c.volume),
          color: close >= open ? 'rgba(38, 166, 154, 0.3)' : 'rgba(239, 83, 80, 0.3)',
        })
      }
      const last = raw[len - 1]
      const open = parseFloat(last.open)
      const close = parseFloat(last.close)
      _cachedVolume[len - 1] = {
        time: toISTEpoch(last.timestamp),
        value: parseFloat(last.volume),
        color: close >= open ? 'rgba(38, 166, 154, 0.3)' : 'rgba(239, 83, 80, 0.3)',
      }
    }
    return _cachedVolume
  })

  async function fetchSymbols() {
    const { data } = await axios.get('/api/v1/chart/symbols')
    symbols.value = data
    if (data.length) {
      // Validate persisted symbol still exists, otherwise fall back to NIFTY BANK → first
      const persisted = activeSymbolId.value
      const valid = persisted && data.some(s => s.id === persisted)
      if (!valid) {
        const defaultSymbol = data.find(s => s.ticker === DEFAULT_SYMBOL_TICKER)
        const fallbackId = defaultSymbol ? defaultSymbol.id : data[0].id
        activeSymbolId.value = fallbackId
        try { localStorage.setItem(PERSIST_KEY, String(fallbackId)) } catch {}
      }
    }
  }

  async function fetchCandles() {
    if (!activeSymbolId.value) return
    loading.value = true
    try {
      const { data } = await axios.get('/api/v1/chart/candles', {
        params: {
          symbol_id: activeSymbolId.value,
          timeframe: activeTimeframe.value,
        },
      })
      candles.value = data
      await fetchOverlays()
    } finally {
      loading.value = false
    }
  }

  let _overlayRetryTimer = null

  async function fetchOverlays(retryCount = 0) {
    if (!activeSymbolId.value) return
    try {
      const { data } = await axios.get('/api/v1/chart/overlays', {
        params: {
          symbol_id: activeSymbolId.value,
          timeframe: activeTimeframe.value,
        },
      })

      // Backend returns { computing: true } on cache miss — engines are running in background.
      // Retry once after 3s to pick up the freshly computed overlays.
      if (data.computing && retryCount < 2) {
        console.log(`[Overlay] Cache miss — engines computing, retry #${retryCount + 1} in 3s`)
        if (_overlayRetryTimer) clearTimeout(_overlayRetryTimer)
        _overlayRetryTimer = setTimeout(() => fetchOverlays(retryCount + 1), 3000)
        return
      }

      overlays.value = data
    } catch {
      overlays.value = { signals: [], orderBlocks: [], fvgs: [] }
    }
  }

  function setTimeframe(tf) {
    activeTimeframe.value = tf
    try { localStorage.setItem(PERSIST_TF_KEY, tf) } catch {}
    fetchCandles()
  }

  function setSymbol(id) {
    activeSymbolId.value = id
    try { localStorage.setItem(PERSIST_KEY, String(id)) } catch {}
    fetchCandles()
    // Auto-check gaps and fill in background (fire-and-forget)
    ensureSymbolReady(id)
  }

  /**
   * Auto-detect & fill gaps + trigger engine run for a symbol.
   * Runs in background — does not block UI.
   */
  async function ensureSymbolReady(symbolId) {
    if (!symbolId) return
    try {
      // 1. Scan for gaps
      const { data: scan } = await axios.post('/api/v1/gaps/scan', { symbol_id: symbolId })
      if (!scan?.totalGaps || scan.totalGaps === 0) return // No gaps — all good

      // 2. Fill each TF that has gaps (sequentially to avoid overloading)
      const tfs = ['1M', '5M', '15M', '1H', '4H', '1D']
      for (const tf of tfs) {
        const tfData = scan.timeframes?.[tf]
        if (tfData && tfData.gapCount > 0) {
          try {
            await axios.post('/api/v1/gaps/fill', { symbol_id: symbolId, timeframe: tf })
          } catch { /* gap fill failed — non-critical */ }
        }
      }

      // 3. Re-fetch candles + overlays with the now-filled data
      if (activeSymbolId.value === symbolId) {
        await fetchCandles()
      }
    } catch { /* background task — silent */ }
  }

  return {
    symbols,
    activeSymbolId,
    activeSymbol,
    activeTimeframe,
    candles,
    loading,
    formattedCandles,
    formattedVolume,
    overlays,
    fetchSymbols,
    fetchCandles,
    fetchOverlays,
    setTimeframe,
    setSymbol,
    ensureSymbolReady,
  }
})
