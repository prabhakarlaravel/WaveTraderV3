<script setup>
import { ref, reactive, onMounted, computed } from 'vue'
import axios from 'axios'

const symbols = ref([])
const selectedSymbol = ref(null)
const validationResult = ref(null)
const loading = ref(false)
const validating = ref(false)
const fixingTf = ref('')
const fixResults = reactive({}) // { tf: { status, message, oldScore, newScore } }
const activityLog = ref([])

onMounted(async () => {
  const { data } = await axios.get('/api/v1/chart/symbols')
  symbols.value = data
  if (data.length) {
    selectedSymbol.value = data[0].id
    await runValidation()
  }
})

function addLog(msg, color = '#34d399') {
  activityLog.value.unshift({ msg, color, time: new Date().toLocaleTimeString() })
  if (activityLog.value.length > 20) activityLog.value.pop()
}

async function runValidation() {
  if (!selectedSymbol.value) return
  validating.value = true
  loading.value = true
  addLog('Validating all timeframes...', '#7c3aed')
  try {
    const { data } = await axios.post('/api/v1/wave-health/validate', { symbol_id: selectedSymbol.value })
    validationResult.value = data
    addLog(`Validation complete — ${data.totalViolations} violations, health ${data.overallHealth}%`, '#34d399')
  } catch (e) {
    addLog(`Validation failed: ${e.message}`, '#ef5350')
  } finally {
    validating.value = false
    loading.value = false
  }
}

async function autoFix(timeframe) {
  if (!selectedSymbol.value || fixingTf.value) return
  fixingTf.value = timeframe
  fixResults[timeframe] = { status: 'fixing' }
  addLog(`Attempting fix for ${timeframe} — testing 7 parameter sets...`, '#2563eb')

  try {
    const { data } = await axios.post('/api/v1/wave-health/fix', { symbol_id: selectedSymbol.value, timeframe })

    if (data.fixed) {
      fixResults[timeframe] = { status: 'fixed', message: data.message, oldScore: data.oldScore, newScore: data.newScore }
      addLog(`✓ ${timeframe} fixed: ${data.message}`, '#34d399')
    } else {
      fixResults[timeframe] = { status: 'no_fix', message: data.message, suggestion: data.suggestion }
      addLog(`⚠ ${timeframe}: ${data.message}`, '#fbbf24')
    }

    // Refresh validation
    await runValidation()
  } catch (e) {
    fixResults[timeframe] = { status: 'error', message: e.message }
    addLog(`✕ ${timeframe} fix error: ${e.message}`, '#ef5350')
  } finally {
    fixingTf.value = ''
  }
}

async function autoFixAll() {
  if (!validationResult.value) return
  const fixableTfs = validationResult.value.timeframes.filter(tf => tf.violations?.length > 0).map(tf => tf.timeframe)
  addLog(`Auto-Fix All — attempting ${fixableTfs.length} timeframes`, '#7c3aed')
  for (const tf of fixableTfs) {
    await autoFix(tf)
  }
  addLog('Auto-Fix All completed', '#34d399')
}

function scoreColor(score) {
  if (score >= 90) return '#34d399'
  if (score >= 70) return '#fbbf24'
  return '#ef5350'
}

function fixStatus(tf) {
  return fixResults[tf]?.status || 'idle'
}

const allViolations = computed(() => {
  if (!validationResult.value) return []
  const v = []
  for (const tf of validationResult.value.timeframes) {
    for (const violation of (tf.violations || [])) {
      v.push({ ...violation, timeframe: tf.timeframe, tfScore: tf.score })
    }
  }
  return v
})
</script>

<template>
  <div class="p-4 max-w-7xl mx-auto">
    <!-- Header -->
    <div class="flex items-center justify-between mb-5">
      <div>
        <h1 class="text-2xl font-bold" style="color: var(--text)">Wave Health</h1>
        <p class="mt-1 text-xs" style="color: var(--muted)">Elliott Wave rule validation and health scoring per symbol and timeframe</p>
      </div>
      <div class="flex items-center gap-2">
        <select v-model="selectedSymbol" @change="runValidation()"
          class="rounded-md px-3 py-1.5 text-xs"
          style="background: var(--card); border: 1px solid var(--border); color: var(--text)">
          <option v-for="s in symbols" :key="s.id" :value="s.id">{{ s.ticker }}</option>
        </select>
        <button @click="runValidation" :disabled="validating"
          class="rounded-md px-3 py-1.5 text-[11px] font-bold transition-all"
          :style="{ background: validating ? '#1e40af' : '#2563eb', color: '#fff', opacity: validating ? 0.7 : 1 }">
          {{ validating ? '⟳ Validating...' : '🔍 Validate All' }}
        </button>
        <button @click="autoFixAll" :disabled="!allViolations.length || fixingTf"
          class="rounded-md px-3 py-1.5 text-[11px] font-bold"
          style="background: #059669; color: #fff">
          🔧 Auto-Fix All
        </button>
      </div>
    </div>

    <div v-if="validationResult" class="grid gap-4" style="grid-template-columns: 1fr 300px">
      <!-- LEFT: Summary + Table + Violations -->
      <div>
        <!-- Summary Cards -->
        <div class="grid grid-cols-4 gap-3 mb-4">
          <div class="rounded-lg p-3 text-center" style="background: var(--card); border: 1px solid var(--border)">
            <div class="text-[8px] uppercase tracking-widest" style="color: var(--dim)">Overall Health</div>
            <div class="text-3xl font-black mt-1 transition-all" :style="{ color: scoreColor(validationResult.overallHealth) }">
              {{ validationResult.overallHealth }}%
            </div>
            <div class="mt-2 rounded-full overflow-hidden" style="height: 4px; background: var(--surface)">
              <div class="h-full rounded-full transition-all duration-1000"
                :style="{ width: validationResult.overallHealth + '%', background: scoreColor(validationResult.overallHealth) }"></div>
            </div>
          </div>
          <div class="rounded-lg p-3 text-center" style="background: var(--card); border: 1px solid var(--border)">
            <div class="text-[8px] uppercase tracking-widest" style="color: var(--dim)">Violations</div>
            <div class="text-3xl font-black mt-1" :style="{ color: validationResult.totalViolations > 0 ? '#ef5350' : '#34d399' }">
              {{ validationResult.totalViolations }}
            </div>
            <div class="text-[9px] mt-1" :style="{ color: validationResult.totalViolations > 0 ? '#ef5350' : '#34d399' }">
              {{ validationResult.totalViolations > 0 ? `${validationResult.criticalCount} critical` : 'All rules pass' }}
            </div>
          </div>
          <div class="rounded-lg p-3 text-center" style="background: var(--card); border: 1px solid var(--border)">
            <div class="text-[8px] uppercase tracking-widest" style="color: var(--dim)">Data</div>
            <div class="text-3xl font-black mt-1"
              :style="{ color: validationResult.dataIntegrity.gaps === 0 ? '#34d399' : '#fbbf24' }">
              {{ validationResult.dataIntegrity.gaps === 0 ? '✓' : '⚠' }}
            </div>
            <div class="text-[9px] mt-1" style="color: var(--muted)">{{ validationResult.dataIntegrity.total_candles.toLocaleString() }} candles</div>
          </div>
          <div class="rounded-lg p-3 text-center" style="background: var(--card); border: 1px solid var(--border)">
            <div class="text-[8px] uppercase tracking-widest" style="color: var(--dim)">Fixable</div>
            <div class="text-3xl font-black mt-1" :style="{ color: validationResult.fixableCount > 0 ? '#fbbf24' : '#34d399' }">
              {{ validationResult.fixableCount }}
            </div>
            <div class="text-[9px] mt-1" style="color: var(--muted)">
              {{ validationResult.fixableCount > 0 ? 'Can recalibrate' : 'All optimal' }}
            </div>
          </div>
        </div>

        <!-- Timeframe Table -->
        <div class="rounded-xl overflow-hidden mb-4" style="border: 1px solid var(--border)">
          <table class="w-full text-left text-xs">
            <thead style="background: var(--card-alt)">
              <tr style="border-bottom: 1px solid var(--border)">
                <th class="px-3 py-2 font-semibold" style="color: var(--dim)">TF</th>
                <th class="px-3 py-2 font-semibold text-center" style="color: var(--dim)">Health</th>
                <th class="px-3 py-2 font-semibold text-center" style="color: var(--dim)">Wave</th>
                <th class="px-3 py-2 font-semibold text-center" style="color: var(--dim)">Trend</th>
                <th class="px-3 py-2 font-semibold text-center" style="color: var(--dim)">Violations</th>
                <th class="px-3 py-2 font-semibold text-center" style="color: var(--dim)">Data</th>
                <th class="px-3 py-2 font-semibold text-right" style="color: var(--dim)">Action</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="tf in validationResult.timeframes" :key="tf.timeframe"
                class="transition-all duration-300"
                :style="{ borderBottom: '1px solid rgba(22,32,64,0.4)', background: fixStatus(tf.timeframe) === 'fixed' ? 'rgba(52,211,153,0.05)' : fixStatus(tf.timeframe) === 'no_fix' ? 'rgba(251,191,36,0.03)' : 'transparent' }">
                <td class="px-3 py-2 font-bold text-sm" style="font-family: var(--mono); color: var(--text)">{{ tf.timeframe }}</td>
                <td class="px-3 py-2 text-center">
                  <div class="inline-flex items-center gap-1.5">
                    <svg width="24" height="24" viewBox="0 0 32 32">
                      <circle cx="16" cy="16" r="13" fill="none" stroke="var(--surface)" stroke-width="3" />
                      <circle cx="16" cy="16" r="13" fill="none"
                        :stroke="scoreColor(tf.score)" stroke-width="3"
                        :stroke-dasharray="`${81.7 * tf.score / 100} ${81.7 * (1 - tf.score / 100)}`"
                        stroke-linecap="round" transform="rotate(-90 16 16)">
                        <animate attributeName="stroke-dasharray" :from="`0 81.7`" :to="`${81.7 * tf.score / 100} ${81.7 * (1 - tf.score / 100)}`" dur="1s" fill="freeze" />
                      </circle>
                    </svg>
                    <span class="font-bold" style="font-family: var(--mono)" :style="{ color: scoreColor(tf.score) }">{{ tf.score }}</span>
                  </div>
                </td>
                <td class="px-3 py-2 text-center">
                  <span v-if="tf.current_wave" class="text-[10px] font-bold" style="font-family: var(--mono); color: var(--text)">
                    W{{ tf.current_wave }} <span style="color: var(--dim)">({{ tf.phase || '—' }})</span>
                  </span>
                  <span v-else class="text-[10px]" style="color: var(--dim)">—</span>
                </td>
                <td class="px-3 py-2 text-center">
                  <span class="text-[10px] font-bold" style="font-family: var(--mono)"
                    :style="{ color: tf.trend === 'bullish' ? '#34d399' : tf.trend === 'bearish' ? '#ef5350' : 'var(--dim)' }">
                    {{ tf.trend === 'bullish' ? '↗' : tf.trend === 'bearish' ? '↘' : '→' }} {{ tf.trend?.toUpperCase() }}
                  </span>
                </td>
                <td class="px-3 py-2 text-center">
                  <span v-if="tf.violations?.length" class="text-[10px] font-bold" style="color: #ef5350">
                    ⚠ {{ tf.violations.length }}
                  </span>
                  <span v-else class="text-[10px] font-bold" style="color: #34d399">✓</span>
                </td>
                <td class="px-3 py-2 text-center">
                  <span class="text-[10px]" :style="{ color: tf.data_check?.is_clean ? '#34d399' : '#fbbf24' }">
                    {{ tf.data_check?.label || '—' }}
                  </span>
                </td>
                <td class="px-3 py-2 text-right">
                  <!-- Fix button with states -->
                  <template v-if="fixStatus(tf.timeframe) === 'fixing'">
                    <span class="text-[9px] font-bold animate-pulse" style="color: #2563eb">⟳ Testing...</span>
                  </template>
                  <template v-else-if="fixStatus(tf.timeframe) === 'fixed'">
                    <span class="text-[9px] font-bold" style="color: #34d399">✓ Fixed</span>
                  </template>
                  <template v-else-if="fixStatus(tf.timeframe) === 'no_fix'">
                    <span class="text-[9px] font-bold" style="color: #fbbf24" :title="fixResults[tf.timeframe]?.message">⚡ Optimal</span>
                  </template>
                  <template v-else-if="tf.violations?.length">
                    <button @click="autoFix(tf.timeframe)" :disabled="!!fixingTf"
                      class="rounded px-2 py-0.5 text-[9px] font-bold transition-all hover:opacity-80"
                      style="background: #ef5350; color: #fff">🔧 Fix</button>
                  </template>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Violations Detail -->
        <div v-if="allViolations.length" class="rounded-xl p-4" style="background: var(--card); border: 1px solid var(--border)">
          <h3 class="text-sm font-bold mb-3" style="color: #ef5350">⚠ Rule Violations</h3>
          <div class="space-y-2">
            <div v-for="(v, i) in allViolations" :key="i"
              class="rounded-lg p-2.5 flex items-center gap-2 transition-all duration-300"
              :style="{ background: fixStatus(v.timeframe) === 'fixed' ? 'rgba(52,211,153,0.06)' : 'rgba(239,83,80,0.04)', border: fixStatus(v.timeframe) === 'fixed' ? '1px solid rgba(52,211,153,0.15)' : '1px solid rgba(239,83,80,0.12)' }">
              <span class="rounded px-1.5 py-0.5 text-[8px] font-extrabold"
                :style="{ background: fixStatus(v.timeframe) === 'fixed' ? '#34d399' : '#ef5350', color: '#fff' }">
                Rule {{ v.rule }}
              </span>
              <div class="flex-1 min-w-0">
                <div class="text-[10px] font-semibold" style="color: var(--text)">
                  {{ v.timeframe }} — {{ v.description }}
                </div>
                <div v-if="v.detail" class="text-[9px] mt-0.5" style="color: var(--dim)">{{ v.detail }}</div>
              </div>
              <template v-if="fixStatus(v.timeframe) === 'idle' || fixStatus(v.timeframe) === 'error'">
                <button @click="autoFix(v.timeframe)" :disabled="!!fixingTf"
                  class="rounded px-2 py-0.5 text-[8px] font-bold shrink-0"
                  style="background: #ef5350; color: #fff">Fix</button>
              </template>
              <template v-else-if="fixStatus(v.timeframe) === 'fixing'">
                <span class="text-[8px] font-bold animate-pulse shrink-0" style="color: #2563eb">Testing 7 params...</span>
              </template>
              <template v-else-if="fixStatus(v.timeframe) === 'fixed'">
                <span class="text-[8px] font-bold shrink-0" style="color: #34d399">✓ Resolved</span>
              </template>
              <template v-else-if="fixStatus(v.timeframe) === 'no_fix'">
                <span class="text-[8px] font-bold shrink-0" style="color: #fbbf24">Best possible</span>
              </template>
            </div>
          </div>
        </div>

        <div v-else-if="validationResult" class="rounded-xl p-6 text-center" style="background: var(--card); border: 1px solid var(--border)">
          <div class="text-3xl mb-2">✅</div>
          <div class="text-sm font-bold" style="color: #34d399">All Elliott Wave rules pass!</div>
        </div>
      </div>

      <!-- RIGHT: Activity Log + Data Integrity -->
      <div>
        <!-- Activity Log -->
        <div class="rounded-xl p-4 mb-3" style="background: var(--card); border: 1px solid var(--border)">
          <h3 class="text-xs font-bold mb-3" style="color: var(--text)">📝 Activity Log</h3>
          <div class="space-y-1.5 max-h-64 overflow-y-auto">
            <div v-for="(log, i) in activityLog" :key="i" class="flex gap-2 items-start">
              <div class="w-1.5 h-1.5 rounded-full mt-1.5 shrink-0" :style="{ background: log.color }"></div>
              <div class="min-w-0">
                <div class="text-[10px] font-semibold" :style="{ color: log.color }">{{ log.msg }}</div>
                <div class="text-[8px]" style="color: var(--dim)">{{ log.time }}</div>
              </div>
            </div>
            <div v-if="!activityLog.length" class="text-[10px] text-center py-4" style="color: var(--dim)">No activity yet</div>
          </div>
        </div>

        <!-- Fix Results Summary -->
        <div v-if="Object.keys(fixResults).length" class="rounded-xl p-4 mb-3" style="background: var(--card); border: 1px solid var(--border)">
          <h3 class="text-xs font-bold mb-3" style="color: var(--text)">🔧 Fix Results</h3>
          <div class="space-y-2">
            <div v-for="(result, tf) in fixResults" :key="tf" class="rounded-lg p-2"
              :style="{ background: result.status === 'fixed' ? 'rgba(52,211,153,0.06)' : result.status === 'no_fix' ? 'rgba(251,191,36,0.06)' : 'rgba(239,83,80,0.06)' }">
              <div class="flex items-center gap-1.5 mb-1">
                <span class="font-bold text-[10px]" style="font-family: var(--mono); color: var(--text)">{{ tf }}</span>
                <span v-if="result.status === 'fixed'" class="text-[8px] font-bold" style="color: #34d399">✓ FIXED</span>
                <span v-else-if="result.status === 'no_fix'" class="text-[8px] font-bold" style="color: #fbbf24">⚡ OPTIMAL</span>
                <span v-else-if="result.status === 'fixing'" class="text-[8px] font-bold animate-pulse" style="color: #2563eb">⟳ TESTING</span>
                <span v-else class="text-[8px] font-bold" style="color: #ef5350">✕ ERROR</span>
              </div>
              <div class="text-[9px]" style="color: var(--muted)">{{ result.message }}</div>
              <div v-if="result.suggestion" class="text-[8px] mt-1" style="color: #fbbf24">💡 {{ result.suggestion }}</div>
            </div>
          </div>
        </div>

        <!-- Data Integrity -->
        <div v-if="validationResult.dataIntegrity" class="rounded-xl p-4" style="background: var(--card); border: 1px solid var(--border)">
          <h3 class="text-xs font-bold mb-3" style="color: #2563eb">📊 Data Integrity</h3>
          <div class="grid grid-cols-2 gap-2">
            <div class="rounded-lg p-2" style="background: var(--surface)">
              <div class="text-[8px]" style="color: var(--dim)">Candles</div>
              <div class="text-sm font-extrabold" style="color: var(--text)">{{ validationResult.dataIntegrity.total_candles?.toLocaleString() }}</div>
            </div>
            <div class="rounded-lg p-2" style="background: var(--surface)">
              <div class="text-[8px]" style="color: var(--dim)">Zero Vol</div>
              <div class="text-sm font-extrabold" :style="{ color: validationResult.dataIntegrity.zero_volume === 0 ? '#34d399' : '#ef5350' }">
                {{ validationResult.dataIntegrity.zero_volume }}
              </div>
            </div>
            <div class="rounded-lg p-2" style="background: var(--surface)">
              <div class="text-[8px]" style="color: var(--dim)">Gaps</div>
              <div class="text-sm font-extrabold" :style="{ color: validationResult.dataIntegrity.gaps === 0 ? '#34d399' : '#fbbf24' }">
                {{ validationResult.dataIntegrity.gaps }}
              </div>
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
      <div class="text-sm animate-pulse" style="color: var(--dim)">⟳ Validating wave structure across all timeframes...</div>
    </div>
  </div>
</template>
