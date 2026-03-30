<script setup>
import { ref, reactive, onMounted, computed } from 'vue'
import axios from 'axios'
import SymbolSelector from '../components/shared/SymbolSelector.vue'
import { useChartStore } from '../stores/useChartStore'

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
  addLog('Validating all timeframes...', '#7c3aed')
  try {
    const { data } = await axios.post('/api/v1/wave-health/validate', { symbol_id: selectedSymbol.value })
    validationResult.value = data
    addLog(`Validation done — health ${data.overallHealth}%, ${data.totalViolations} violations`, '#34d399')
  } catch (e) {
    addLog(`Validation failed: ${e.message}`, '#ef5350')
  } finally {
    loading.value = false
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
          class="rounded-md px-3 py-1.5 text-[11px] font-bold"
          :style="{ background: loading ? '#1e40af' : '#2563eb', color: '#fff' }">
          {{ loading ? '⟳ Validating...' : '🔍 Validate' }}
        </button>
        <button @click="autoFixAll" :disabled="!allViolations.length || fixingTf"
          class="rounded-md px-3 py-1.5 text-[11px] font-bold"
          style="background: #059669; color: #fff">
          🔧 Auto-Fix All
        </button>
        <button @click="regenerateAll" :disabled="regenerating"
          class="rounded-md px-3 py-1.5 text-[11px] font-bold"
          :style="{ background: regenerating ? '#6d28d9' : '#7c3aed', color: '#fff' }">
          {{ regenerating ? '⟳ Regenerating...' : '🔄 Regenerate Waves' }}
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
                    <span class="text-[9px] font-bold animate-pulse" style="color: #2563eb">⟳ Testing 7 params...</span>
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
                <span class="text-[8px] font-bold animate-pulse shrink-0" style="color: #2563eb">Testing...</span>
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
                <span v-else-if="r.status === 'fixing'" class="text-[8px] font-bold animate-pulse" style="color: #2563eb">⟳ TESTING</span>
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

    <!-- Loading -->
    <div v-if="!validationResult && loading" class="text-center py-20">
      <div class="text-sm animate-pulse" style="color: var(--dim)">⟳ Validating wave structure...</div>
    </div>
  </div>
</template>
