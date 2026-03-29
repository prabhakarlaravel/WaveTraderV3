<script setup>
import { ref, onMounted, computed } from 'vue'
import axios from 'axios'

const symbols = ref([])
const selectedSymbol = ref(null)
const validationResult = ref(null)
const loading = ref(false)
const validating = ref(false)
const fixing = ref('')
const fixLog = ref([])

onMounted(async () => {
  const { data } = await axios.get('/api/v1/chart/symbols')
  symbols.value = data
  if (data.length) {
    selectedSymbol.value = data[0].id
    await runValidation()
  }
})

async function runValidation() {
  if (!selectedSymbol.value) return
  validating.value = true
  loading.value = true
  try {
    const { data } = await axios.post('/api/v1/wave-health/validate', {
      symbol_id: selectedSymbol.value,
    })
    validationResult.value = data
  } finally {
    validating.value = false
    loading.value = false
  }
}

async function autoFix(timeframe) {
  if (!selectedSymbol.value) return
  fixing.value = timeframe
  try {
    const { data } = await axios.post('/api/v1/wave-health/fix', {
      symbol_id: selectedSymbol.value,
      timeframe,
    })
    fixLog.value.unshift({
      time: new Date().toLocaleTimeString(),
      message: data.message,
      fixed: data.fixed,
      timeframe,
      oldScore: data.oldScore,
      newScore: data.newScore,
    })
    // Refresh validation after fix
    await runValidation()
  } finally {
    fixing.value = ''
  }
}

async function autoFixAll() {
  if (!validationResult.value) return
  const fixableTfs = validationResult.value.timeframes
    .filter(tf => tf.fixable)
    .map(tf => tf.timeframe)
  for (const tf of fixableTfs) {
    await autoFix(tf)
  }
}

const allViolations = computed(() => {
  if (!validationResult.value) return []
  const violations = []
  for (const tf of validationResult.value.timeframes) {
    for (const v of (tf.violations || [])) {
      violations.push({ ...v, timeframe: tf.timeframe, fixable: tf.fixable })
    }
  }
  return violations
})

function scoreColor(score) {
  if (score >= 90) return 'var(--bull)'
  if (score >= 70) return '#fbbf24'
  return 'var(--bear)'
}
</script>

<template>
  <div class="p-4 max-w-6xl mx-auto">
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
          class="rounded-md px-3 py-1.5 text-[11px] font-bold"
          style="background: #2563eb; color: #fff">
          {{ validating ? '⟳ Validating...' : '🔍 Validate All' }}
        </button>
        <button @click="autoFixAll" :disabled="!validationResult?.fixableCount"
          class="rounded-md px-3 py-1.5 text-[11px] font-bold"
          style="background: #059669; color: #fff">
          🔧 Auto-Fix All
        </button>
      </div>
    </div>

    <!-- Summary Cards -->
    <div v-if="validationResult" class="grid grid-cols-4 gap-3 mb-5">
      <div class="rounded-lg p-3 text-center" style="background: var(--card); border: 1px solid var(--border)">
        <div class="text-[9px] uppercase tracking-widest" style="color: var(--dim)">Overall Health</div>
        <div class="text-3xl font-black mt-1" :style="{ color: scoreColor(validationResult.overallHealth) }">
          {{ validationResult.overallHealth }}%
        </div>
        <div class="mt-2 rounded-full overflow-hidden" style="height: 4px; background: var(--surface)">
          <div class="h-full rounded-full transition-all duration-700"
            :style="{ width: validationResult.overallHealth + '%', background: scoreColor(validationResult.overallHealth) }"></div>
        </div>
      </div>
      <div class="rounded-lg p-3 text-center" style="background: var(--card); border: 1px solid var(--border)">
        <div class="text-[9px] uppercase tracking-widest" style="color: var(--dim)">Violations Found</div>
        <div class="text-3xl font-black mt-1" :style="{ color: validationResult.totalViolations > 0 ? 'var(--bear)' : 'var(--bull)' }">
          {{ validationResult.totalViolations }}
        </div>
        <div class="text-[10px] mt-1" style="color: var(--bear)" v-if="validationResult.totalViolations">
          {{ validationResult.criticalCount }} critical · {{ validationResult.warningCount }} warning
        </div>
        <div class="text-[10px] mt-1" style="color: var(--bull)" v-else>All rules pass</div>
      </div>
      <div class="rounded-lg p-3 text-center" style="background: var(--card); border: 1px solid var(--border)">
        <div class="text-[9px] uppercase tracking-widest" style="color: var(--dim)">Data Integrity</div>
        <div class="text-3xl font-black mt-1"
          :style="{ color: validationResult.dataIntegrity.gaps === 0 && validationResult.dataIntegrity.zero_volume === 0 ? 'var(--bull)' : '#fbbf24' }">
          {{ validationResult.dataIntegrity.gaps === 0 && validationResult.dataIntegrity.zero_volume === 0 ? '✓' : '⚠' }}
        </div>
        <div class="text-[10px] mt-1" style="color: var(--muted)">
          {{ validationResult.dataIntegrity.total_candles.toLocaleString() }} candles · {{ validationResult.dataIntegrity.gaps }} gaps
        </div>
      </div>
      <div class="rounded-lg p-3 text-center" style="background: var(--card); border: 1px solid var(--border)">
        <div class="text-[9px] uppercase tracking-widest" style="color: var(--dim)">Auto-Fixable</div>
        <div class="text-3xl font-black mt-1" :style="{ color: validationResult.fixableCount > 0 ? '#fbbf24' : 'var(--bull)' }">
          {{ validationResult.fixableCount }}
        </div>
        <div class="text-[10px] mt-1" style="color: var(--muted)">
          {{ validationResult.fixableCount > 0 ? 'Can be recalibrated' : 'All optimal' }}
        </div>
      </div>
    </div>

    <!-- Timeframe Table -->
    <div v-if="validationResult" class="rounded-xl overflow-hidden mb-5" style="border: 1px solid var(--border)">
      <table class="w-full text-left text-xs">
        <thead style="background: var(--card-alt)">
          <tr style="border-bottom: 1px solid var(--border)">
            <th class="px-3 py-2.5 font-semibold" style="color: var(--dim)">TF</th>
            <th class="px-3 py-2.5 font-semibold text-center" style="color: var(--dim)">Health</th>
            <th class="px-3 py-2.5 font-semibold text-center" style="color: var(--dim)">Status</th>
            <th class="px-3 py-2.5 font-semibold text-center" style="color: var(--dim)">Trend</th>
            <th class="px-3 py-2.5 font-semibold text-center" style="color: var(--dim)">Violations</th>
            <th class="px-3 py-2.5 font-semibold text-center" style="color: var(--dim)">Data</th>
            <th class="px-3 py-2.5 font-semibold text-center" style="color: var(--dim)">Swing</th>
            <th class="px-3 py-2.5 font-semibold text-right" style="color: var(--dim)">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="tf in validationResult.timeframes" :key="tf.timeframe"
            style="border-bottom: 1px solid rgba(22,32,64,0.4)">
            <td class="px-3 py-2.5 font-bold text-sm" style="font-family: var(--mono); color: var(--text)">{{ tf.timeframe }}</td>
            <td class="px-3 py-2.5 text-center">
              <div class="inline-flex items-center gap-1.5">
                <svg width="26" height="26" viewBox="0 0 32 32">
                  <circle cx="16" cy="16" r="13" fill="none" stroke="var(--surface)" stroke-width="3" />
                  <circle cx="16" cy="16" r="13" fill="none"
                    :stroke="scoreColor(tf.score)" stroke-width="3"
                    :stroke-dasharray="`${81.7 * tf.score / 100} ${81.7 * (1 - tf.score / 100)}`"
                    stroke-linecap="round" transform="rotate(-90 16 16)" />
                  <text x="16" y="19" text-anchor="middle" :fill="scoreColor(tf.score)"
                    font-size="8" font-weight="800" font-family="var(--mono)">{{ tf.score }}</text>
                </svg>
                <span class="font-bold" style="font-family: var(--mono)" :style="{ color: scoreColor(tf.score) }">{{ tf.score }}/100</span>
              </div>
            </td>
            <td class="px-3 py-2.5 text-center">
              <span class="text-[10px] font-semibold" :style="{ color: tf.status === 'valid' ? 'var(--bull)' : tf.status === 'caution' ? '#fbbf24' : 'var(--bear)' }">
                {{ tf.status === 'valid' ? '● Valid' : tf.status === 'caution' ? '◐ Caution' : '✕ Invalid' }}
              </span>
            </td>
            <td class="px-3 py-2.5 text-center">
              <span class="text-[10px] font-bold" style="font-family: var(--mono)"
                :style="{ color: tf.trend === 'bullish' ? 'var(--bull)' : tf.trend === 'bearish' ? 'var(--bear)' : 'var(--dim)' }">
                {{ tf.trend === 'bullish' ? '↗' : tf.trend === 'bearish' ? '↘' : '→' }} {{ tf.trend?.toUpperCase() }}
              </span>
            </td>
            <td class="px-3 py-2.5 text-center">
              <span v-if="tf.violations?.length" class="text-[10px] font-semibold" style="color: var(--bear)">
                ⚠ {{ tf.violations.length }} found
              </span>
              <span v-else class="text-[10px]" style="color: var(--bull)">✓ None</span>
            </td>
            <td class="px-3 py-2.5 text-center">
              <span class="text-[10px]" :style="{ color: tf.data_check?.is_clean ? 'var(--bull)' : '#fbbf24' }">
                {{ tf.data_check?.label || '—' }}
              </span>
            </td>
            <td class="px-3 py-2.5 text-center" style="font-family: var(--mono); color: var(--muted)">{{ tf.swing_strength }}</td>
            <td class="px-3 py-2.5 text-right">
              <button v-if="tf.fixable" @click="autoFix(tf.timeframe)" :disabled="fixing === tf.timeframe"
                class="rounded px-2 py-1 text-[9px] font-bold mr-1"
                style="background: var(--bear); color: #fff">
                {{ fixing === tf.timeframe ? '⟳...' : '🔧 Fix' }}
              </button>
              <span v-if="tf.best_alt?.improved" class="text-[9px]" style="color: #fbbf24">
                → sw{{ tf.best_alt.best }} ({{ tf.best_alt.bestScore }})
              </span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Bottom: Violations + Fix Log -->
    <div v-if="validationResult" class="grid grid-cols-2 gap-4">
      <!-- Violations -->
      <div class="rounded-xl p-4" style="background: var(--card); border: 1px solid var(--border)">
        <h3 class="text-sm font-bold mb-3" style="color: var(--bear)">⚠ Rule Violations</h3>
        <div v-if="allViolations.length" class="space-y-2">
          <div v-for="(v, i) in allViolations" :key="i"
            class="flex items-center gap-2 rounded-lg p-2.5"
            style="background: rgba(239,83,80,0.06); border: 1px solid rgba(239,83,80,0.15)">
            <span class="rounded px-1.5 py-0.5 text-[9px] font-extrabold"
              style="background: var(--bear); color: #fff">Rule {{ v.rule }}</span>
            <div class="flex-1 min-w-0">
              <div class="text-[11px] font-semibold" style="color: var(--text)">{{ v.timeframe }} — {{ v.description }}</div>
              <div class="text-[9px] mt-0.5" style="color: var(--dim)">{{ v.severity }}</div>
            </div>
            <button v-if="v.fixable" @click="autoFix(v.timeframe)"
              class="rounded px-2 py-1 text-[9px] font-bold shrink-0"
              style="background: var(--bear); color: #fff">Auto-Fix</button>
          </div>
        </div>
        <div v-else class="text-xs py-4 text-center" style="color: var(--dim)">✓ No violations found</div>
      </div>

      <!-- Fix Log + Data Integrity -->
      <div class="rounded-xl p-4" style="background: var(--card); border: 1px solid var(--border)">
        <h3 class="text-sm font-bold mb-3" style="color: var(--bull)">🔧 Fix Log</h3>
        <div v-if="fixLog.length" class="space-y-2 mb-4">
          <div v-for="(log, i) in fixLog" :key="i"
            class="rounded-lg p-2.5"
            :style="{ background: log.fixed ? 'rgba(52,211,153,0.06)' : 'rgba(251,191,36,0.06)',
                       border: log.fixed ? '1px solid rgba(52,211,153,0.15)' : '1px solid rgba(251,191,36,0.15)' }">
            <div class="flex justify-between items-center">
              <span class="text-[11px] font-semibold" :style="{ color: log.fixed ? 'var(--bull)' : '#fbbf24' }">
                {{ log.fixed ? '✓' : '⟳' }} {{ log.message }}
              </span>
              <span class="text-[9px]" style="color: var(--dim)">{{ log.time }}</span>
            </div>
          </div>
        </div>
        <div v-else class="text-xs py-2 text-center mb-4" style="color: var(--dim)">No fixes applied yet</div>

        <!-- Data Integrity -->
        <h3 class="text-sm font-bold mb-3" style="color: #2563eb">📊 Data Integrity</h3>
        <div v-if="validationResult.dataIntegrity" class="grid grid-cols-2 gap-2">
          <div class="rounded-lg p-2" style="background: var(--surface); border: 1px solid var(--border)">
            <div class="text-[9px]" style="color: var(--dim)">Candle Count</div>
            <div class="text-base font-extrabold" style="color: var(--text)">{{ validationResult.dataIntegrity.total_candles?.toLocaleString() }}</div>
          </div>
          <div class="rounded-lg p-2" style="background: var(--surface); border: 1px solid var(--border)">
            <div class="text-[9px]" style="color: var(--dim)">Zero Volume</div>
            <div class="text-base font-extrabold" :style="{ color: validationResult.dataIntegrity.zero_volume === 0 ? 'var(--bull)' : 'var(--bear)' }">
              {{ validationResult.dataIntegrity.zero_volume }}
            </div>
          </div>
          <div class="rounded-lg p-2" style="background: var(--surface); border: 1px solid var(--border)">
            <div class="text-[9px]" style="color: var(--dim)">Data Gaps</div>
            <div class="text-base font-extrabold" :style="{ color: validationResult.dataIntegrity.gaps === 0 ? 'var(--bull)' : '#fbbf24' }">
              {{ validationResult.dataIntegrity.gaps }}
            </div>
          </div>
          <div class="rounded-lg p-2" style="background: var(--surface); border: 1px solid var(--border)">
            <div class="text-[9px]" style="color: var(--dim)">Duplicates</div>
            <div class="text-base font-extrabold" :style="{ color: validationResult.dataIntegrity.duplicates === 0 ? 'var(--bull)' : 'var(--bear)' }">
              {{ validationResult.dataIntegrity.duplicates }}
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Loading state -->
    <div v-if="!validationResult && loading" class="text-center py-20">
      <div class="text-sm" style="color: var(--dim)">⟳ Validating wave structure across all timeframes...</div>
    </div>
  </div>
</template>
