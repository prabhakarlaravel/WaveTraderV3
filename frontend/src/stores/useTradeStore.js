import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import axios from 'axios'

export const useTradeStore = defineStore('trade', () => {
  const trades = ref([])
  const analytics = ref(null)
  const loading = ref(false)

  const openTrades = computed(() => trades.value.filter((t) => t.status === 'open'))
  const closedTrades = computed(() => trades.value.filter((t) => t.status === 'closed'))

  const totalPnl = computed(() =>
    closedTrades.value.reduce((sum, t) => sum + parseFloat(t.pnl || 0), 0)
  )

  const winRate = computed(() => {
    const closed = closedTrades.value
    if (!closed.length) return 0
    const wins = closed.filter((t) => parseFloat(t.pnl || 0) > 0).length
    return Math.round((wins / closed.length) * 100)
  })

  const equityCurve = computed(() => {
    let equity = 10000
    return closedTrades.value.map((t) => {
      equity += parseFloat(t.pnl || 0)
      return equity
    })
  })

  async function fetchTrades(status = null) {
    loading.value = true
    try {
      const params = status ? { status } : {}
      const { data } = await axios.get('/api/v1/trades', { params })
      trades.value = data.data || data || []
    } finally {
      loading.value = false
    }
  }

  async function openTrade(payload) {
    const { data } = await axios.post('/api/v1/trades', payload)
    trades.value.unshift(data)
    return data
  }

  async function closeTrade(tradeId, exitPrice) {
    const { data } = await axios.put(`/api/v1/trades/${tradeId}`, {
      exit_price: exitPrice,
      status: 'closed',
    })
    const idx = trades.value.findIndex((t) => t.id === tradeId)
    if (idx !== -1) trades.value[idx] = data
    return data
  }

  async function updateTrade(tradeId, payload) {
    const { data } = await axios.put(`/api/v1/trades/${tradeId}`, payload)
    const idx = trades.value.findIndex((t) => t.id === tradeId)
    if (idx !== -1) trades.value[idx] = data
    return data
  }

  async function runAutoTrade(symbolId, timeframe, minConfluence = 60) {
    const { data } = await axios.post('/api/v1/trades/auto', {
      symbol_id: symbolId,
      timeframe,
      min_confluence: minConfluence,
    })
    if (data.trade) {
      trades.value.unshift(data.trade)
    }
    return data
  }

  async function fetchAnalytics() {
    const { data } = await axios.get('/api/v1/trades/analytics/summary')
    analytics.value = data
    return data
  }

  return {
    trades, analytics, loading, openTrades, closedTrades,
    totalPnl, winRate, equityCurve,
    fetchTrades, openTrade, closeTrade, updateTrade,
    runAutoTrade, fetchAnalytics,
  }
})
