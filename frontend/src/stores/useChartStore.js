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
  const overlaysLoading = ref(false) // separate flag for overlay/wave loading indicator

  const activeSymbol = computed(() =>
    symbols.value.find((s) => s.id === activeSymbolId.value)
  )

  // Cached formatted arrays — only reformat when candles actually change.
  // Incremental path updates only the tail, then returns a NEW shallow copy
  // so Vue's watch() detects the change (Object.is check on array reference).
  let _cachedCandles = []
  let _cachedVolume = []
  let _cachedLength = 0

  const formattedCandles = computed(() => {
    const raw = candles.value
    const len = raw.length
    if (len === 0) { _cachedCandles = []; _cachedLength = 0; return [] }

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
      // Incremental: append new candles
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
      // Always update the last candle (forming candle with new close/high/low)
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
    // Return a new shallow copy so Vue watch() detects the change.
    // This is O(n) pointer copy (~0.1ms for 5000 candles) — NOT a deep clone.
    return [..._cachedCandles]
  })

  const formattedVolume = computed(() => {
    const raw = candles.value
    const len = raw.length
    if (len === 0) { _cachedVolume = []; return [] }

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
    return [..._cachedVolume]
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

  const _emptyOverlays = { signals: [], orderBlocks: [], fvgs: [] }

  async function fetchCandles() {
    if (!activeSymbolId.value) return
    loading.value = true
    overlaysLoading.value = true
    // Bug fix #1: Clear old overlays immediately so stale data from previous symbol
    // is never rendered on the new symbol's chart
    overlays.value = { ..._emptyOverlays }
    try {
      const { data } = await axios.get('/api/v1/chart/candles', {
        params: {
          symbol_id: activeSymbolId.value,
          timeframe: activeTimeframe.value,
        },
      })
      candles.value = data
      loading.value = false
      // Fire overlays fetch without awaiting — don't block chart render.
      // Overlays will load in background and update reactively.
      fetchOverlays()
    } catch (err) {
      loading.value = false
      throw err
    } finally {
      // loading already set above
    }
  }

  let _overlayRetryTimer = null
  let _overlayFetchSymbolId = null // Bug fix #2: track which symbol the retry chain belongs to

  async function fetchOverlays(retryCount = 0) {
    if (!activeSymbolId.value) return

    // On first call (not a retry), record the symbol and cancel stale retry chains
    if (retryCount === 0) {
      if (_overlayRetryTimer) clearTimeout(_overlayRetryTimer)
      _overlayRetryTimer = null
      _overlayFetchSymbolId = activeSymbolId.value
      overlaysLoading.value = true
    }

    // Bug fix #2: If symbol changed since retry chain started, abort this stale retry
    if (_overlayFetchSymbolId !== activeSymbolId.value) {
      console.log('[Overlay] Stale retry aborted — symbol changed')
      overlaysLoading.value = false
      return
    }

    try {
      const { data } = await axios.get('/api/v1/chart/overlays', {
        params: {
          symbol_id: activeSymbolId.value,
          timeframe: activeTimeframe.value,
        },
      })

      // Backend runs engines synchronously on cache miss, so this should
      // return real data. Only retry if it still says computing (edge case).
      if (data.computing && retryCount < 2) {
        const delay = 5000
        console.log(`[Overlay] Still computing — retry #${retryCount + 1} in ${delay/1000}s`)
        if (_overlayRetryTimer) clearTimeout(_overlayRetryTimer)
        _overlayRetryTimer = setTimeout(() => fetchOverlays(retryCount + 1), delay)
        return
      }

      // Always set overlays (even if still computing after retries exhausted)
      overlays.value = data
      // Clear loading if we got real data OR all retries are done
      const hasRealData = (data.waveLabels?.length > 0 || data.signals?.length > 0 || data.confluence)
      if (hasRealData || !data.computing) {
        overlaysLoading.value = false
      }
    } catch {
      overlays.value = { ..._emptyOverlays }
      overlaysLoading.value = false
    }
  }

  function setTimeframe(tf) {
    activeTimeframe.value = tf
    try { localStorage.setItem(PERSIST_TF_KEY, tf) } catch {}
    fetchCandles()
  }

  function setSymbol(id) {
    // Bug fix #2: Cancel any pending overlay retry from previous symbol
    if (_overlayRetryTimer) { clearTimeout(_overlayRetryTimer); _overlayRetryTimer = null }
    // Bug fix #4: Clear stale confluence from previous symbol
    mtfConfluence.value = null
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

  /**
   * mtfConfluence: populated by WaveMatrixPanel from the mtf-waves endpoint.
   * Used as fallback when overlays.confluence is null (cache miss / computing).
   * This ensures bottom bias cards in LiveChart always have data.
   */
  const mtfConfluence = ref(null)

  function setMtfConfluence(c) {
    mtfConfluence.value = c
  }

  return {
    symbols,
    activeSymbolId,
    activeSymbol,
    activeTimeframe,
    candles,
    loading,
    overlaysLoading,
    formattedCandles,
    formattedVolume,
    overlays,
    mtfConfluence,
    fetchSymbols,
    fetchCandles,
    fetchOverlays,
    setTimeframe,
    setSymbol,
    ensureSymbolReady,
    setMtfConfluence,
  }
})
