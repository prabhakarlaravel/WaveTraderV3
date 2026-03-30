import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import axios from 'axios'

const PERSIST_KEY = 'wt3_active_symbol'
const PERSIST_TF_KEY = 'wt3_active_timeframe'

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

  // Browser's local timezone offset. DB stores UTC; append 'Z' to force UTC parse,
  // then add offset so lightweight-charts displays in local time (IST, EST, etc.)
  const LOCAL_TZ_OFFSET = -(new Date().getTimezoneOffset()) * 60

  function toLocalEpoch(ts) {
    // Append 'Z' so JS parses as UTC, then shift to local timezone
    const utcStr = ts.endsWith('Z') ? ts : ts.replace(' ', 'T') + 'Z'
    return Math.floor(new Date(utcStr).getTime() / 1000) + LOCAL_TZ_OFFSET
  }

  const formattedCandles = computed(() =>
    candles.value.map((c) => ({
      time: toLocalEpoch(c.timestamp),
      open: parseFloat(c.open),
      high: parseFloat(c.high),
      low: parseFloat(c.low),
      close: parseFloat(c.close),
    }))
  )

  const formattedVolume = computed(() =>
    candles.value.map((c) => {
      const open = parseFloat(c.open)
      const close = parseFloat(c.close)
      return {
        time: toLocalEpoch(c.timestamp),
        value: parseFloat(c.volume),
        color: close >= open ? 'rgba(38, 166, 154, 0.3)' : 'rgba(239, 83, 80, 0.3)',
      }
    })
  )

  async function fetchSymbols() {
    const { data } = await axios.get('/api/v1/chart/symbols')
    symbols.value = data
    if (data.length) {
      // Validate persisted symbol still exists, otherwise fall back to first
      const persisted = activeSymbolId.value
      const valid = persisted && data.some(s => s.id === persisted)
      if (!valid) {
        activeSymbolId.value = data[0].id
        try { localStorage.setItem(PERSIST_KEY, String(data[0].id)) } catch {}
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

  async function fetchOverlays() {
    if (!activeSymbolId.value) return
    try {
      const { data } = await axios.get('/api/v1/chart/overlays', {
        params: {
          symbol_id: activeSymbolId.value,
          timeframe: activeTimeframe.value,
        },
      })
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
