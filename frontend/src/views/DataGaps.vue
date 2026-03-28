<script setup>
import { ref, reactive, onMounted, computed } from 'vue'
import axios from 'axios'

const symbols = ref([])
const timeframes = ['1M', '5M', '15M', '1H', '4H', '1D']
const loaded = ref(false)

// Per-symbol, per-TF scan state
const symbolStates = reactive({})
// { [symbolId]: { ticker, state: 'idle'|'scanning'|'filling'|'done', tfResults: { [tf]: { pct, gaps, state } } } }

const activityLog = ref([])
const isSmartScanning = ref(false)
const isAutoFilling = ref(false)

// Overall computed stats
const overallStats = computed(() => {
  let totalTfs = 0, scanned = 0, totalGaps = 0, filled = 0, remaining = 0
  for (const sym of Object.values(symbolStates)) {
    for (const tf of Object.values(sym.tfResults || {})) {
      totalTfs++
      if (tf.state !== 'idle') scanned++
      totalGaps += tf.gaps || 0
      filled += tf.filled || 0
      remaining += (tf.gaps || 0) - (tf.filled || 0)
    }
  }
  const pct = totalTfs > 0 ? Math.round(((totalTfs - remaining) / totalTfs) * 100) : 100
  return { symbols: symbols.value.length, totalTfs, scanned, totalGaps, filled, remaining: Math.max(0, remaining), pct }
})

onMounted(async () => {
  const { data } = await axios.get('/api/v1/chart/symbols')
  symbols.value = data
  for (const s of data) {
    symbolStates[s.id] = {
      ticker: s.ticker,
      state: 'idle',
      tfResults: {}
    }
    for (const tf of timeframes) {
      symbolStates[s.id].tfResults[tf] = { pct: 100, gaps: 0, filled: 0, state: 'idle' }
    }
  }
  loaded.value = true
})

function addLog(icon, msg) {
  const now = new Date()
  const time = now.toLocaleTimeString('en-IN', { hour12: false, hour: '2-digit', minute: '2-digit', second: '2-digit' })
  activityLog.value.unshift({ time, icon, msg, id: Date.now() })
  if (activityLog.value.length > 50) activityLog.value.pop()
}

async function smartScanAll() {
  if (isSmartScanning.value) return
  isSmartScanning.value = true
  addLog('🚀', `Smart Scan started — <span class="num">${symbols.value.length}</span> symbols × <span class="num">${timeframes.length}</span> timeframes`)

  for (const sym of symbols.value) {
    symbolStates[sym.id].state = 'scanning'
    let symHasGaps = false

    for (const tf of timeframes) {
      symbolStates[sym.id].tfResults[tf].state = 'scanning'
      addLog('🔍', `Scanning <span class="sym">${sym.ticker}</span> <span class="tf">${tf}</span>...`)

      try {
        const { data } = await axios.post('/api/v1/gaps/scan', { symbol_id: sym.id, timeframe: tf })
        const gapCount = data.gaps?.length || 0
        symbolStates[sym.id].tfResults[tf].gaps = gapCount
        symbolStates[sym.id].tfResults[tf].pct = gapCount === 0 ? 100 : Math.max(0, 100 - gapCount * 20)
        symbolStates[sym.id].tfResults[tf].state = gapCount > 0 ? 'has_gaps' : 'clean'

        if (gapCount > 0) {
          symHasGaps = true
          addLog('⚡', `Found <span class="num">${gapCount}</span> gaps in <span class="sym">${sym.ticker}</span> <span class="tf">${tf}</span>`)
        }
      } catch {
        symbolStates[sym.id].tfResults[tf].state = 'clean'
      }
    }

    symbolStates[sym.id].state = symHasGaps ? 'has_gaps' : 'done'
    if (!symHasGaps) {
      addLog('✅', `<span class="sym">${sym.ticker}</span> all timeframes <span class="ok">clean ✓</span>`)
    }
  }

  addLog('🏁', `Smart Scan complete — <span class="num">${overallStats.value.totalGaps}</span> gaps found across <span class="num">${overallStats.value.scanned}</span> scans`)
  isSmartScanning.value = false
}

async function autoFillAll() {
  if (isAutoFilling.value) return
  isAutoFilling.value = true
  addLog('🔧', `Auto-Fill started — fixing <span class="num">${overallStats.value.remaining}</span> remaining gaps`)

  for (const sym of symbols.value) {
    let hadGaps = false

    for (const tf of timeframes) {
      const tfState = symbolStates[sym.id].tfResults[tf]
      if (tfState.gaps > 0 && tfState.state === 'has_gaps') {
        hadGaps = true
        symbolStates[sym.id].state = 'filling'
        tfState.state = 'filling'
        addLog('⬇️', `Filling <span class="sym">${sym.ticker}</span> <span class="tf">${tf}</span>...`)

        try {
          await axios.post('/api/v1/gaps/fill', { symbol_id: sym.id, timeframe: tf })
          tfState.filled = tfState.gaps
          tfState.pct = 100
          tfState.state = 'clean'
          addLog('✅', `Filled <span class="num">${tfState.gaps}</span> candles for <span class="sym">${sym.ticker}</span> <span class="tf">${tf}</span> <span class="ok">→ 100%</span>`)
        } catch {
          tfState.state = 'has_gaps'
          addLog('❌', `Failed to fill <span class="sym">${sym.ticker}</span> <span class="tf">${tf}</span>`)
        }
      }
    }

    if (hadGaps) {
      symbolStates[sym.id].state = 'done'
    }
  }

  addLog('🏁', `Auto-Fill complete — all gaps resolved!`)
  isAutoFilling.value = false
}

function getSymbolBadge(sym) {
  const s = symbolStates[sym.id]
  if (!s) return { cls: 'badge-idle', text: '—' }
  if (s.state === 'scanning') return { cls: 'badge-scanning', text: 'Scanning...' }
  if (s.state === 'filling') return { cls: 'badge-filling', text: 'Filling...' }
  if (s.state === 'has_gaps') {
    const g = Object.values(s.tfResults).reduce((a, t) => a + (t.gaps - (t.filled || 0)), 0)
    return { cls: 'badge-gaps', text: `${g} gaps` }
  }
  if (s.state === 'done') return { cls: 'badge-ok', text: '✓ Clean' }
  return { cls: 'badge-idle', text: '—' }
}

function getTfBarClass(symId, tf) {
  const t = symbolStates[symId]?.tfResults?.[tf]
  if (!t) return 'idle'
  if (t.state === 'scanning') return 'scanning'
  if (t.state === 'filling') return 'filling'
  if (t.pct >= 100) return 'ok'
  return 'partial'
}

function getTfPct(symId, tf) {
  return symbolStates[symId]?.tfResults?.[tf]?.pct ?? 100
}

function getTfGapCount(symId, tf) {
  const t = symbolStates[symId]?.tfResults?.[tf]
  if (!t) return 0
  return Math.max(0, (t.gaps || 0) - (t.filled || 0))
}
</script>

<template>
  <div class="smart-gaps">
    <h1>Data Gaps</h1>
    <p class="sub">Smart scan &amp; auto-fill missing candle data across all symbols and timeframes</p>

    <!-- Action Bar -->
    <div class="action-bar">
      <button class="btn-smart" :class="{ scanning: isSmartScanning }" @click="smartScanAll" :disabled="isSmartScanning">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
        {{ isSmartScanning ? 'Scanning...' : 'Smart Scan All' }}
      </button>
      <button class="btn-fill" @click="autoFillAll" :disabled="isAutoFilling || overallStats.remaining === 0">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
        {{ isAutoFilling ? 'Filling...' : 'Auto-Fill All Gaps' }}
      </button>
      <div class="status-badge" :class="isSmartScanning ? 'status-scanning' : isAutoFilling ? 'status-filling' : 'status-idle'">
        <template v-if="isSmartScanning">⚡ Scanning...</template>
        <template v-else-if="isAutoFilling">🔧 Filling gaps...</template>
        <template v-else-if="overallStats.remaining > 0">⚠️ {{ overallStats.remaining }} gaps remaining</template>
        <template v-else>✅ All clean</template>
      </div>
    </div>

    <!-- Overall Progress -->
    <div class="overall-progress">
      <div class="overall-header">
        <span>Overall Health</span>
        <strong :style="{ color: overallStats.pct >= 90 ? '#34d399' : overallStats.pct >= 50 ? '#fbbf24' : '#ef5350' }">{{ overallStats.pct }}%</strong>
      </div>
      <div class="overall-bar">
        <div class="overall-fill" :class="{ animated: isSmartScanning || isAutoFilling }" :style="{ width: overallStats.pct + '%' }"></div>
      </div>
      <div class="overall-stats">
        <div class="stat"><b>{{ overallStats.symbols }}</b> symbols</div>
        <div class="stat"><b>{{ overallStats.scanned }}</b> timeframes scanned</div>
        <div class="stat"><b>{{ overallStats.totalGaps }}</b> gaps found</div>
        <div class="stat"><b>{{ overallStats.filled }}</b> filled</div>
        <div class="stat"><b>{{ overallStats.remaining }}</b> remaining</div>
      </div>
    </div>

    <!-- Symbol Grid -->
    <div class="symbol-grid">
      <div v-for="sym in symbols" :key="sym.id" class="symbol-card"
        :class="symbolStates[sym.id]?.state || 'idle'">
        <div class="card-header">
          <h3>{{ sym.ticker }}</h3>
          <span class="card-badge" :class="getSymbolBadge(sym).cls">{{ getSymbolBadge(sym).text }}</span>
        </div>
        <div class="tf-rows">
          <div v-for="tf in timeframes" :key="tf" class="tf-row">
            <span class="tf-label">{{ tf }}</span>
            <div class="tf-bar">
              <div class="tf-fill" :class="getTfBarClass(sym.id, tf)" :style="{ width: getTfPct(sym.id, tf) + '%' }"></div>
            </div>
            <span class="tf-pct" :style="{ color: getTfPct(sym.id, tf) >= 100 ? '#34d399' : getTfPct(sym.id, tf) > 70 ? '#fbbf24' : '#ef5350' }">
              {{ getTfPct(sym.id, tf) }}%
            </span>
            <span class="tf-gaps">{{ getTfGapCount(sym.id, tf) > 0 ? getTfGapCount(sym.id, tf) + ' gaps' : '' }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Activity Log -->
    <div class="activity-log" v-if="activityLog.length">
      <h3>📋 Activity Log</h3>
      <TransitionGroup name="log" tag="div">
        <div v-for="entry in activityLog" :key="entry.id" class="log-entry">
          <span class="log-time">{{ entry.time }}</span>
          <span class="log-icon">{{ entry.icon }}</span>
          <span class="log-msg" v-html="entry.msg"></span>
        </div>
      </TransitionGroup>
    </div>

    <!-- Empty state -->
    <div v-else-if="loaded && !isSmartScanning" class="empty-state">
      <p>Click <strong>Smart Scan All</strong> to detect gaps across all symbols and timeframes at once.</p>
    </div>
  </div>
</template>

<style scoped>
.smart-gaps { padding: 24px; max-width: 1100px; margin: 0 auto; }
.smart-gaps h1 { font-size: 24px; font-weight: 800; color: var(--text); margin: 0 0 4px; }
.sub { color: var(--muted); font-size: 13px; margin-bottom: 20px; }

/* Action Bar */
.action-bar { display: flex; gap: 12px; margin-bottom: 24px; align-items: center; flex-wrap: wrap; }
.btn-smart {
  background: linear-gradient(135deg, #8b5cf6, #6366f1);
  color: #fff; border: none; padding: 10px 24px; border-radius: 8px;
  font-size: 14px; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 8px;
  transition: all 0.3s; box-shadow: 0 0 20px rgba(139,92,246,0.3);
}
.btn-smart:hover:not(:disabled) { box-shadow: 0 0 30px rgba(139,92,246,0.5); transform: translateY(-1px); }
.btn-smart:disabled { opacity: 0.7; cursor: not-allowed; }
.btn-smart.scanning { animation: pulse-glow 1.5s infinite; }
@keyframes pulse-glow { 0%,100% { box-shadow: 0 0 20px rgba(139,92,246,0.3); } 50% { box-shadow: 0 0 40px rgba(139,92,246,0.6); } }

.btn-fill {
  background: linear-gradient(135deg, #059669, #10b981);
  color: #fff; border: none; padding: 10px 24px; border-radius: 8px;
  font-size: 14px; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 8px;
  transition: all 0.3s; box-shadow: 0 0 20px rgba(16,185,129,0.3);
}
.btn-fill:hover:not(:disabled) { box-shadow: 0 0 30px rgba(16,185,129,0.5); }
.btn-fill:disabled { opacity: 0.5; cursor: not-allowed; }

.status-badge {
  margin-left: auto; padding: 6px 16px; border-radius: 20px; font-size: 12px; font-weight: 600;
  background: rgba(100,100,100,0.1); color: var(--dim); border: 1px solid var(--border);
}
.status-scanning { background: rgba(139,92,246,0.15); color: #a78bfa; border-color: rgba(139,92,246,0.3); animation: pulse 1.5s infinite; }
.status-filling { background: rgba(16,185,129,0.15); color: #34d399; border-color: rgba(16,185,129,0.3); animation: pulse 1.5s infinite; }
@keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.6; } }

/* Overall Progress */
.overall-progress { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 16px 20px; margin-bottom: 24px; }
.overall-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
.overall-header span { font-size: 13px; color: var(--muted); }
.overall-header strong { font-size: 18px; }
.overall-bar { height: 8px; background: var(--border); border-radius: 4px; overflow: hidden; }
.overall-fill { height: 100%; border-radius: 4px; transition: width 0.8s ease; background: linear-gradient(90deg, #10b981, #34d399); }
.overall-fill.animated::after {
  content: ''; position: absolute; top: 0; right: 0; width: 30px; height: 100%;
  background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3));
  animation: shimmer 1.5s infinite;
}
@keyframes shimmer { 0% { opacity: 0; } 50% { opacity: 1; } 100% { opacity: 0; } }
.overall-stats { display: flex; gap: 24px; margin-top: 10px; flex-wrap: wrap; }
.overall-stats .stat { font-size: 12px; color: var(--dim); }
.overall-stats .stat b { color: var(--text); }

/* Symbol Grid */
.symbol-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 12px; margin-bottom: 24px; }
.symbol-card {
  background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 14px 16px;
  transition: all 0.4s;
}
.symbol-card.scanning { border-color: #8b5cf6; box-shadow: 0 0 15px rgba(139,92,246,0.15); }
.symbol-card.filling { border-color: #10b981; box-shadow: 0 0 15px rgba(16,185,129,0.15); }
.symbol-card.done { border-color: var(--border); }
.symbol-card.has_gaps { border-color: #f59e0b; }
.card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
.card-header h3 { font-size: 14px; font-weight: 700; color: var(--text); margin: 0; font-family: var(--mono); }
.card-badge { font-size: 10px; padding: 2px 8px; border-radius: 10px; font-weight: 600; }
.badge-ok { background: rgba(16,185,129,0.15); color: #34d399; }
.badge-gaps { background: rgba(245,158,11,0.15); color: #fbbf24; }
.badge-scanning { background: rgba(139,92,246,0.15); color: #a78bfa; animation: pulse 1s infinite; }
.badge-filling { background: rgba(16,185,129,0.15); color: #34d399; animation: pulse 1s infinite; }
.badge-idle { background: rgba(100,100,100,0.1); color: var(--dim); }

/* TF Progress Rows */
.tf-rows { display: flex; flex-direction: column; gap: 4px; }
.tf-row { display: flex; align-items: center; gap: 8px; font-size: 11px; }
.tf-label { width: 28px; font-weight: 700; color: var(--dim); font-family: var(--mono); }
.tf-bar { flex: 1; height: 4px; background: var(--border); border-radius: 2px; overflow: hidden; }
.tf-fill { height: 100%; border-radius: 2px; transition: width 0.8s ease; }
.tf-fill.ok { background: #10b981; }
.tf-fill.partial { background: #f59e0b; }
.tf-fill.scanning { background: #8b5cf6; animation: scan-bar 1s infinite; }
.tf-fill.filling { background: #10b981; animation: scan-bar 0.8s infinite; }
.tf-fill.idle { background: var(--border-hi); }
@keyframes scan-bar { 0% { opacity: 0.4; } 50% { opacity: 1; } 100% { opacity: 0.4; } }
.tf-pct { width: 32px; text-align: right; font-family: var(--mono); font-size: 10px; }
.tf-gaps { width: 48px; text-align: right; font-size: 10px; color: var(--dim); }

/* Activity Log */
.activity-log {
  background: var(--card); border: 1px solid var(--border); border-radius: 10px;
  padding: 14px 16px; max-height: 200px; overflow-y: auto;
}
.activity-log h3 { font-size: 13px; font-weight: 700; margin: 0 0 10px; color: var(--muted); }
.log-entry { display: flex; align-items: center; gap: 8px; font-size: 11px; padding: 4px 0; border-bottom: 1px solid var(--surface); }
.log-time { color: var(--dim); font-family: var(--mono); width: 60px; font-size: 10px; }
.log-icon { width: 16px; text-align: center; }
.log-msg { flex: 1; color: var(--muted); }
.log-msg :deep(.sym) { color: #8b5cf6; font-weight: 600; }
.log-msg :deep(.tf) { color: #f59e0b; }
.log-msg :deep(.ok) { color: #34d399; }
.log-msg :deep(.num) { color: #60a5fa; }

/* Log transition */
.log-enter-active { animation: log-fade-in 0.3s ease; }
@keyframes log-fade-in { from { opacity: 0; transform: translateX(-10px); } to { opacity: 1; transform: translateX(0); } }

/* Empty state */
.empty-state {
  text-align: center; padding: 40px; border: 1px dashed var(--border); border-radius: 12px;
  color: var(--dim); font-size: 14px;
}
.empty-state strong { color: #8b5cf6; }
</style>
