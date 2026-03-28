<script setup>
import { ref, reactive, onMounted, computed } from 'vue'
import axios from 'axios'

const symbols = ref([])
const timeframes = ['1M', '5M', '15M', '1H', '4H', '1D']
const loaded = ref(false)

const symbolStates = reactive({})
const activityLog = ref([])
const isSmartScanning = ref(false)
const isAutoFilling = ref(false)

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
    symbolStates[s.id] = { ticker: s.ticker, state: 'idle', tfResults: {} }
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
  addLog('🚀', `Smart Scan started — <span class="n">${symbols.value.length}</span> symbols × <span class="n">${timeframes.length}</span> TFs`)

  for (const sym of symbols.value) {
    symbolStates[sym.id].state = 'scanning'
    let symHasGaps = false

    for (const tf of timeframes) {
      symbolStates[sym.id].tfResults[tf].state = 'scanning'
      addLog('🔍', `Scanning <span class="s">${sym.ticker}</span> <span class="t">${tf}</span>...`)

      try {
        const { data } = await axios.post('/api/v1/gaps/scan', { symbol_id: sym.id, timeframe: tf })
        const gapCount = data.gaps?.length || 0
        symbolStates[sym.id].tfResults[tf].gaps = gapCount
        symbolStates[sym.id].tfResults[tf].pct = gapCount === 0 ? 100 : Math.max(0, 100 - gapCount * 20)
        symbolStates[sym.id].tfResults[tf].state = gapCount > 0 ? 'has_gaps' : 'clean'

        if (gapCount > 0) {
          symHasGaps = true
          addLog('⚡', `Found <span class="n">${gapCount}</span> gaps in <span class="s">${sym.ticker}</span> <span class="t">${tf}</span>`)
        }
      } catch {
        symbolStates[sym.id].tfResults[tf].state = 'clean'
      }
    }

    symbolStates[sym.id].state = symHasGaps ? 'has_gaps' : 'done'
    if (!symHasGaps) addLog('✅', `<span class="s">${sym.ticker}</span> all timeframes <span class="g">clean ✓</span>`)
  }

  addLog('🏁', `Scan complete — <span class="n">${overallStats.value.totalGaps}</span> gaps found`)
  isSmartScanning.value = false
}

async function autoFillAll() {
  if (isAutoFilling.value) return
  isAutoFilling.value = true
  addLog('🔧', `Auto-Fill started — <span class="n">${overallStats.value.remaining}</span> gaps`)

  for (const sym of symbols.value) {
    let hadGaps = false
    for (const tf of timeframes) {
      const tfState = symbolStates[sym.id].tfResults[tf]
      if (tfState.gaps > 0 && tfState.state === 'has_gaps') {
        hadGaps = true
        symbolStates[sym.id].state = 'filling'
        tfState.state = 'filling'
        addLog('⬇️', `Filling <span class="s">${sym.ticker}</span> <span class="t">${tf}</span>...`)

        try {
          await axios.post('/api/v1/gaps/fill', { symbol_id: sym.id, timeframe: tf })
          tfState.filled = tfState.gaps
          tfState.pct = 100
          tfState.state = 'clean'
          addLog('✅', `Filled <span class="n">${tfState.gaps}</span> candles for <span class="s">${sym.ticker}</span> <span class="t">${tf}</span> <span class="g">→ 100%</span>`)
        } catch {
          tfState.state = 'has_gaps'
          addLog('❌', `Failed to fill <span class="s">${sym.ticker}</span> <span class="t">${tf}</span>`)
        }
      }
    }
    if (hadGaps) symbolStates[sym.id].state = 'done'
  }

  addLog('🏁', `Auto-Fill complete!`)
  isAutoFilling.value = false
}

function getSymbolBadge(sym) {
  const s = symbolStates[sym.id]
  if (!s) return { cls: 'badge-idle', text: '—' }
  if (s.state === 'scanning') return { cls: 'badge-scan', text: 'Scanning...' }
  if (s.state === 'filling') return { cls: 'badge-fill', text: 'Filling...' }
  if (s.state === 'has_gaps') {
    const g = Object.values(s.tfResults).reduce((a, t) => a + Math.max(0, (t.gaps || 0) - (t.filled || 0)), 0)
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
</script>

<template>
  <div class="sg">
    <!-- Top bar: title + buttons -->
    <div class="top-bar">
      <h1>Data Gaps</h1>
      <div class="top-actions">
        <button class="btn-sm btn-scan" :class="{ active: isSmartScanning }" @click="smartScanAll" :disabled="isSmartScanning">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
          {{ isSmartScanning ? 'Scanning...' : 'Smart Scan All' }}
        </button>
        <button class="btn-sm btn-autofill" @click="autoFillAll" :disabled="isAutoFilling || overallStats.remaining === 0">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
          {{ isAutoFilling ? 'Filling...' : 'Auto-Fill All' }}
        </button>
        <span class="status-pill" :class="{ 'pill-scan': isSmartScanning, 'pill-fill': isAutoFilling, 'pill-warn': !isSmartScanning && !isAutoFilling && overallStats.remaining > 0 }">
          <template v-if="isSmartScanning">⚡ Scanning...</template>
          <template v-else-if="isAutoFilling">🔧 Filling...</template>
          <template v-else-if="overallStats.remaining > 0">⚠️ {{ overallStats.remaining }} remaining</template>
          <template v-else>✅ All clean</template>
        </span>
      </div>
    </div>

    <div class="sub">Smart scan & auto-fill missing candle data across all symbols and timeframes</div>

    <!-- Compact health strip -->
    <div class="health-strip">
      <div class="health-bar-wrap">
        <div class="health-meta">
          <span class="health-label">Overall Health</span>
          <div class="health-stats">
            <span><b>{{ overallStats.symbols }}</b> symbols</span>
            <span><b>{{ overallStats.scanned }}</b> scanned</span>
            <span><b>{{ overallStats.totalGaps }}</b> gaps</span>
            <span><b>{{ overallStats.filled }}</b> filled</span>
            <span><b>{{ overallStats.remaining }}</b> remaining</span>
          </div>
        </div>
        <div class="health-bar">
          <div class="health-fill" :class="{ shimmer: isSmartScanning || isAutoFilling }" :style="{ width: overallStats.pct + '%' }"></div>
        </div>
      </div>
      <div class="health-pct" :style="{ color: overallStats.pct >= 90 ? '#34d399' : overallStats.pct >= 50 ? '#fbbf24' : '#ef5350' }">{{ overallStats.pct }}%</div>
    </div>

    <!-- Main 2-col layout: cards + log -->
    <div class="main-layout">
      <!-- Left: Symbol grid -->
      <div class="sym-grid">
        <div v-for="sym in symbols" :key="sym.id" class="sym-card" :class="symbolStates[sym.id]?.state || 'idle'">
          <div class="card-top">
            <h3>{{ sym.ticker }}</h3>
            <span class="badge" :class="getSymbolBadge(sym).cls">{{ getSymbolBadge(sym).text }}</span>
          </div>
          <div class="tf-grid">
            <div v-for="tf in timeframes" :key="tf" class="tf-item">
              <div class="tf-name">{{ tf }}</div>
              <div class="tf-mini-bar">
                <div class="tf-mini-fill" :class="getTfBarClass(sym.id, tf)" :style="{ width: getTfPct(sym.id, tf) + '%' }"></div>
              </div>
              <div class="tf-val" :style="{ color: getTfPct(sym.id, tf) >= 100 ? '#34d399' : getTfPct(sym.id, tf) > 70 ? '#fbbf24' : '#ef5350' }">
                {{ getTfPct(sym.id, tf) }}%
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Right: Activity log -->
      <div class="log-panel">
        <div class="log-title">📋 Activity Log</div>
        <div v-if="!activityLog.length" class="log-empty">Click Smart Scan All to start</div>
        <TransitionGroup name="log" tag="div">
          <div v-for="entry in activityLog" :key="entry.id" class="log-entry">
            <span class="log-t">{{ entry.time }}</span>
            <span class="log-i">{{ entry.icon }}</span>
            <span class="log-m" v-html="entry.msg"></span>
          </div>
        </TransitionGroup>
      </div>
    </div>
  </div>
</template>

<style scoped>
.sg { padding: 20px 24px; max-width: 1200px; margin: 0 auto; }
.sg h1 { font-size: 22px; font-weight: 800; color: var(--text); margin: 0; }
.sub { color: var(--dim); font-size: 12px; margin-bottom: 16px; }

/* Top bar */
.top-bar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px; flex-wrap: wrap; gap: 10px; }
.top-actions { display: flex; align-items: center; gap: 10px; }

.btn-sm {
  border: none; padding: 7px 16px; border-radius: 6px;
  font-size: 11px; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 6px;
  transition: all 0.3s; font-family: inherit;
}
.btn-sm:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-scan { background: linear-gradient(135deg, #8b5cf6, #6366f1); color: #fff; box-shadow: 0 0 12px rgba(139,92,246,0.2); }
.btn-scan:hover:not(:disabled) { box-shadow: 0 0 24px rgba(139,92,246,0.4); transform: translateY(-1px); }
.btn-scan.active { animation: pulse-glow 1.5s infinite; }
@keyframes pulse-glow { 0%,100% { box-shadow: 0 0 12px rgba(139,92,246,0.2); } 50% { box-shadow: 0 0 30px rgba(139,92,246,0.5); } }
.btn-autofill { background: linear-gradient(135deg, #059669, #10b981); color: #fff; box-shadow: 0 0 12px rgba(16,185,129,0.2); }
.btn-autofill:hover:not(:disabled) { box-shadow: 0 0 24px rgba(16,185,129,0.4); }

.status-pill { padding: 4px 12px; border-radius: 12px; font-size: 10px; font-weight: 600; background: rgba(16,185,129,0.12); color: #34d399; border: 1px solid rgba(16,185,129,0.2); }
.pill-scan { background: rgba(139,92,246,0.12); color: #a78bfa; border-color: rgba(139,92,246,0.25); animation: pulse 1.5s infinite; }
.pill-fill { background: rgba(16,185,129,0.12); color: #34d399; border-color: rgba(16,185,129,0.25); animation: pulse 1.5s infinite; }
.pill-warn { background: rgba(245,158,11,0.12); color: #fbbf24; border-color: rgba(245,158,11,0.25); }
@keyframes pulse { 0%,100%{opacity:1}50%{opacity:.5} }

/* Health strip */
.health-strip { background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 12px 16px; margin-bottom: 16px; display: flex; align-items: center; gap: 16px; }
.health-bar-wrap { flex: 1; }
.health-meta { display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px; }
.health-label { font-size: 11px; color: var(--dim); }
.health-stats { display: flex; gap: 14px; font-size: 10px; color: var(--dim); }
.health-stats b { color: var(--text); }
.health-bar { height: 6px; background: var(--border); border-radius: 3px; overflow: hidden; }
.health-fill { height: 100%; border-radius: 3px; background: linear-gradient(90deg, #10b981, #34d399); transition: width 0.8s; position: relative; }
.health-fill.shimmer::after { content:''; position:absolute; top:0; right:0; width:30px; height:100%; background:linear-gradient(90deg,transparent,rgba(255,255,255,0.3)); animation:shimmer 1.5s infinite; }
@keyframes shimmer { 0%{opacity:0}50%{opacity:1}100%{opacity:0} }
.health-pct { font-size: 22px; font-weight: 800; min-width: 55px; text-align: right; }

/* Main 2-col layout */
.main-layout { display: grid; grid-template-columns: 1fr 300px; gap: 16px; }
@media (max-width: 900px) { .main-layout { grid-template-columns: 1fr; } }

/* Symbol grid */
.sym-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }
@media (max-width: 700px) { .sym-grid { grid-template-columns: 1fr; } }

.sym-card { background: var(--card); border: 1px solid var(--border); border-radius: 8px; padding: 10px 12px; transition: all 0.4s; }
.sym-card.scanning { border-color: #8b5cf6; box-shadow: 0 0 12px rgba(139,92,246,0.12); }
.sym-card.filling { border-color: #10b981; box-shadow: 0 0 12px rgba(16,185,129,0.12); }
.sym-card.has_gaps { border-color: #f59e0b; }
.sym-card.done { border-color: var(--border); }

.card-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
.card-top h3 { font-size: 12px; font-weight: 700; color: var(--text); margin: 0; font-family: var(--mono); }

.badge { font-size: 9px; padding: 2px 8px; border-radius: 8px; font-weight: 600; }
.badge-ok { background: rgba(16,185,129,0.12); color: #34d399; }
.badge-gaps { background: rgba(245,158,11,0.12); color: #fbbf24; }
.badge-scan { background: rgba(139,92,246,0.12); color: #a78bfa; animation: pulse 1s infinite; }
.badge-fill { background: rgba(16,185,129,0.12); color: #34d399; animation: pulse 1s infinite; }
.badge-idle { background: rgba(100,100,100,0.08); color: var(--dim); }

/* Horizontal TF grid */
.tf-grid { display: grid; grid-template-columns: repeat(6, 1fr); gap: 4px; }
.tf-item { text-align: center; }
.tf-name { font-size: 8px; font-weight: 700; color: var(--dim); font-family: var(--mono); margin-bottom: 2px; }
.tf-mini-bar { height: 3px; background: var(--border); border-radius: 2px; overflow: hidden; }
.tf-mini-fill { height: 100%; border-radius: 2px; transition: width 0.6s; }
.tf-mini-fill.ok { background: #10b981; }
.tf-mini-fill.partial { background: #f59e0b; }
.tf-mini-fill.scanning { background: #8b5cf6; animation: scan-pulse 0.8s infinite; }
.tf-mini-fill.filling { background: #10b981; animation: scan-pulse 0.8s infinite; }
.tf-mini-fill.idle { background: var(--border-hi); }
@keyframes scan-pulse { 0%{opacity:.4}50%{opacity:1}100%{opacity:.4} }
.tf-val { font-size: 8px; margin-top: 1px; font-family: var(--mono); }

/* Activity log */
.log-panel { background: var(--card); border: 1px solid var(--border); border-radius: 8px; padding: 10px 12px; max-height: 500px; overflow-y: auto; }
.log-title { font-size: 11px; font-weight: 700; color: var(--dim); margin-bottom: 8px; }
.log-empty { text-align: center; padding: 20px; font-size: 11px; color: var(--dim); }
.log-entry { display: flex; align-items: flex-start; gap: 6px; font-size: 10px; padding: 3px 0; border-bottom: 1px solid var(--surface); }
.log-t { color: var(--dim); font-family: var(--mono); width: 50px; font-size: 9px; flex-shrink: 0; }
.log-i { width: 14px; text-align: center; flex-shrink: 0; }
.log-m { flex: 1; color: var(--muted); line-height: 1.3; }
.log-m :deep(.s) { color: #8b5cf6; font-weight: 600; }
.log-m :deep(.t) { color: #f59e0b; }
.log-m :deep(.g) { color: #34d399; }
.log-m :deep(.n) { color: #60a5fa; }

/* Log transition */
.log-enter-active { animation: log-in 0.3s ease; }
@keyframes log-in { from { opacity:0; transform:translateX(-8px); } to { opacity:1; transform:translateX(0); } }
</style>
