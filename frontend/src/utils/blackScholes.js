/**
 * Client-side Black-Scholes pricing for live options P&L estimation.
 *
 * This mirrors the backend BlackScholesService but runs in the browser
 * so we can recalculate premiums every second without API calls.
 */

const RISK_FREE_RATE = 0.065 // RBI repo rate
const SECONDS_PER_YEAR = 365 * 24 * 3600

/**
 * Standard normal CDF — Abramowitz & Stegun approximation.
 */
function normalCdf(x) {
  const a1 = 0.254829592
  const a2 = -0.284496736
  const a3 = 1.421413741
  const a4 = -1.453152027
  const a5 = 1.061405429
  const p = 0.3275911

  const sign = x < 0 ? -1 : 1
  const absX = Math.abs(x)
  const t = 1.0 / (1.0 + p * absX)

  const y = 1.0 - (a1 * t + a2 * t * t + a3 * t ** 3 + a4 * t ** 4 + a5 * t ** 5) *
    Math.exp(-absX * absX / 2.0)

  return 0.5 * (1.0 + sign * y)
}

/**
 * Calculate Black-Scholes option premium.
 *
 * @param {number} spot - Current underlying price
 * @param {number} strike - Strike price
 * @param {string} type - 'CE' or 'PE'
 * @param {string} expiryDate - 'YYYY-MM-DD' format
 * @param {number} iv - Implied volatility (default 0.15 = 15%)
 * @returns {number} Theoretical premium
 */
export function calcPremium(spot, strike, type, expiryDate, iv = 0.15) {
  if (!spot || !strike || !expiryDate) return 0

  const now = new Date()
  // Expiry at 15:30 IST = 10:00 UTC
  const expiry = new Date(expiryDate + 'T10:00:00Z')
  const diffSec = (expiry - now) / 1000
  const T = Math.max(diffSec / SECONDS_PER_YEAR, 1 / SECONDS_PER_YEAR) // min 1 second

  const r = RISK_FREE_RATE
  const sqrtT = Math.sqrt(T)

  const d1 = (Math.log(spot / strike) + (r + iv * iv / 2) * T) / (iv * sqrtT)
  const d2 = d1 - iv * sqrtT

  if (type === 'CE') {
    return Math.max(0, spot * normalCdf(d1) - strike * Math.exp(-r * T) * normalCdf(d2))
  } else {
    return Math.max(0, strike * Math.exp(-r * T) * normalCdf(-d2) - spot * normalCdf(-d1))
  }
}

/**
 * Estimate the current premium for an open options trade, given the latest spot price.
 * Uses the trade's stored strike, option_type, and expiry with a default IV.
 *
 * @param {Object} trade - Open trade object with strike, option_type, expiry
 * @param {number} currentSpot - Current underlying spot price
 * @returns {number} Estimated current premium
 */
export function estimateLivePremium(trade, currentSpot) {
  if (!trade || !trade.strike || !trade.option_type || !trade.expiry || !currentSpot) {
    return null
  }
  return Math.round(calcPremium(currentSpot, trade.strike, trade.option_type, trade.expiry) * 100) / 100
}
