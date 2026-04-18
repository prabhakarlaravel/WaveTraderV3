<script setup>
import { ref, reactive, onMounted, computed, h } from 'vue'
import axios from 'axios'
import SymbolSelector from '../components/shared/SymbolSelector.vue'
import { useChartStore } from '../stores/useChartStore'

// Reusable SVG spinner — one source of truth for every loading state
// in this view. Size is pixel units; color inherits currentColor so the
// spinner picks up whatever text color its parent declares.
const SpinnerIcon = {
  props: { size: { type: [Number, String], default: 14 } },
  setup(props) {
    return () => h('svg', {
      width: props.size,
      height: props.size,
      viewBox: '0 0 24 24',
      fill: 'none',
      class: 'spinner-icon',
      'aria-hidden': 'true',
    }, [
      h('circle', {
        cx: 12, cy: 12, r: 9,
        stroke: 'currentColor', 'stroke-width': 2.5,
        'stroke-linecap': 'round', 'stroke-dasharray': '40 60', opacity: 0.9,
      }),
    ])
  },
}

const chartStore = useChartStore()
const symbols = computed(() => chartStore.symbols)
const selectedSymbol = computed({
  get: () => chartStore.activeSymbolId,
  set: (v) => {
    chartStore.activeSymbolId = v
    try { localStorage.setItem('wt3_active_symbol', String(v)) } catch {}
  },
})
const validationResult = ref(null)
const loading = ref(false)
const fixingTf = ref('')
const regenerating = ref(false)
const fixResults = reactive({})
const activityLog = ref([])
// Request state for the loader UX — drives elapsed counter + error recovery
const loadingStartedAt = ref(0)
const loadingElapsed = ref(0)
const loadError = ref(null)         // null | { message, status, retriable: true }
let elapsedTimer = null

onMounted(async () => {
  if (!chartStore.symbols.length) await chartStore.fetchSymbols()
  if (chartStore.activeSymbolId) await runValidation()
})

function addLog(msg, color = '#34d399') {
  activityLog.value.unshift({ msg, color, time: new Date().toLocaleTimeString() })
  if (activityLog.value.length > 25) activityLog.value.pop()
}

async function runValidation() {
  if (!selectedSymbol.value) return
  loading.value = true
  loadError.value = null
  loadingStartedAt.value = Date.now()
  loadingElapsed.value = 0
  // Tick elapsed seconds so the skeleton shows "Validating wave structure • 4s"
  if (elapsedTimer) clearInterval(elapsedTimer)
  elapsedTimer = setInterval(() => {
    loadingElapsed.value = Math.floor((Date.now() - loadingStartedAt.value) / 1000)
  }, 500)
  addLog('Validating all timeframes...', '#7c3aed')
  try {
    // 120s timeout — first validation legitimately takes ~60-90s because it
    // runs Elliott Wave + Market Structure engines across all 6 timeframes
    // synchronously. The backend caches the result for 45s so reloads and
    // dropdown flips are near-instant. Anything beyond 120s = backend hang.
    const { data } = await axios.post(
      '/api/v1/wave-health/validate',
      { symbol_id: selectedSymbol.value },
      { timeout: 120000 },
    )
    validationResult.value = data
    addLog(`Validation done — health ${data.overallHealth}%, ${data.totalViolations} violations`, '#34d399')
  } catch (e) {
    const isTimeout = e.code === 'ECONNABORTED' || /timeout/i.test(e.message || '')
    const isNetwork = !e.response && (e.code === 'ERR_NETWORK' || /Network Error/i.test(e.message || ''))
    loadError.value = {
      message: isTimeout
        ? 'Validation timed out after 2 minutes. The backend may be under heavy load or a query is stuck.'
        : isNetwork
          ? 'Cannot reach the backend API. Make sure the Laravel server is running on port 8000.'
          : (e.response?.data?.message || e.message || 'Validation failed'),
      status: e.response?.status,
      retriable: true,
    }
    addLog(`Validation failed: ${loadError.value.message}`, '#ef5350')
  } finally {
    loading.value = false
    if (elapsedTimer) { clearInterval(elapsedTimer); elapsedTimer = null }
  }
}

async function autoFix(timeframe) {
  if (!selectedSymbol.value || fixingTf.value) return
  fixingTf.value = timeframe
  fixResults[timeframe] = { status: 'fixing' }
  addLog(`${timeframe}: Testing 7 swing parameters...`, '#2563eb')

  try {
    const { data } = await axios.post('/api/v1/wave-health/fix', { symbol_id: selectedSymbol.value, timeframe })
    if (data.fixed) {
      fixResults[timeframe] = { status: 'fixed', ...data }
      addLog(`✓ ${timeframe}: ${data.message}`, '#34d399')
    } else {
      fixResults[timeframe] = { status: 'no_fix', ...data }
      addLog(`⚡ ${timeframe}: Already optimal — ${data.message}`, '#fbbf24')
    }
    await runValidation()
  } catch (e) {
    fixResults[timeframe] = { status: 'error', message: e.message }
    addLog(`✕ ${timeframe}: ${e.message}`, '#ef5350')
  } finally {
    fixingTf.value = ''
  }
}

async function autoFixAll() {
  if (!validationResult.value) return
  const tfs = validationResult.value.timeframes.filter(tf => tf.violations?.length > 0).map(tf => tf.timeframe)
  if (!tfs.length) { addLog('No violations to fix', '#fbbf24'); return }
  addLog(`Auto-Fix All: ${tfs.length} timeframes`, '#7c3aed')
  for (const tf of tfs) {
    await autoFix(tf)
  }
  addLog('Auto-Fix All complete', '#34d399')
}

async function regenerateAll() {
  if (!selectedSymbol.value) return
  regenerating.value = true
  addLog('Regenerating all waves with saved parameters...', '#7c3aed')
  try {
    const { data } = await axios.post('/api/v1/wave-health/regenerate', { symbol_id: selectedSymbol.value })
    addLog(`✓ Waves regenerated: ${data.message}`, '#34d399')
    await runValidation()
  } catch (e) {
    addLog(`✕ Regeneration failed: ${e.message}`, '#ef5350')
  } finally {
    regenerating.value = false
  }
}

function scoreColor(s) { return s >= 90 ? '#34d399' : s >= 70 ? '#fbbf24' : '#ef5350' }
function fixStatus(tf) { return fixResults[tf]?.status || 'idle' }

const allViolations = computed(() => {
  if (!validationResult.value) return []
  const v = []
  for (const tf of validationResult.value.timeframes)
    for (const vi of (tf.violations || []))
      v.push({ ...vi, timeframe: tf.timeframe })
  return v
})
</script>

<template>
  <div class="p-4 max-w-7xl mx-auto">
    <!-- Header -->
    <div class="flex items-center justify-between mb-5">
      <div>
        <h1 class="text-2xl font-bold" style="color: var(--text)">Wave Health</h1>
        <p class="mt-1 text-xs" style="color: var(--muted)">Elliott Wave validation, auto-fix, and wave regeneration</p>
      </div>
      <div class="flex items-center gap-2">
        <SymbolSelector
          :symbols="symbols"
          v-model="selectedSymbol"
          @change="runValidation()"
          compact
        />
        <button @click="runValidation" :disabled="loading"
          class="btn-pill inline-flex items-center gap-1.5"
          :style="{ background: loading ? '#1e40af' : '#2563eb', color: '#fff' }">
          <SpinnerIcon v-if="loading" size="12" />
          <span v-else>🔍</span>
          {{ loading ? 'Validating…' : 'Validate' }}
        </button>
        <button @click="autoFixAll" :disabled="!allViolations.length || fixingTf"
          class="btn-pill inline-flex items-center gap-1.5"
          style="background: #059669; color: #fff">
          <SpinnerIcon v-if="fixingTf" size="12" />
          <span v-else>🔧</span>
          {{ fixingTf ? 'Fixing…' : 'Auto-Fix All' }}
        </button>
        <button @click="regenerateAll" :disabled="regenerating"
          class="btn-pill inline-flex items-center gap-1.5"
          :style="{ background: regenerating ? '#6d28d9' : '#7c3aed', color: '#fff' }">
          <SpinnerIcon v-if="regenerating" size="12" />
          <span v-else>🔄</span>
          {{ regenerating ? 'Regenerating…' : 'Regenerate Waves' }}
        </button>
      </div>
    </div>

    <div v-if="validationResult" class="grid gap-4" style="grid-template-columns: 1fr 300px">
      <!-- LEFT -->
      <div>
        <!-- Summary -->
        <div class="grid grid-cols-4 gap-3 mb-4">
          <div class="rounded-lg p-3 text-center" style="background: var(--card); border: 1px solid var(--border)">
            <div class="text-[8px] uppercase tracking-widest" style="color: var(--dim)">Health</div>
            <div class="text-3xl font-black mt-1 transition-all duration-700" :style="{ color: scoreColor(validationResult.overallHealth) }">{{ validationResult.overallHealth }}%</div>
            <div class="mt-2 rounded-full overflow-hidden" style="height: 4px; background: var(--surface)">
              <div class="h-full rounded-full transition-all duration-1000" :style="{ width: validationResult.overallHealth + '%', background: scoreColor(validationResult.overallHealth) }"></div>
            </div>
          </div>
          <div class="rounded-lg p-3 text-center" style="background: var(--card); border: 1px solid var(--border)">
            <div class="text-[8px] uppercase tracking-widest" style="color: var(--dim)">Violations</div>
            <div class="text-3xl font-black mt-1" :style="{ color: validationResult.totalViolations ? '#ef5350' : '#34d399' }">{{ validationResult.totalViolations }}</div>
            <div class="text-[9px] mt-1" style="color: var(--muted)">{{ validationResult.criticalCount }} critical</div>
          </div>
          <div class="rounded-lg p-3 text-center" style="background: var(--card); border: 1px solid var(--border)">
            <div class="text-[8px] uppercase tracking-widest" style="color: var(--dim)">Data</div>
            <div class="text-3xl font-black mt-1" :style="{ color: validationResult.dataIntegrity.gaps === 0 ? '#34d399' : '#fbbf24' }">
              {{ validationResult.dataIntegrity.gaps === 0 ? '✓' : '⚠' }}
            </div>
            <div class="text-[9px] mt-1" style="color: var(--muted)">{{ validationResult.dataIntegrity.total_candles?.toLocaleString() }} candles</div>
          </div>
          <div class="rounded-lg p-3 text-center" style="background: var(--card); border: 1px solid var(--border)">
            <div class="text-[8px] uppercase tracking-widest" style="color: var(--dim)">Fixable</div>
            <div class="text-3xl font-black mt-1" :style="{ color: validationResult.fixableCount ? '#fbbf24' : '#34d399' }">{{ validationResult.fixableCount }}</div>
            <div class="text-[9px] mt-1" style="color: var(--muted)">{{ validationResult.fixableCount ? 'Can optimize' : 'All optimal' }}</div>
          </div>
        </div>

        <!-- TF Table -->
        <div class="rounded-xl overflow-hidden mb-4" style="border: 1px solid var(--border)">
          <table class="w-full text-xs">
            <thead style="background: var(--card-alt)">
              <tr style="border-bottom: 1px solid var(--border)">
                <th class="px-3 py-2 text-left font-semibold" style="color: var(--dim)">TF</th>
                <th class="px-3 py-2 text-center font-semibold" style="color: var(--dim)">Score</th>
                <th class="px-3 py-2 text-center font-semibold" style="color: var(--dim)">Wave</th>
                <th class="px-3 py-2 text-center font-semibold" style="color: var(--dim)">Trend</th>
                <th class="px-3 py-2 text-center font-semibold" style="color: var(--dim)">Swing</th>
                <th class="px-3 py-2 text-center font-semibold" style="color: var(--dim)">Issues</th>
                <th class="px-3 py-2 text-right font-semibold" style="color: var(--dim)">Action</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="tf in validationResult.timeframes" :key="tf.timeframe"
                class="transition-all duration-500"
                :style="{ borderBottom: '1px solid rgba(22,32,64,0.4)', background: fixStatus(tf.timeframe) === 'fixed' ? 'rgba(52,211,153,0.04)' : 'transparent' }">
                <td class="px-3 py-2.5 font-bold text-sm" style="font-family: var(--mono); color: var(--text)">{{ tf.timeframe }}</td>
                <td class="px-3 py-2.5 text-center">
                  <div class="inline-flex items-center gap-1">
                    <svg width="22" height="22" viewBox="0 0 32 32">
                      <circle cx="16" cy="16" r="13" fill="none" stroke="var(--surface)" stroke-width="3"/>
                      <circle cx="16" cy="16" r="13" fill="none" :stroke="scoreColor(tf.score)" stroke-width="3"
                        :stroke-dasharray="`${81.7*tf.score/100} ${81.7*(1-tf.score/100)}`" stroke-linecap="round" transform="rotate(-90 16 16)"/>
                    </svg>
                    <span class="font-bold" style="font-family: var(--mono)" :style="{ color: scoreColor(tf.score) }">{{ tf.score }}</span>
                  </div>
                </td>
                <td class="px-3 py-2.5 text-center text-[10px] font-bold" style="font-family: var(--mono); color: var(--text)">
                  {{ tf.current_wave ? `W${tf.current_wave}` : '—' }}
                  <span v-if="tf.phase" class="font-normal" style="color: var(--dim)"> {{ tf.phase }}</span>
                </td>
                <td class="px-3 py-2.5 text-center text-[10px] font-bold" :style="{ color: tf.trend === 'bullish' ? '#34d399' : tf.trend === 'bearish' ? '#ef5350' : 'var(--dim)' }">
                  {{ tf.trend === 'bullish' ? '↗ BULL' : tf.trend === 'bearish' ? '↘ BEAR' : '→' }}
                </td>
                <td class="px-3 py-2.5 text-center text-[10px]" style="font-family: var(--mono); color: var(--muted)">
                  sw{{ tf.swing_strength }}
                </td>
                <td class="px-3 py-2.5 text-center">
                  <span v-if="tf.violations?.length" class="text-[10px] font-bold" style="color: #ef5350">⚠ {{ tf.violations.length }}</span>
                  <span v-else class="text-[10px] font-bold" style="color: #34d399">✓</span>
                </td>
                <td class="px-3 py-2.5 text-right">
                  <template v-if="fixStatus(tf.timeframe) === 'fixing'">
                    <span class="inline-flex items-center gap-1 text-[9px] font-bold" style="color: #2563eb">
                      <SpinnerIcon size="10" />
                      Testing 7 params…
                    </span>
                  </template>
                  <template v-else-if="fixStatus(tf.timeframe) === 'fixed'">
                    <span class="text-[9px] font-bold" style="color: #34d399">✓ Fixed & Saved</span>
                  </template>
                  <template v-else-if="fixStatus(tf.timeframe) === 'no_fix'">
                    <span class="text-[9px] font-bold" style="color: #fbbf24" :title="fixResults[tf.timeframe]?.suggestion">⚡ Best possible</span>
                  </template>
                  <template v-else-if="tf.violations?.length">
                    <button @click="autoFix(tf.timeframe)" :disabled="!!fixingTf"
                      class="rounded px-2 py-0.5 text-[9px] font-bold" style="background: #ef5350; color: #fff">🔧 Fix</button>
                    <span v-if="tf.fixable && tf.best_alt" class="ml-1 text-[8px]" style="color: #fbbf24">
                      → sw{{ tf.best_alt.best }} ({{ tf.best_alt.bestScore }})
                    </span>
                  </template>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Violations -->
        <div class="rounded-xl p-4" style="background: var(--card); border: 1px solid var(--border)">
          <h3 class="text-sm font-bold mb-3" :style="{ color: allViolations.length ? '#ef5350' : '#34d399' }">
            {{ allViolations.length ? '⚠ Rule Violations' : '✅ All Rules Pass' }}
          </h3>
          <div v-if="allViolations.length" class="space-y-2">
            <div v-for="(v, i) in allViolations" :key="i"
              class="rounded-lg p-2.5 flex items-center gap-2 transition-all duration-300"
              :style="{ background: fixStatus(v.timeframe) === 'fixed' ? 'rgba(52,211,153,0.05)' : 'rgba(239,83,80,0.04)', border: '1px solid ' + (fixStatus(v.timeframe) === 'fixed' ? 'rgba(52,211,153,0.15)' : 'rgba(239,83,80,0.12)') }">
              <span class="rounded px-1.5 py-0.5 text-[8px] font-extrabold"
                :style="{ background: fixStatus(v.timeframe) === 'fixed' ? '#34d399' : '#ef5350', color: '#fff' }">R{{ v.rule }}</span>
              <div class="flex-1 min-w-0">
                <div class="text-[10px] font-semibold" style="color: var(--text)">{{ v.timeframe }} — {{ v.description }}</div>
                <div v-if="v.detail" class="text-[8px] mt-0.5" style="color: var(--dim)">{{ v.detail }}</div>
              </div>
              <template v-if="fixStatus(v.timeframe) === 'fixing'">
                <span class="inline-flex items-center gap-1 text-[8px] font-bold shrink-0" style="color: #2563eb">
                  <SpinnerIcon size="9" />
                  Testing…
                </span>
              </template>
              <template v-else-if="fixStatus(v.timeframe) === 'fixed'">
                <span class="text-[8px] font-bold shrink-0" style="color: #34d399">✓ Resolved</span>
              </template>
              <template v-else-if="fixStatus(v.timeframe) === 'no_fix'">
                <span class="text-[8px] font-bold shrink-0" style="color: #fbbf24">Market structure</span>
              </template>
              <template v-else>
                <button @click="autoFix(v.timeframe)" :disabled="!!fixingTf"
                  class="rounded px-2 py-0.5 text-[8px] font-bold shrink-0" style="background: #ef5350; color: #fff">Fix</button>
              </template>
            </div>
          </div>
          <div v-else class="text-center py-4">
            <div class="text-sm" style="color: #34d399">No violations detected across all timeframes</div>
          </div>
        </div>
      </div>

      <!-- RIGHT -->
      <div>
        <!-- Activity Log -->
        <div class="rounded-xl p-4 mb-3" style="background: var(--card); border: 1px solid var(--border)">
          <h3 class="text-xs font-bold mb-3" style="color: var(--text)">📝 Activity Log</h3>
          <div class="space-y-1.5 max-h-52 overflow-y-auto">
            <div v-for="(log, i) in activityLog" :key="i" class="flex gap-2 items-start">
              <div class="w-1.5 h-1.5 rounded-full mt-1.5 shrink-0" :style="{ background: log.color }"></div>
              <div class="min-w-0">
                <div class="text-[10px] font-semibold" :style="{ color: log.color }">{{ log.msg }}</div>
                <div class="text-[8px]" style="color: var(--dim)">{{ log.time }}</div>
              </div>
            </div>
            <div v-if="!activityLog.length" class="text-[10px] text-center py-3" style="color: var(--dim)">No activity</div>
          </div>
        </div>

        <!-- Fix Results -->
        <div v-if="Object.keys(fixResults).length" class="rounded-xl p-4 mb-3" style="background: var(--card); border: 1px solid var(--border)">
          <h3 class="text-xs font-bold mb-3" style="color: var(--text)">🔧 Fix Results</h3>
          <div class="space-y-2">
            <div v-for="(r, tf) in fixResults" :key="tf" class="rounded-lg p-2 transition-all"
              :style="{ background: r.status === 'fixed' ? 'rgba(52,211,153,0.06)' : r.status === 'no_fix' ? 'rgba(251,191,36,0.06)' : r.status === 'fixing' ? 'rgba(37,99,235,0.06)' : 'rgba(239,83,80,0.06)' }">
              <div class="flex items-center gap-1.5 mb-1">
                <span class="font-bold text-[10px]" style="font-family: var(--mono); color: var(--text)">{{ tf }}</span>
                <span v-if="r.status === 'fixed'" class="text-[8px] font-bold" style="color: #34d399">✓ SAVED</span>
                <span v-else-if="r.status === 'no_fix'" class="text-[8px] font-bold" style="color: #fbbf24">⚡ OPTIMAL</span>
                <span v-else-if="r.status === 'fixing'" class="inline-flex items-center gap-1 text-[8px] font-bold" style="color: #2563eb">
                  <SpinnerIcon size="9" />
                  TESTING
                </span>
                <span v-else class="text-[8px] font-bold" style="color: #ef5350">✕ ERROR</span>
              </div>
              <div class="text-[9px]" style="color: var(--muted)">{{ r.message }}</div>
              <div v-if="r.suggestion" class="text-[8px] mt-1" style="color: #fbbf24">💡 {{ r.suggestion }}</div>
            </div>
          </div>
        </div>

        <!-- Data Integrity -->
        <div class="rounded-xl p-4" style="background: var(--card); border: 1px solid var(--border)">
          <h3 class="text-xs font-bold mb-3" style="color: #2563eb">📊 Data Integrity</h3>
          <div class="grid grid-cols-2 gap-2">
            <div class="rounded-lg p-2" style="background: var(--surface)">
              <div class="text-[8px]" style="color: var(--dim)">Candles</div>
              <div class="text-sm font-extrabold" style="color: var(--text)">{{ validationResult.dataIntegrity.total_candles?.toLocaleString() }}</div>
            </div>
            <div class="rounded-lg p-2" style="background: var(--surface)">
              <div class="text-[8px]" style="color: var(--dim)">Gaps</div>
              <div class="text-sm font-extrabold" :style="{ color: validationResult.dataIntegrity.gaps === 0 ? '#34d399' : '#fbbf24' }">{{ validationResult.dataIntegrity.gaps }}</div>
            </div>
            <div class="rounded-lg p-2" style="background: var(--surface)">
              <div class="text-[8px]" style="color: var(--dim)">Zero Vol</div>
              <div class="text-sm font-extrabold" :style="{ color: validationResult.dataIntegrity.zero_volume === 0 ? '#34d399' : '#ef5350' }">{{ validationResult.dataIntegrity.zero_volume }}</div>
            </div>
            <div class="rounded-lg p-2" style="background: var(--surface)">
              <div class="text-[8px]" style="color: var(--dim)">Dupes</div>
              <div class="text-sm font-extrabold" style="color: #34d399">0</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Error state — replaces the skeleton when the initial validation request
         fails (timeout, backend down, 5xx). Gives the user a clear message and
         a Retry button instead of an eternal loading screen. -->
    <div v-if="!validationResult && !loading && loadError"
      class="rounded-xl p-6 text-center mx-auto" style="max-width: 560px; background: var(--card); border: 1px solid rgba(239,83,80,0.35)">
      <div class="flex justify-center mb-3">
        <div class="rounded-full flex items-center justify-center" style="width: 48px; height: 48px; background: rgba(239,83,80,0.12)">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#ef5350" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="10"/>
            <line x1="12" y1="8" x2="12" y2="12"/>
            <line x1="12" y1="16" x2="12.01" y2="16"/>
          </svg>
        </div>
      </div>
      <div class="text-sm font-bold mb-1" style="color: var(--text)">Couldn't load wave health</div>
      <div class="text-[12px] mb-4" style="color: var(--muted)">{{ loadError.message }}</div>
      <button @click="runValidation" class="btn-pill inline-flex items-center gap-1.5" style="background: #2563eb; color: #fff">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <polyline points="23 4 23 10 17 10"/>
          <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
        </svg>
        Retry
      </button>
    </div>

    <!-- Skeleton loader — mirrors the real layout so the UI doesn't "jump"
         when data arrives. Shown only on initial validation (no cached result). -->
    <div v-if="!validationResult && loading" class="grid gap-4" style="grid-template-columns: 1fr 300px">
      <div>
        <!-- Summary cards skeleton -->
        <div class="grid grid-cols-4 gap-3 mb-4">
          <div v-for="n in 4" :key="'sum-'+n" class="rounded-lg p-3" style="background: var(--card); border: 1px solid var(--border)">
            <div class="skel skel-line" style="width: 40%; height: 8px"></div>
            <div class="skel skel-line mt-2" style="width: 55%; height: 24px"></div>
            <div class="skel skel-line mt-2" style="width: 100%; height: 4px; border-radius: 9999px"></div>
          </div>
        </div>
        <!-- Table skeleton -->
        <div class="rounded-xl overflow-hidden mb-4" style="border: 1px solid var(--border)">
          <div class="flex px-3 py-2" style="background: var(--card-alt); border-bottom: 1px solid var(--border)">
            <div v-for="c in 7" :key="'hcol-'+c" class="flex-1"><div class="skel skel-line" style="width: 50%; height: 8px"></div></div>
          </div>
          <div v-for="r in 6" :key="'row-'+r" class="flex items-center px-3 py-3" :style="{ background: r%2 ? 'transparent' : 'rgba(22,32,64,0.2)', borderBottom: '1px solid rgba(22,32,64,0.4)' }">
            <div class="flex-1"><div class="skel skel-line" style="width: 40%; height: 10px"></div></div>
            <div class="flex-1 flex items-center justify-center gap-2">
              <div class="skel" style="width: 22px; height: 22px; border-radius: 50%"></div>
              <div class="skel skel-line" style="width: 28px; height: 10px"></div>
            </div>
            <div class="flex-1"><div class="skel skel-line mx-auto" style="width: 45%; height: 9px"></div></div>
            <div class="flex-1"><div class="skel skel-line mx-auto" style="width: 50%; height: 9px"></div></div>
            <div class="flex-1"><div class="skel skel-line mx-auto" style="width: 35%; height: 9px"></div></div>
            <div class="flex-1"><div class="skel skel-line mx-auto" style="width: 30%; height: 9px"></div></div>
            <div class="flex-1 flex justify-end"><div class="skel skel-line" style="width: 50%; height: 14px; border-radius: 6px"></div></div>
          </div>
        </div>
        <!-- Violations panel skeleton -->
        <div class="rounded-xl p-4" style="background: var(--card); border: 1px solid var(--border)">
          <div class="skel skel-line mb-3" style="width: 30%; height: 12px"></div>
          <div v-for="v in 3" :key="'vio-'+v" class="rounded-lg p-2.5 flex items-center gap-2 mb-2" style="background: rgba(22,32,64,0.25)">
            <div class="skel" style="width: 20px; height: 14px; border-radius: 4px"></div>
            <div class="flex-1 space-y-1">
              <div class="skel skel-line" style="width: 70%; height: 9px"></div>
              <div class="skel skel-line" style="width: 50%; height: 7px"></div>
            </div>
            <div class="skel" style="width: 28px; height: 14px; border-radius: 4px"></div>
          </div>
        </div>
      </div>
      <!-- Right column skeleton -->
      <div>
        <div class="rounded-xl p-4 mb-3" style="background: var(--card); border: 1px solid var(--border)">
          <div class="skel skel-line mb-3" style="width: 50%; height: 12px"></div>
          <div v-for="l in 5" :key="'log-'+l" class="flex gap-2 items-start mb-2">
            <div class="skel shrink-0" style="width: 6px; height: 6px; border-radius: 50%; margin-top: 4px"></div>
            <div class="flex-1 space-y-1">
              <div class="skel skel-line" style="width: 85%; height: 8px"></div>
              <div class="skel skel-line" style="width: 35%; height: 6px"></div>
            </div>
          </div>
        </div>
        <div class="rounded-xl p-4" style="background: var(--card); border: 1px solid var(--border)">
          <div class="skel skel-line mb-3" style="width: 55%; height: 12px"></div>
          <div class="grid grid-cols-2 gap-2">
            <div v-for="d in 4" :key="'data-'+d" class="rounded-lg p-2" style="background: var(--surface)">
              <div class="skel skel-line" style="width: 50%; height: 7px"></div>
              <div class="skel skel-line mt-1.5" style="width: 70%; height: 12px"></div>
            </div>
          </div>
        </div>
      </div>

      <!-- Live status pill — shows spinner + message + elapsed seconds so the
           user knows the app is actually working and how long they've waited. -->
      <div class="fixed bottom-6 right-6 inline-flex items-center gap-2 px-3 py-2 rounded-full shadow-lg"
        style="background: var(--card); border: 1px solid var(--border); z-index: 40">
        <SpinnerIcon size="12" />
        <span class="text-[11px] font-semibold" style="color: var(--text)">Validating wave structure</span>
        <span v-if="loadingElapsed > 0" class="text-[10px]"
          :style="{ color: loadingElapsed > 15 ? '#fbbf24' : 'var(--dim)', fontFamily: 'var(--mono)' }">• {{ loadingElapsed }}s</span>
      </div>
      <!-- If the request is running very long, reassure the user with context.
           First validation legitimately takes 60-90s (full engine sweep).
           Subsequent loads are cached and return in <1s. -->
      <div v-if="loadingElapsed >= 30"
        class="fixed bottom-20 right-6 max-w-xs text-[10px] rounded-lg p-2.5 shadow-lg"
        style="background: rgba(251,191,36,0.1); border: 1px solid rgba(251,191,36,0.3); color: #fbbf24; z-index: 40">
        First-run validation runs every engine across 6 timeframes — expect up to 90 seconds. Subsequent loads are cached.
      </div>
    </div>

    <!-- Full-screen overlay loader for long-running Regenerate action -->
    <transition name="fade">
      <div v-if="regenerating" class="fixed inset-0 flex items-center justify-center"
        style="background: rgba(5, 8, 20, 0.72); backdrop-filter: blur(4px); z-index: 60">
        <div class="rounded-2xl p-6 text-center" style="background: var(--card); border: 1px solid var(--border); min-width: 280px">
          <div class="flex justify-center mb-3">
            <SpinnerIcon size="36" />
          </div>
          <div class="text-sm font-bold mb-1" style="color: var(--text)">Regenerating all waves</div>
          <div class="text-[11px]" style="color: var(--dim)">Running every engine across all timeframes. This may take up to a minute.</div>
        </div>
      </div>
    </transition>
  </div>
</template>

<style scoped>
/* Skeleton shimmer — subtle left-to-right sheen */
.skel {
  position: relative;
  overflow: hidden;
  background: var(--surface, #0f1629);
  border-radius: 4px;
}
.skel::after {
  content: '';
  position: absolute;
  inset: 0;
  transform: translateX(-100%);
  background-image: linear-gradient(
    90deg,
    rgba(255, 255, 255, 0) 0%,
    rgba(124, 58, 237, 0.08) 30%,
    rgba(37, 99, 235, 0.12) 50%,
    rgba(124, 58, 237, 0.08) 70%,
    rgba(255, 255, 255, 0) 100%
  );
  animation: shimmer 1.6s infinite;
}
.skel-line { display: block; }
@keyframes shimmer {
  100% { transform: translateX(100%); }
}

/* Pill buttons */
.btn-pill {
  border-radius: 9999px;
  padding: 6px 14px;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.2px;
  transition: transform 0.15s ease, box-shadow 0.15s ease, opacity 0.15s ease;
  box-shadow: 0 1px 0 rgba(255,255,255,0.04) inset, 0 2px 6px rgba(0,0,0,0.25);
}
.btn-pill:hover:not(:disabled) {
  transform: translateY(-1px);
  box-shadow: 0 1px 0 rgba(255,255,255,0.06) inset, 0 4px 10px rgba(0,0,0,0.3);
}
.btn-pill:disabled {
  opacity: 0.65;
  cursor: not-allowed;
}

/* Spinner animation — rotates the dashed circle */
.spinner-icon {
  animation: spinner-rotate 0.9s linear infinite;
  color: currentColor;
  flex-shrink: 0;
}
@keyframes spinner-rotate {
  to { transform: rotate(360deg); }
}

/* Fade transition for the regenerate overlay */
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
