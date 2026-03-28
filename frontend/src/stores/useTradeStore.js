import { defineStore } from 'pinia'
import { ref, computed } from 'vue'

const STORAGE_KEY = 'wt3_paper_trades'

export const useTradeStore = defineStore('trade', () => {
  const trades = ref([])
  const loading = ref(false)

  // Load from localStorage on init
  function loadTrades() {
    try {
      const saved = localStorage.getItem(STORAGE_KEY)
      if (saved) trades.value = JSON.parse(saved)
    } catch { /* ignore */ }
  }

  function persist() {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(trades.value))
  }

  // Filtered views
  const openTrades = computed(() => trades.value.filter(t => t.status === 'open'))
  const closedTrades = computed(() => trades.value.filter(t => t.status === 'closed'))

  // Realized P&L (closed trades only)
  const totalPnl = computed(() =>
    closedTrades.value.reduce((sum, t) => sum + (t.pnl || 0), 0)
  )

  const winRate = computed(() => {
    const closed = closedTrades.value
    if (!closed.length) return 0
    const wins = closed.filter(t => (t.pnl || 0) > 0).length
    return Math.round((wins / closed.length) * 100)
  })

  const equityCurve = computed(() => {
    let equity = 10000
    return closedTrades.value.map(t => {
      equity += (t.pnl || 0)
      return equity
    })
  })

  /**
   * Calculate unrealized P&L for a single open trade at a given price.
   */
  function calcUnrealizedPnl(trade, currentPrice) {
    if (!currentPrice || trade.status !== 'open') return 0
    const entry = parseFloat(trade.entry_price)
    const mult = trade.type === 'long' ? 1 : -1
    return (currentPrice - entry) * mult * parseFloat(trade.quantity)
  }

  /**
   * Total unrealized P&L across all open trades at given price.
   */
  function totalUnrealizedPnl(currentPrice) {
    return openTrades.value.reduce((sum, t) => sum + calcUnrealizedPnl(t, currentPrice), 0)
  }

  /**
   * Open a new paper trade (localStorage, no API needed).
   */
  function openTrade(payload) {
    const trade = {
      id: Date.now().toString(36) + Math.random().toString(36).slice(2, 6),
      symbol_id: payload.symbol_id,
      symbol_ticker: payload.symbol_ticker || '',
      type: payload.type,
      entry_price: parseFloat(payload.entry_price),
      exit_price: null,
      quantity: parseFloat(payload.quantity),
      sl: payload.sl ? parseFloat(payload.sl) : null,
      tp: payload.tp ? parseFloat(payload.tp) : null,
      status: 'open',
      pnl: null,
      notes: payload.notes || null,
      tags: payload.tags || [],
      engine: payload.engine || null,
      timeframe: payload.timeframe || null,
      wave_position: payload.wave_position || null,
      confluence_score: payload.confluence_score || null,
      auto_trade: payload.auto_trade || false,
      created_at: new Date().toISOString(),
      closed_at: null,
    }
    trades.value.unshift(trade)
    persist()
    return trade
  }

  /**
   * Close a trade at given exit price.
   */
  function closeTrade(tradeId, exitPrice) {
    const idx = trades.value.findIndex(t => t.id === tradeId)
    if (idx === -1) return null

    const trade = trades.value[idx]
    const mult = trade.type === 'long' ? 1 : -1
    const pnl = (exitPrice - trade.entry_price) * mult * trade.quantity

    trades.value[idx] = {
      ...trade,
      exit_price: exitPrice,
      status: 'closed',
      pnl: Math.round(pnl * 100) / 100,
      closed_at: new Date().toISOString(),
    }
    persist()
    return trades.value[idx]
  }

  /**
   * Update a trade's SL/TP or notes.
   */
  function updateTrade(tradeId, payload) {
    const idx = trades.value.findIndex(t => t.id === tradeId)
    if (idx === -1) return null
    trades.value[idx] = { ...trades.value[idx], ...payload }
    persist()
    return trades.value[idx]
  }

  /**
   * Check if any open trades hit SL or TP at given price.
   * Returns array of auto-closed trades.
   */
  function checkStops(currentPrice) {
    const closed = []
    for (const trade of openTrades.value) {
      if (trade.type === 'long') {
        if (trade.sl && currentPrice <= trade.sl) {
          closed.push(closeTrade(trade.id, trade.sl))
        } else if (trade.tp && currentPrice >= trade.tp) {
          closed.push(closeTrade(trade.id, trade.tp))
        }
      } else {
        if (trade.sl && currentPrice >= trade.sl) {
          closed.push(closeTrade(trade.id, trade.sl))
        } else if (trade.tp && currentPrice <= trade.tp) {
          closed.push(closeTrade(trade.id, trade.tp))
        }
      }
    }
    return closed.filter(Boolean)
  }

  /**
   * Delete a trade entirely.
   */
  function deleteTrade(tradeId) {
    trades.value = trades.value.filter(t => t.id !== tradeId)
    persist()
  }

  /**
   * Clear all trades.
   */
  function clearAll() {
    trades.value = []
    persist()
  }

  // Load on init
  loadTrades()

  return {
    trades, loading, openTrades, closedTrades,
    totalPnl, winRate, equityCurve,
    calcUnrealizedPnl, totalUnrealizedPnl,
    openTrade, closeTrade, updateTrade, checkStops,
    deleteTrade, clearAll, loadTrades, persist,
  }
})
