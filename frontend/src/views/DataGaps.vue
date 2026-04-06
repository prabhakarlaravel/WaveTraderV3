<script setup>
import { ref, reactive, onMounted, computed, watch } from 'vue'
import axios from 'axios'
import SymbolSelector from '../components/shared/SymbolSelector.vue'
import { useChartStore } from '../stores/useChartStore'

const chartStore = useChartStore()
const scanResult = ref(null)
const scanning = ref(false)
const activeTf = ref('1M')
const fillingTf = ref('')
const fillQueue = ref([])       // [ { tf, status, pct, filled, total, elapsed } ]
const activityLog = ref([])

// Use chartStore as the single source of truth for symbols & selected symbol
const symbols = computed(() => chartStore.symbols)
const selectedSymbol = computed({
  get: () => chartStore.activeSymbolId,
  set: (v) => {
    chartStore.activeSymbolId = v
    try { localStorage.setItem('wt3_active_symbol', String(v)) } catch {}
  },
})

const tfOrder = ['1M', '5M', '15M', '1H', '4H', '1D']

onMounted(async () => {
  if (!chartStore.symbols.length) await chartStore.fetchSymbols()
  if (chartStore.activeSymbolId) await smartScan()
})

// ── Activity log ─────────────────────────────────────────────────────────────
function addLog(msg, color = '#10b981') {
  activityLog.value.unshift({ msg, color, time: new Date().toLocaleTimeString() })
  if (activityLog.value.length > 40) activityLog.value.pop()
}

// ── Scan ─────────────────────────────────────────────────────────────────────
async function smartScan() {
  if (!selectedSymbol.value) return
  scanning.value = true
  fillQueue.value = []
  addLog('Scanning 6 timeframes…', '#8b5cf6')
  try {
    const { data } = await axios.post('/api/v1/gaps/scan', { symbol_id: selectedSymbol.value })
    scanResult.value = data
    addLog(`Scan complete — ${data.totalGaps} gap${data.totalGaps !== 1 ? 's' : ''} found`, '#10b981')
  } catch (e) {
    addLog(`Scan failed: ${e.response?.data?.message || e.message}`, '#ef4444')
  } finally {
    scanning.value = false
  }
}

// ── Fill single TF ───────────────────────────────────────────────────────────
async function fillGap(tf) {
  if (!selectedSymbol.value || fillingTf.value) return
  fillingTf.value = tf

  const tfData = scanResult.value?.timeframes?.[tf]
  const totalMissing = tfData?.gaps?.reduce((s, g) => s + g.missingCandles, 0) || 0

  // Add to queue
  const qi = fillQueue.value.findIndex(q => q.tf === tf)
  const qItem = { tf, status: 'filling', pct: 0, filled: 0, total: totalMissing, elapsed: '0s', startTime: Date.now() }
  if (qi >= 0) fillQueue.value[qi] = qItem
  else fillQueue.value.push(qItem)

  addLog(`${tf} — filling ${totalMissing} candles…`, '#3b82f6')

  // Progress ticker
  const timer = setInterval(() => {
    const q = fillQueue.value.find(q => q.tf === tf)
    if (q?.status === 'filling') {
      q.elapsed = ((Date.now() - q.startTime) / 1000).toFixed(1) + 's'
      if (q.pct < 90) { q.pct = Math.min(90, q.pct + 2); q.filled = Math.floor(totalMissing * q.pct / 100) }
    }
  }, 200)

  try {
    const { data } = await axios.post('/api/v1/gaps/fill', { symbol_id: selectedSymbol.value, timeframe: tf })
    clearInterval(timer)
    const q = fillQueue.value.find(q => q.tf === tf)
    const elapsed = ((Date.now() - q.startTime) / 1000).toFixed(1) + 's'
    if (data.success) {
      Object.assign(q, { status: 'done', pct: 100, filled: data.filled || totalMissing, elapsed })
      addLog(`✓ ${tf} — ${data.filled} candles filled in ${elapsed}`, '#10b981')
    } else {
      Object.assign(q, { status: 'error', elapsed })
      addLog(`✕ ${tf}: ${data.message}`, '#ef4444')
    }
    await smartScan()
  } catch (e) {
    clearInterval(timer)
    const q = fillQueue.value.find(q => q.tf === tf)
    if (q) q.status = 'error'
    addLog(`✕ ${tf} failed: ${e.response?.data?.message || e.message}`, '#ef4444')
  } finally {
    fillingTf.value = ''
  }
}

// ── Fix All ──────────────────────────────────────────────────────────────────
const fixingAll = ref(false)

async function fixAll() {
  if (!scanResult.value || fixingAll.value) return
  fixingAll.value = true

  const tfsWithGaps = tfOrder.filter(tf => (scanResult.value.timeframes[tf]?.gapCount ?? 0) > 0)
  if (!tfsWithGaps.length) {
    addLog('No gaps to fix!', '#10b981')
    fixingAll.value = false
    return
  }

  // Pre-populate queue
  fillQueue.value = tfsWithGaps.map(tf => {
    const totalMissing = scanResult.value.timeframes[tf]?.gaps?.reduce((s, g) => s + g.missingCandles, 0) || 0
    return { tf, status: 'queued', pct: 0, filled: 0, total: totalMissing, elapsed: '—', startTime: 0 }
  })

  addLog(`Fix All started — ${tfsWithGaps.length} timeframes queued`, '#8b5cf6')

  for (const tf of tfsWithGaps) {
    await fillGap(tf)
  }

  addLog('Fix All completed!', '#10b981')
  fixingAll.value = false
}

// ── Queue stats ──────────────────────────────────────────────────────────────
const queueTotal = computed(() => fillQueue.value.reduce((s, q) => s + q.total, 0))
const queueFilled = computed(() => fillQueue.value.reduce((s, q) => s + (q.status === 'done' ? q.total : q.filled), 0))
const queuePct = computed(() => queueTotal.value > 0 ? Math.round(queueFilled.value / queueTotal.value * 100) : 0)

// ── Calendar heatmap ─────────────────────────────────────────────────────────
const calendarMonths = computed(() => {
  if (!scanResult.value) return []

  const tf = activeTf.value
  const tfData = scanResult.value.timeframes?.[tf]
  if (!tfData) return []

  // If no candles at all, treat ALL trading days as gaps
  const noData = (tfData.totalCandles || 0) === 0

  // Build separate sets: holidays (full_day + 0 existing candles) vs real gaps
  const holidayDates = new Set()
  const gapDates = new Set()
  const todayKey = (() => {
    const n = new Date()
    return `${n.getFullYear()}-${String(n.getMonth()+1).padStart(2,'0')}-${String(n.getDate()).padStart(2,'0')}`
  })()

  if (!noData) {
    for (const gap of (tfData.gaps || [])) {
      // Extract IST date from gap (use 'date' field if available from NSE detector)
      const gapDate = gap.date || null
      const isHoliday = gap.gapType === 'full_day' && (gap.existingCandles === 0 || gap.existingCandles === undefined)

      if (gapDate) {
        // NSE detector provides a single date per gap
        if (gapDate === todayKey) {
          gapDates.add(gapDate) // Today shown as gap (market not yet open), not holiday
        } else if (isHoliday) {
          holidayDates.add(gapDate)
        } else {
          gapDates.add(gapDate)
        }
      } else {
        // Crypto/Forex: range-based gaps
        const start = new Date(gap.gapStart)
        const end = new Date(gap.gapEnd)
        const d = new Date(start)
        while (d <= end) {
          gapDates.add(`${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`)
          d.setDate(d.getDate() + 1)
        }
      }
    }
  }

  // Generate 3 months of calendar data
  const now = new Date()
  const months = []
  for (let m = 2; m >= 0; m--) {
    const dt = new Date(now.getFullYear(), now.getMonth() - m, 1)
    const year = dt.getFullYear()
    const month = dt.getMonth()
    const daysInMonth = new Date(year, month + 1, 0).getDate()
    const firstDow = dt.getDay() // 0=Sun
    const offset = firstDow === 0 ? 6 : firstDow - 1 // Mon-start

    const label = dt.toLocaleString('en', { month: 'short', year: 'numeric' })
    const days = []

    // Blank cells for offset
    for (let b = 0; b < offset; b++) days.push({ blank: true })

    for (let d = 1; d <= daysInMonth; d++) {
      const date = new Date(year, month, d)
      const dow = date.getDay()
      const isWeekend = dow === 0 || dow === 6
      const key = `${year}-${String(month+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`
      const isFuture = date > now

      // Market-type aware weekend handling:
      // - Crypto (24/7): weekends are normal trading days, check for gaps
      // - NSE: weekends are always closed (no trading)
      // - Forex: Sat closed, Sun opens at 22:00 UTC — treat as weekend
      const mkt = (scanResult.value?.marketType || '').toLowerCase()
      const isCrypto = mkt === '24/7' || mkt === 'crypto'
      const isForex = mkt === 'forex'
      const weekendIsClosed = !isCrypto // NSE + Forex have weekend closures

      let status = 'ok'
      if (isFuture) status = 'future'
      else if (isWeekend && weekendIsClosed) status = 'weekend'
      else if (holidayDates.has(key)) status = 'holiday'
      else if (noData || gapDates.has(key)) status = 'gap'

      days.push({ d, status, key, date })
    }

    months.push({ label, days })
  }

  return months
})

// ── TF gap counts for pills ─────────────────────────────────────────────────
function tfGapCount(tf) {
  return scanResult.value?.timeframes?.[tf]?.gapCount ?? 0
}
function tfHealth(tf) {
  return scanResult.value?.timeframes?.[tf]?.healthPct ?? 100
}
function tfCandles(tf) {
  return scanResult.value?.timeframes?.[tf]?.totalCandles ?? 0
}
function tfMissing(tf) {
  return (scanResult.value?.timeframes?.[tf]?.gaps || []).reduce((s, g) => s + g.missingCandles, 0)
}

// Helpers
function queueStatusColor(s) {
  if (s === 'done') return '#10b981'
  if (s === 'filling') return '#3b82f6'
  if (s === 'error') return '#ef4444'
  return '#475569'
}
</script>

<template>
  <div class="gaps-page">

    <!-- Header -->
    <div class="gaps-header">
      <div>
        <h1 class="gaps-title">Data Gaps</h1>
        <p class="gaps-subtitle">Calendar heatmap — see data completeness at a glance</p>
      </div>
      <div class="gaps-actions">
        <SymbolSelector :symbols="symbols" v-model="selectedSymbol" @change="smartScan()" compact />
        <button @click="smartScan" :disabled="scanning" class="btn btn-scan">
          <svg v-if="scanning" class="btn-spin" viewBox="0 0 16 16" width="12" height="12"><circle cx="8" cy="8" r="6" fill="none" stroke="currentColor" stroke-width="2" stroke-dasharray="20 18" stroke-linecap="round"/></svg>
          {{ scanning ? 'Scanning…' : 'Scan' }}
        </button>
        <button @click="fixAll" :disabled="!scanResult?.totalGaps || fixingAll || !!fillingTf" class="btn btn-fix">
          ⚡ Fix All Gaps
        </button>
      </div>
    </div>

    <div v-if="scanResult" class="gaps-grid">

      <!-- ═══ LEFT COLUMN ═══ -->
      <div class="gaps-left">

        <!-- TF Pills -->
        <div class="tf-pills">
          <button v-for="tf in tfOrder" :key="tf"
            @click="activeTf = tf"
            :class="['tf-pill', { active: activeTf === tf }]">
            {{ tf }}
            <span v-if="tfGapCount(tf) > 0" class="tf-pill-badge">{{ tfGapCount(tf) }}</span>
          </button>
        </div>

        <!-- Calendar Heatmap -->
        <div class="cal-card">
          <div class="cal-header">
            <h3 class="cal-title">📅 {{ activeTf }} Data Heatmap</h3>
            <div class="cal-legend">
              <span class="cal-leg"><span class="cal-dot" style="background:rgba(16,185,129,0.4)"></span>Complete</span>
              <span class="cal-leg"><span class="cal-dot" style="background:rgba(56,189,248,0.45);border:1px solid rgba(56,189,248,0.3)"></span>Holiday</span>
              <span class="cal-leg"><span class="cal-dot" style="background:rgba(239,68,68,0.55)"></span>Missing</span>
              <span v-if="(scanResult?.marketType || '').toLowerCase() !== '24/7'" class="cal-leg"><span class="cal-dot" style="background:rgba(99,102,241,0.08);border:1px solid #2d2b3d"></span>Weekend</span>
              <span class="cal-leg"><span class="cal-dot" style="background:rgba(71,85,105,0.15)"></span>Future</span>
            </div>
          </div>

          <!-- Months -->
          <div class="cal-months">
            <div v-for="m in calendarMonths" :key="m.label" class="cal-month">
              <div class="cal-month-label">{{ m.label }}</div>
              <div class="cal-day-names">
                <span v-for="dn in ['M','T','W','T','F','S','S']" :key="dn" class="cal-dn">{{ dn }}</span>
              </div>
              <div class="cal-days">
                <template v-for="(day, i) in m.days" :key="i">
                  <div v-if="day.blank" class="cal-cell cal-blank"></div>
                  <div v-else
                    :class="['cal-cell', 'cal-' + day.status]"
                    :title="day.status === 'holiday' ? day.key + ' — HOLIDAY' : day.status === 'gap' ? day.key + ' — MISSING' : day.status === 'weekend' ? day.key + ' (Weekend)' : day.key + ' — Complete'">
                    <span v-if="day.status === 'gap'" class="cal-gap-mark">✕</span>
                    <span v-else-if="day.status === 'holiday'" class="cal-holiday-mark">H</span>
                  </div>
                </template>
              </div>
            </div>
          </div>

          <!-- Stats bar -->
          <div class="cal-stats">
            <div class="cal-stat">
              <div class="cal-stat-label">Trading Days</div>
              <div class="cal-stat-val">{{ calendarMonths.reduce((s,m) => s + m.days.filter(d => !d.blank && d.status !== 'weekend' && d.status !== 'future').length, 0) }}</div>
            </div>
            <div class="cal-stat">
              <div class="cal-stat-label">Complete</div>
              <div class="cal-stat-val" style="color:#10b981">{{ calendarMonths.reduce((s,m) => s + m.days.filter(d => d.status === 'ok').length, 0) }}</div>
            </div>
            <div class="cal-stat">
              <div class="cal-stat-label">Holidays</div>
              <div class="cal-stat-val" style="color:#38bdf8">{{ calendarMonths.reduce((s,m) => s + m.days.filter(d => d.status === 'holiday').length, 0) }}</div>
            </div>
            <div class="cal-stat">
              <div class="cal-stat-label">Gaps</div>
              <div class="cal-stat-val" style="color:#ef4444">{{ calendarMonths.reduce((s,m) => s + m.days.filter(d => d.status === 'gap').length, 0) }}</div>
            </div>
            <div class="cal-stat">
              <div class="cal-stat-label">Health</div>
              <div class="cal-stat-val" :style="{ color: tfHealth(activeTf) >= 95 ? '#10b981' : tfHealth(activeTf) >= 80 ? '#f59e0b' : '#ef4444' }">
                {{ tfHealth(activeTf) }}%
              </div>
            </div>
          </div>
        </div>

        <!-- TF Summary Grid -->
        <div class="tf-grid">
          <div v-for="tf in tfOrder" :key="tf"
            :class="['tf-card', { 'tf-card-ok': tfGapCount(tf) === 0 }]"
            @click="activeTf = tf"
            :style="{ borderColor: activeTf === tf ? '#6366f1' : tfGapCount(tf) > 0 ? 'rgba(239,68,68,0.25)' : '#2d2b3d' }">
            <div class="tf-card-name">{{ tf }}</div>
            <div class="tf-card-val" :style="{ color: tfGapCount(tf) > 0 ? '#ef4444' : '#10b981' }">
              {{ tfGapCount(tf) > 0 ? tfGapCount(tf) : '✓' }}
            </div>
            <div class="tf-card-sub">{{ tfGapCount(tf) > 0 ? tfGapCount(tf) + ' gaps' : 'Complete' }}</div>
            <div class="tf-card-bar">
              <div class="tf-card-bar-fill" :style="{ width: tfHealth(tf) + '%', background: tfGapCount(tf) > 0 ? '#ef4444' : '#10b981' }"></div>
            </div>
            <div class="tf-card-health" :style="{ color: tfHealth(tf) >= 95 ? '#10b981' : '#f59e0b' }">{{ tfHealth(tf) }}%</div>
          </div>
        </div>
      </div>

      <!-- ═══ RIGHT COLUMN ═══ -->
      <div class="gaps-right">

        <!-- Gap Repair Panel -->
        <div class="panel">
          <h3 class="panel-title">🔧 Gap Repair</h3>

          <!-- Queue items -->
          <div v-if="fillQueue.length" class="repair-list">
            <div v-for="q in fillQueue" :key="q.tf" class="repair-item"
              :style="{ borderColor: q.status === 'done' ? 'rgba(16,185,129,0.3)' : q.status === 'filling' ? 'rgba(59,130,246,0.3)' : '#1a1825' }">
              <span class="repair-tf">{{ q.tf }}</span>
              <div class="repair-info">
                <div class="repair-top">
                  <span class="repair-count">{{ q.total }} candles</span>
                  <span class="repair-status" :style="{ color: queueStatusColor(q.status) }">
                    {{ q.status === 'done' ? '✓ FILLED' : q.status === 'filling' ? '⟳ ' + q.pct + '%' : q.status === 'error' ? '✕ FAILED' : 'Queued' }}
                  </span>
                </div>
                <div class="repair-bar"><div class="repair-bar-fill" :style="{ width: q.pct + '%', background: q.status === 'done' ? '#10b981' : q.status === 'filling' ? 'linear-gradient(90deg,#2563eb,#3b82f6)' : '#334155' }"></div></div>
              </div>
            </div>
          </div>

          <!-- No queue -->
          <div v-else class="repair-empty">
            <p v-if="!scanResult?.totalGaps">✅ No gaps to repair!</p>
            <p v-else>Click <b>Fix All Gaps</b> or select a timeframe to start.</p>
          </div>

          <!-- Overall progress -->
          <div v-if="fillQueue.length" class="repair-overall">
            <div class="repair-overall-top">
              <span>Overall Progress</span>
              <span class="repair-overall-count">{{ queueFilled.toLocaleString() }} / {{ queueTotal.toLocaleString() }}</span>
            </div>
            <div class="repair-bar repair-bar-lg"><div class="repair-bar-fill" :style="{ width: queuePct + '%', background: 'linear-gradient(90deg,#059669,#10b981)' }"></div></div>
            <div class="repair-overall-pct">{{ queuePct }}%</div>
          </div>

          <!-- Per-TF fill buttons (when not using Fix All) -->
          <div v-if="!fixingAll && scanResult?.totalGaps" class="repair-buttons">
            <button v-for="tf in tfOrder.filter(t => tfGapCount(t) > 0)" :key="tf"
              @click="fillGap(tf)" :disabled="!!fillingTf"
              class="repair-btn">
              {{ tf }} <span class="repair-btn-count">{{ tfMissing(tf) }}</span>
            </button>
          </div>
        </div>

        <!-- Activity Log -->
        <div class="panel">
          <h3 class="panel-title">📝 Activity</h3>
          <div class="log-list">
            <div v-for="(log, i) in activityLog" :key="i" class="log-item">
              <span class="log-dot" :style="{ background: log.color }"></span>
              <div>
                <div class="log-msg" :style="{ color: log.color }">{{ log.msg }}</div>
                <div class="log-time">{{ log.time }}</div>
              </div>
            </div>
            <div v-if="!activityLog.length" class="log-empty">No activity yet</div>
          </div>
        </div>

        <!-- Market Info -->
        <div class="panel panel-sm">
          <div class="market-row">
            <span class="market-label">Market</span>
            <span class="market-val" style="color:#6366f1">{{ scanResult.marketType || 'Unknown' }}</span>
          </div>
          <div class="market-row" style="margin-top:6px">
            <span class="market-label">Weekends</span>
            <span class="market-val" :style="{ color: (scanResult.marketType || '').toLowerCase() === '24/7' ? '#f59e0b' : '#6366f1' }">
              {{ (scanResult.marketType || '').toLowerCase() === '24/7' ? 'Included (24/7 market)' : 'Excluded from gaps' }}
            </span>
          </div>
        </div>
      </div>
    </div>

    <!-- Loading overlay — shown on initial scan or re-scan -->
    <div v-if="scanning" class="scan-overlay" :class="{ 'scan-overlay-full': !scanResult }">
      <div class="scan-card">
        <svg class="scan-spinner" viewBox="0 0 50 50">
          <circle class="scan-spinner-track" cx="25" cy="25" r="20" fill="none" stroke-width="4" />
          <circle class="scan-spinner-arc" cx="25" cy="25" r="20" fill="none" stroke-width="4"
            stroke-linecap="round" stroke-dasharray="80 120" />
        </svg>
        <div class="scan-text">
          <div class="scan-title">Scanning 6 timeframes</div>
          <div class="scan-sub">Analyzing <span class="scan-sym">{{ chartStore.activeSymbol?.ticker || 'symbol' }}</span> for data gaps…</div>
          <div class="scan-dots"><span /><span /><span /></div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* ── Page ────────────────────────────────────────────────────── */
.gaps-page { padding: 16px; max-width: 1200px; margin: 0 auto; }

/* ── Header ──────────────────────────────────────────────────── */
.gaps-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
.gaps-title { font-size: 22px; font-weight: 800; color: var(--text); margin: 0; }
.gaps-subtitle { font-size: 12px; color: var(--dim); margin: 4px 0 0; }
.gaps-actions { display: flex; gap: 8px; align-items: center; }

.btn { padding: 6px 14px; border-radius: 8px; border: none; font-size: 11px; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 5px; }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-scan { background: #2563eb; color: #fff; }
.btn-fix { background: #059669; color: #fff; }
.btn-spin { animation: scanRotate 0.8s linear infinite; }

/* ── Grid ────────────────────────────────────────────────────── */
.gaps-grid { display: grid; grid-template-columns: 1fr 300px; gap: 16px; }

/* ── TF Pills ────────────────────────────────────────────────── */
.tf-pills { display: flex; gap: 4px; margin-bottom: 14px; padding: 4px; background: var(--card); border-radius: 10px; border: 1px solid var(--border); width: fit-content; }
.tf-pill { display: flex; align-items: center; gap: 4px; padding: 5px 16px; border-radius: 7px; border: none; background: transparent; color: var(--dim); font-size: 11px; font-weight: 700; cursor: pointer; font-family: var(--mono); }
.tf-pill:hover { color: var(--text); background: var(--surface); }
.tf-pill.active { background: #6366f1; color: #fff; }
.tf-pill-badge { font-size: 8px; background: rgba(239,68,68,0.8); color: #fff; padding: 1px 5px; border-radius: 3px; font-weight: 800; }
.tf-pill.active .tf-pill-badge { background: rgba(255,255,255,0.25); }

/* ── Calendar Card ───────────────────────────────────────────── */
.cal-card { background: var(--card); border: 1px solid var(--border); border-radius: 14px; padding: 20px; }
.cal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
.cal-title { font-size: 13px; font-weight: 700; color: var(--text); margin: 0; }
.cal-legend { display: flex; gap: 12px; align-items: center; }
.cal-leg { display: flex; align-items: center; gap: 4px; font-size: 9px; color: var(--dim); }
.cal-dot { width: 10px; height: 10px; border-radius: 2px; }

.cal-months { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; }
.cal-month-label { font-size: 11px; font-weight: 700; color: #94a3b8; text-align: center; margin-bottom: 8px; }
.cal-day-names { display: grid; grid-template-columns: repeat(7, 1fr); gap: 3px; margin-bottom: 4px; }
.cal-dn { text-align: center; font-size: 8px; color: #475569; font-weight: 600; }
.cal-days { display: grid; grid-template-columns: repeat(7, 1fr); gap: 3px; }

.cal-cell {
  width: 100%; aspect-ratio: 1; border-radius: 3px; display: flex;
  align-items: center; justify-content: center; cursor: pointer;
  transition: transform 0.1s; font-size: 7px; font-weight: 700;
  min-height: 16px;
}
.cal-cell:hover { transform: scale(1.25); z-index: 5; }
.cal-blank { background: transparent; cursor: default; }
.cal-blank:hover { transform: none; }
.cal-ok { background: rgba(16,185,129,0.35); }
.cal-gap { background: rgba(239,68,68,0.5); border: 1px solid rgba(239,68,68,0.4); }
.cal-holiday { background: rgba(56,189,248,0.35); border: 1px solid rgba(56,189,248,0.25); }
.cal-weekend { background: rgba(99,102,241,0.06); }
.cal-future { background: rgba(71,85,105,0.1); }
.cal-gap-mark { color: #fca5a5; font-size: 8px; }
.cal-holiday-mark { color: #7dd3fc; font-size: 7px; font-weight: 800; }

/* Stats bar */
.cal-stats { margin-top: 16px; display: flex; gap: 12px; justify-content: center; }
.cal-stat { text-align: center; padding: 8px 16px; background: var(--surface); border-radius: 8px; min-width: 80px; }
.cal-stat-label { font-size: 8px; color: var(--dim); text-transform: uppercase; letter-spacing: 1px; }
.cal-stat-val { font-size: 18px; font-weight: 900; color: var(--text); }

/* ── TF Summary Grid ─────────────────────────────────────────── */
.tf-grid { display: grid; grid-template-columns: repeat(6, 1fr); gap: 8px; margin-top: 12px; }
.tf-card {
  background: var(--card); border: 1px solid var(--border); border-radius: 10px;
  padding: 10px; text-align: center; cursor: pointer; transition: border-color 0.15s;
}
.tf-card:hover { border-color: #6366f1; }
.tf-card-name { font-size: 12px; font-weight: 800; color: var(--text); font-family: var(--mono); }
.tf-card-val { font-size: 18px; font-weight: 900; margin: 4px 0; }
.tf-card-sub { font-size: 8px; color: var(--dim); }
.tf-card-bar { margin-top: 6px; height: 3px; border-radius: 2px; background: var(--surface); overflow: hidden; }
.tf-card-bar-fill { height: 100%; border-radius: 2px; transition: width 0.3s; }
.tf-card-health { font-size: 8px; font-weight: 700; margin-top: 3px; }

/* ── Right panel ─────────────────────────────────────────────── */
.panel { background: var(--card); border: 1px solid var(--border); border-radius: 14px; padding: 16px; margin-bottom: 12px; }
.panel-sm { padding: 12px; }
.panel-title { font-size: 12px; font-weight: 700; color: var(--text); margin: 0 0 12px; }

/* Repair list */
.repair-list { display: flex; flex-direction: column; gap: 6px; }
.repair-item {
  display: flex; align-items: center; gap: 8px;
  padding: 8px 10px; border-radius: 8px; background: var(--surface);
  border: 1px solid transparent; transition: border-color 0.2s;
}
.repair-tf { font-size: 11px; font-weight: 800; color: var(--text); font-family: var(--mono); width: 24px; flex-shrink: 0; }
.repair-info { flex: 1; }
.repair-top { display: flex; justify-content: space-between; margin-bottom: 3px; }
.repair-count { font-size: 9px; color: #94a3b8; }
.repair-status { font-size: 9px; font-weight: 700; }
.repair-bar { height: 3px; border-radius: 2px; background: var(--surface); overflow: hidden; }
.repair-bar-lg { height: 6px; border-radius: 3px; }
.repair-bar-fill { height: 100%; border-radius: inherit; transition: width 0.3s; }
.repair-empty { font-size: 11px; color: var(--dim); text-align: center; padding: 16px 0; }
.repair-overall { margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--border); }
.repair-overall-top { display: flex; justify-content: space-between; margin-bottom: 4px; font-size: 10px; color: #94a3b8; }
.repair-overall-count { font-weight: 800; color: #10b981; }
.repair-overall-pct { text-align: right; margin-top: 3px; font-size: 9px; color: #10b981; font-weight: 700; }

.repair-buttons { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--border); }
.repair-btn {
  display: flex; align-items: center; gap: 4px;
  padding: 4px 10px; border-radius: 6px; border: 1px solid var(--border);
  background: var(--surface); color: var(--text); font-size: 10px;
  font-weight: 700; cursor: pointer; font-family: var(--mono);
}
.repair-btn:hover { border-color: #6366f1; }
.repair-btn:disabled { opacity: 0.4; cursor: not-allowed; }
.repair-btn-count { font-size: 8px; background: rgba(239,68,68,0.2); color: #ef4444; padding: 1px 4px; border-radius: 3px; }

/* Log */
.log-list { max-height: 200px; overflow-y: auto; }
.log-list::-webkit-scrollbar { width: 3px; }
.log-list::-webkit-scrollbar-thumb { background: var(--border); border-radius: 2px; }
.log-item { display: flex; gap: 8px; align-items: flex-start; margin-bottom: 8px; }
.log-dot { width: 5px; height: 5px; border-radius: 50%; margin-top: 5px; flex-shrink: 0; }
.log-msg { font-size: 10px; font-weight: 600; }
.log-time { font-size: 8px; color: var(--dim); }
.log-empty { font-size: 10px; text-align: center; padding: 16px; color: var(--dim); }

/* Market info */
.market-row { display: flex; justify-content: space-between; align-items: center; font-size: 10px; }
.market-label { font-weight: 700; color: #94a3b8; }
.market-val { font-weight: 700; }

/* ── Scan overlay ────────────────────────────────────────────── */
.scan-overlay {
  position: fixed; inset: 0; z-index: 100;
  display: flex; align-items: center; justify-content: center;
  background: rgba(10, 10, 20, 0.55); backdrop-filter: blur(4px);
  animation: scanFadeIn 0.2s ease;
}
.scan-overlay-full { background: rgba(10, 10, 20, 0.8); }

.scan-card {
  display: flex; align-items: center; gap: 20px;
  background: var(--card); border: 1px solid var(--border);
  border-radius: 16px; padding: 24px 36px;
  box-shadow: 0 8px 32px rgba(0,0,0,0.4);
  animation: scanSlideUp 0.3s ease;
}

/* Spinner */
.scan-spinner { width: 44px; height: 44px; flex-shrink: 0; animation: scanRotate 1s linear infinite; }
.scan-spinner-track { stroke: rgba(99,102,241,0.15); }
.scan-spinner-arc { stroke: #6366f1; }

/* Text */
.scan-title { font-size: 14px; font-weight: 800; color: var(--text); }
.scan-sub { font-size: 11px; color: var(--dim); margin-top: 4px; }
.scan-sym { color: #6366f1; font-weight: 700; font-family: var(--mono); }

/* Bouncing dots */
.scan-dots { display: flex; gap: 4px; margin-top: 8px; }
.scan-dots span {
  width: 5px; height: 5px; border-radius: 50%; background: #6366f1;
  animation: scanBounce 1.2s ease-in-out infinite;
}
.scan-dots span:nth-child(2) { animation-delay: 0.15s; }
.scan-dots span:nth-child(3) { animation-delay: 0.3s; }

@keyframes scanFadeIn { from { opacity: 0; } to { opacity: 1; } }
@keyframes scanSlideUp { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
@keyframes scanRotate { to { transform: rotate(360deg); } }
@keyframes scanBounce {
  0%, 80%, 100% { opacity: 0.3; transform: scale(0.8); }
  40% { opacity: 1; transform: scale(1.3); }
}
</style>
