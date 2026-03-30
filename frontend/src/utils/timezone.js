/**
 * Timezone utility — all chart timestamps displayed in IST (UTC+5:30).
 *
 * Lightweight-charts expects Unix timestamps in the "display timezone".
 * DB stores UTC. We convert: UTC epoch + IST offset → IST epoch for display.
 *
 * IST = UTC + 5h 30m = +19800 seconds
 */

export const IST_OFFSET = 19800 // +5:30 in seconds

/**
 * Convert a DB timestamp string (UTC) to a Unix epoch in IST for lightweight-charts.
 *
 * @param {string} ts  — DB timestamp, e.g. "2026-03-27 09:15:00" or "2026-03-27T09:15:00Z"
 * @returns {number}   — Unix epoch shifted to IST (what lightweight-charts displays)
 */
export function toISTEpoch(ts) {
  const utcStr = ts.endsWith('Z') ? ts : String(ts).replace(' ', 'T') + 'Z'
  return Math.floor(new Date(utcStr).getTime() / 1000) + IST_OFFSET
}
