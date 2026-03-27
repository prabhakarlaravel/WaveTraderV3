import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import axios from 'axios'

export const useChartStore = defineStore('chart', () => {
  const symbols = ref([])
  const activeSymbolId = ref(null)
  const activeTimeframe = ref('1H')
  const candles = ref([])
  const overlays = ref({ signals: [], orderBlocks: [], fvgs: [] })
  const loading = ref(false)

  const activeSymbol = computed(() =>
    symbols.value.find((s) => s.id === activeSymbolId.value)
  )

  const formattedCandles = computed(() =>
    candles.value.map((c) => ({
      time: Math.floor(new Date(c.timestamp).getTime() / 1000),
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
        time: Math.floor(new Date(c.timestamp).getTime() / 1000),
        value: parseFloat(c.volume),
        color: close >= open ? 'rgba(38, 166, 154, 0.3)' : 'rgba(239, 83, 80, 0.3)',
      }
    })
  )

  async function fetchSymbols() {
    const { data } = await axios.get('/api/v1/chart/symbols')
    symbols.value = data
    if (data.length && !activeSymbolId.value) {
      activeSymbolId.value = data[0].id
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
    fetchCandles()
  }

  function setSymbol(id) {
    activeSymbolId.value = id
    fetchCandles()
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
  }
})
