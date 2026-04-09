import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { useChartStore } from './useChartStore'
import { estimateLivePremium } from '../utils/blackScholes'

const STORAGE_KEY = 'wt3_paper_trades'
const CAPITAL_KEY = 'wt3_virtual_capital'
const CURRENCY_KEY = 'wt3_virtual_currency'

export const useTradeStore = defineStore('trade', () => {
  const trades = ref([])
  const loading = ref(false)

  // ---------------------------------------------------------------------------
  // Virtual Account System
  // ---------------------------------------------------------------------------
  const virtualCapital = ref(
    (() => { try { const v = localStorage.getItem(CAPITAL_KEY); return v ? parseFloat(v) : 500000 } catch { return 500000 } })()
  )
  const currency = ref(
    (() => { try { return localStorage.getItem(CURRENCY_KEY) || 'INR' } catch { return 'INR' } })()
  )

  function setVirtualCapital(amount, curr) {
    virtualCapital.value = parseFloat(amount)
    if (curr) currency.value = curr
    try {
      localStorage.setItem(CAPITAL_KEY, String(virtualCapital.value))
      localStorage.setItem(CURRENCY_KEY, currency.value)
    } catch { /* ignore */ }
  }

  const usedMargin = computed(() =>
    openTrades.value.reduce((sum, t) => {
      const qty = _effectiveQty(t)
      if (t.instrument_type === 'options' && t.premium != null) {
        return sum + parseFloat(t.premium) * qty
      }
      return sum + parseFloat(t.entry_price) * qty
    }, 0)
  )

  const availableMargin = computed(() => virtualCapital.value - usedMargin.value)

  const dailyPnl = computed(() => {
    const todayStr = new Date().toISOString().slice(0, 10)
    return closedTrades.value
      .filter(t => t.closed_at && t.closed_at.slice(0, 10) === todayStr)
      .reduce((sum, t) => sum + (t.pnl || 0), 0)
  })

  // Peak-equity drawdown: track the running peak of cumulative P&L, drawdown
  // is the maximum distance from any peak to a subsequent trough.
  const drawdown = computed(() => {
    let cumulative = 0
    let peak = 0
    let maxDrawdown = 0
    for (const t of closedTrades.value) {
      cumulative += (t.pnl || 0)
      if (cumulative > peak) peak = cumulative
      const dd = peak - cumulative
      if (dd > maxDrawdown) maxDrawdown = dd
    }
    return maxDrawdown
  })

  // ---------------------------------------------------------------------------
  // Persistence
  // ---------------------------------------------------------------------------
  function loadTrades() {
    try {
      const saved = localStorage.getItem(STORAGE_KEY)
      if (saved) trades.value = JSON.parse(saved)
    } catch { /* ignore */ }
  }

  function persist() {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(trades.value))
  }

  // ---------------------------------------------------------------------------
  // Filtered views
  // ---------------------------------------------------------------------------
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
    let equity = virtualCapital.value
    return closedTrades.value.map(t => {
      equity += (t.pnl || 0)
      return equity
    })
  })

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  /**
   * Effective quantity accounting for lot_size on options trades.
   */
  function _effectiveQty(trade) {
    const qty = parseFloat(trade.quantity) || 0
    if (trade.instrument_type === 'options' && trade.lot_size) {
      return qty * parseFloat(trade.lot_size)
    }
    return qty
  }

  /**
   * Calculate P&L for a trade at a given exit price / premium.
   * For options trades using premiums, for others using raw prices.
   */
  function _calcPnl(trade, exitPrice, exitPremium) {
    const qty = _effectiveQty(trade)

    // Options: buying a PUT or CALL is always a long position — P&L = (exit - entry) premium
    if (trade.instrument_type === 'options' && trade.premium != null && exitPremium != null) {
      return (exitPremium - parseFloat(trade.premium)) * qty
    }
    // Equity/Crypto/Forex: use type-based multiplier
    const mult = trade.type === 'long' ? 1 : -1
    return (exitPrice - parseFloat(trade.entry_price)) * mult * qty
  }

  /**
   * Calculate unrealized P&L for a single open trade at current spot price.
   * For options: estimates the current premium via Black-Scholes from spot price.
   * For equity/crypto/forex: uses spot price directly.
   */
  function calcUnrealizedPnl(trade, currentSpotPrice) {
    if (!currentSpotPrice || trade.status !== 'open') return 0

    if (trade.instrument_type === 'options' && trade.premium != null && trade.strike) {
      const livePremium = estimateLivePremium(trade, currentSpotPrice)
      if (livePremium != null) {
        return _calcPnl(trade, currentSpotPrice, livePremium)
      }
      // Fallback: intrinsic value only
      const intrinsic = trade.option_type === 'CE'
        ? Math.max(0, currentSpotPrice - trade.strike)
        : Math.max(0, trade.strike - currentSpotPrice)
      return _calcPnl(trade, currentSpotPrice, intrinsic)
    }

    return _calcPnl(trade, currentSpotPrice, null)
  }

  /**
   * Total unrealized P&L across all open trades at given spot price.
   */
  function totalUnrealizedPnl(currentSpotPrice) {
    return openTrades.value.reduce((sum, t) => sum + calcUnrealizedPnl(t, currentSpotPrice), 0)
  }

  // ---------------------------------------------------------------------------
  // Risk Calculator
  // ---------------------------------------------------------------------------

  /**
   * Calculate position size based on risk parameters.
   * @param {number} riskAmount - Total capital willing to risk
   * @param {number} entryPrice - Planned entry price
   * @param {number} slPrice - Stop-loss price
   * @param {number} lotSize - Lot size multiplier (default 1)
   * @param {number|null} tpPrice - Optional take-profit price for RR calculation
   * @returns {{ quantity: number, lots: number, riskPerUnit: number, totalRisk: number, rr: number|null }}
   */
  function calculateQuantity(riskAmount, entryPrice, slPrice, lotSize = 1, tpPrice = null) {
    const riskPerUnit = Math.abs(entryPrice - slPrice)
    if (riskPerUnit === 0) return { quantity: 0, lots: 0, riskPerUnit: 0, totalRisk: 0, rr: null }

    const rawQty = Math.floor(riskAmount / riskPerUnit)
    const lots = lotSize > 1 ? Math.floor(rawQty / lotSize) : rawQty
    const quantity = lots * lotSize

    let rr = null
    if (tpPrice != null) {
      const reward = Math.abs(tpPrice - entryPrice)
      rr = reward / riskPerUnit
      rr = Math.round(rr * 100) / 100
    }

    return {
      quantity,
      lots: lotSize > 1 ? lots : quantity,
      riskPerUnit: Math.round(riskPerUnit * 100) / 100,
      totalRisk: Math.round(riskPerUnit * quantity * 100) / 100,
      rr,
    }
  }

  // ---------------------------------------------------------------------------
  // Open Trade (with options & trailing stop support)
  // ---------------------------------------------------------------------------

  /**
   * Open a new paper trade (localStorage, no API needed).
   * Accepts optional options fields: strike, option_type, expiry, premium,
   * lot_size, instrument_type, trailing_stop.
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

      // Options fields
      strike: payload.strike != null ? parseFloat(payload.strike) : null,
      option_type: payload.option_type || null,       // 'CE' or 'PE'
      expiry: payload.expiry || null,                  // ISO date string
      premium: payload.premium != null ? parseFloat(payload.premium) : null,
      lot_size: payload.lot_size != null ? parseInt(payload.lot_size, 10) : null,
      instrument_type: payload.instrument_type || 'equity', // equity | options | crypto | forex

      // Trailing stop fields
      trailing_stop: payload.trailing_stop != null ? parseFloat(payload.trailing_stop) : null,
      trailing_high: payload.trailing_stop != null ? parseFloat(payload.entry_price) : null,
    }
    trades.value.unshift(trade)
    persist()
    return trade
  }

  // ---------------------------------------------------------------------------
  // Close Trade
  // ---------------------------------------------------------------------------

  /**
   * Close a trade at given exit (spot) price.
   * For options: estimates exit premium from spot price via Black-Scholes
   * unless exitPremium is explicitly provided.
   */
  function closeTrade(tradeId, exitSpotPrice, exitPremium = null) {
    const idx = trades.value.findIndex(t => t.id === tradeId)
    if (idx === -1) return null

    const trade = trades.value[idx]

    // For options: compute exit premium from spot if not given
    let finalExitPremium = exitPremium
    if (trade.instrument_type === 'options' && trade.premium != null && finalExitPremium == null) {
      finalExitPremium = estimateLivePremium(trade, exitSpotPrice)
    }

    const pnl = _calcPnl(trade, exitSpotPrice, finalExitPremium)

    const closed = {
      ...trade,
      exit_price: exitSpotPrice,
      exit_premium: finalExitPremium,
      status: 'closed',
      pnl: Math.round(pnl * 100) / 100,
      closed_at: new Date().toISOString(),
    }
    // Use splice to guarantee Vue reactivity triggers
    trades.value.splice(idx, 1, closed)
    persist()
    return closed
  }

  /**
   * Close all open positions at the current price.
   */
  function closeAllTrades(exitSpotPrice) {
    const results = []
    // Close in reverse to avoid index shifting issues
    for (let i = trades.value.length - 1; i >= 0; i--) {
      if (trades.value[i].status === 'open') {
        const result = closeTrade(trades.value[i].id, exitSpotPrice)
        if (result) results.push(result)
      }
    }
    return results
  }

  // ---------------------------------------------------------------------------
  // Update Trade
  // ---------------------------------------------------------------------------

  /**
   * Update a trade's SL/TP, notes, or any other mutable fields.
   */
  function updateTrade(tradeId, payload) {
    const idx = trades.value.findIndex(t => t.id === tradeId)
    if (idx === -1) return null
    trades.value[idx] = { ...trades.value[idx], ...payload }
    persist()
    return trades.value[idx]
  }

  // ---------------------------------------------------------------------------
  // Check Stops (with trailing stop support)
  // ---------------------------------------------------------------------------

  /**
   * Check if any open trades hit SL or TP at given price.
   * Updates trailing stops before checking SL/TP.
   * Returns array of auto-closed trades.
   */
  function checkStops(currentPrice) {
    const closed = []
    let dirty = false

    for (const trade of openTrades.value) {
      const idx = trades.value.findIndex(t => t.id === trade.id)
      if (idx === -1) continue

      // PUT options profit when price drops — invert direction for stop logic
      const isLongDir = trade.option_type === 'PE' ? false : (trade.type === 'long')

      // --- Trailing stop logic ---
      if (trade.trailing_stop != null && trade.trailing_stop > 0) {
        if (isLongDir) {
          // Track highest price for longs / CALL options
          if (currentPrice > (trade.trailing_high || trade.entry_price)) {
            trades.value[idx] = {
              ...trades.value[idx],
              trailing_high: currentPrice,
              sl: currentPrice - trade.trailing_stop,
            }
            dirty = true
          }
        } else {
          // Track lowest price for shorts / PUT options
          const trailingRef = trade.trailing_high || trade.entry_price
          if (currentPrice < trailingRef) {
            trades.value[idx] = {
              ...trades.value[idx],
              trailing_high: currentPrice,
              sl: currentPrice + trade.trailing_stop,
            }
            dirty = true
          }
        }
      }

      // Re-read trade after potential trailing update
      const t = trades.value[idx]

      // --- SL / TP check ---
      if (isLongDir) {
        if (t.sl && currentPrice <= t.sl) {
          closed.push(closeTrade(t.id, t.sl))
        } else if (t.tp && currentPrice >= t.tp) {
          closed.push(closeTrade(t.id, t.tp))
        }
      } else {
        if (t.sl && currentPrice >= t.sl) {
          closed.push(closeTrade(t.id, t.sl))
        } else if (t.tp && currentPrice <= t.tp) {
          closed.push(closeTrade(t.id, t.tp))
        }
      }
    }

    if (dirty) persist()
    return closed.filter(Boolean)
  }

  // ---------------------------------------------------------------------------
  // Signal-to-Trade Bridge
  // ---------------------------------------------------------------------------

  /**
   * Create a trade from a confluence signal object.
   * @param {Object} signal - Must have callPut ('BUY CALL' | 'BUY PUT'), adjustedPct, etc.
   * @param {number} riskAmount - Amount of capital to risk on this trade
   * @param {Object} [overrides] - Optional field overrides (sl, tp, lot_size, expiry, etc.)
   * @returns {Object} The created trade
   */
  function createFromSignal(signal, riskAmount, overrides = {}) {
    const chartStore = useChartStore()

    // Determine direction and option type from signal
    const isBuyCall = signal.callPut === 'BUY CALL'
    const type = 'long' // Buying options (CALL or PUT) is always a long position
    const optionType = isBuyCall ? 'CE' : 'PE'

    // Get current price from chart store (last candle close)
    const candles = chartStore.candles
    const lastCandle = candles.length ? candles[candles.length - 1] : null
    const currentPrice = lastCandle ? parseFloat(lastCandle.close) : 0

    // Extract SL/TP from signal or overrides
    const sl = overrides.sl != null ? parseFloat(overrides.sl) : (signal.sl != null ? parseFloat(signal.sl) : null)
    const tp = overrides.tp != null ? parseFloat(overrides.tp) : (signal.tp != null ? parseFloat(signal.tp) : null)

    // Calculate quantity using risk calculator
    const lotSize = overrides.lot_size || signal.lot_size || 1
    let quantity = 1
    if (sl != null && riskAmount > 0) {
      const calc = calculateQuantity(riskAmount, currentPrice, sl, lotSize, tp)
      quantity = lotSize > 1 ? calc.lots : calc.quantity
    }

    return openTrade({
      symbol_id: chartStore.activeSymbolId,
      symbol_ticker: chartStore.activeSymbol?.ticker || '',
      type,
      entry_price: currentPrice,
      quantity,
      sl,
      tp,
      engine: signal.engine || 'confluence',
      timeframe: signal.timeframe || chartStore.activeTimeframe,
      confluence_score: signal.adjustedPct || signal.confluenceScore || null,
      auto_trade: true,
      notes: `Auto from signal: ${signal.callPut}`,
      tags: ['signal-trade'],

      // Options fields
      option_type: optionType,
      strike: overrides.strike || signal.strike || null,
      expiry: overrides.expiry || signal.expiry || null,
      premium: overrides.premium || signal.premium || null,
      lot_size: lotSize > 1 ? lotSize : null,
      instrument_type: overrides.instrument_type || signal.instrument_type || 'equity',

      // Trailing stop
      trailing_stop: overrides.trailing_stop || null,
    })
  }

  // ---------------------------------------------------------------------------
  // Delete / Clear
  // ---------------------------------------------------------------------------

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
    // State
    trades, loading,
    virtualCapital, currency,

    // Computed — trade views
    openTrades, closedTrades,
    totalPnl, winRate, equityCurve,

    // Computed — account
    usedMargin, availableMargin, dailyPnl, drawdown,

    // P&L helpers
    calcUnrealizedPnl, totalUnrealizedPnl,

    // Trade actions
    openTrade, closeTrade, closeAllTrades, updateTrade, checkStops,
    deleteTrade, clearAll, loadTrades, persist,

    // Virtual account
    setVirtualCapital,

    // Risk calculator
    calculateQuantity,

    // Signal bridge
    createFromSignal,
  }
})
