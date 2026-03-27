<script setup>
import { ref, onMounted } from 'vue'
import axios from 'axios'

const symbols = ref([])
const selectedSymbol = ref(null)
const healthData = ref([])
const loading = ref(false)

onMounted(async () => {
  const { data } = await axios.get('/api/v1/chart/symbols')
  symbols.value = data
  if (data.length) {
    selectedSymbol.value = data[0].id
    await fetchHealth()
  }
})

async function fetchHealth() {
  if (!selectedSymbol.value) return
  loading.value = true
  try {
    const { data } = await axios.get('/api/v1/wave-health', {
      params: { symbol_id: selectedSymbol.value },
    })
    healthData.value = data
  } finally {
    loading.value = false
  }
}

function scoreColor(score) {
  if (score >= 75) return 'var(--bull)'
  if (score >= 50) return 'var(--ob)'
  return 'var(--bear)'
}

function statusBg(status) {
  if (status === 'valid') return 'rgba(0,220,130,0.1)'
  if (status === 'caution') return 'rgba(245,158,11,0.1)'
  if (status === 'invalidated') return 'rgba(255,59,92,0.1)'
  return 'var(--surface)'
}

function statusColor(status) {
  if (status === 'valid') return 'var(--bull)'
  if (status === 'caution') return 'var(--ob)'
  if (status === 'invalidated') return 'var(--bear)'
  return 'var(--dim)'
}

function trendArrow(trend) {
  if (trend === 'bullish') return '↗'
  if (trend === 'bearish') return '↘'
  return '→'
}
</script>

<template>
  <div class="p-4 max-w-5xl mx-auto">
    <h1 class="text-2xl font-bold" style="color: var(--text)">Wave Health</h1>
    <p class="mt-1 text-sm" style="color: var(--muted)">Elliott Wave rule validation and health scoring per symbol and timeframe.</p>

    <!-- Symbol selector -->
    <div class="flex items-center gap-3 mt-6">
      <select v-model="selectedSymbol" @change="fetchHealth()"
        class="rounded-md px-3 py-2 text-sm"
        style="background: var(--card); border: 1px solid var(--border); color: var(--text)">
        <option v-for="s in symbols" :key="s.id" :value="s.id">{{ s.ticker }}</option>
      </select>
      <button @click="fetchHealth" :disabled="loading"
        class="rounded-md px-4 py-2 text-xs font-semibold"
        style="background: var(--accent); color: #fff">
        {{ loading ? 'Analyzing...' : 'Refresh' }}
      </button>
    </div>

    <!-- Health Table -->
    <div class="mt-6 rounded-xl overflow-hidden" style="border: 1px solid var(--border)">
      <table class="w-full text-left text-sm">
        <thead style="background: var(--card)">
          <tr style="border-bottom: 1px solid var(--border)">
            <th class="px-4 py-3 font-medium" style="color: var(--dim)">Timeframe</th>
            <th class="px-4 py-3 font-medium" style="color: var(--dim)">Health Score</th>
            <th class="px-4 py-3 font-medium" style="color: var(--dim)">Status</th>
            <th class="px-4 py-3 font-medium" style="color: var(--dim)">Trend</th>
            <th class="px-4 py-3 font-medium" style="color: var(--dim)">Waves</th>
            <th class="px-4 py-3 font-medium" style="color: var(--dim)">Swings</th>
            <th class="px-4 py-3 font-medium" style="color: var(--dim)">BOS</th>
            <th class="px-4 py-3 font-medium" style="color: var(--dim)">Violations</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="h in healthData" :key="h.timeframe" style="border-bottom: 1px solid rgba(22,32,64,0.5)">
            <td class="px-4 py-3 font-bold" style="font-family: var(--mono); color: var(--text)">{{ h.timeframe }}</td>
            <td class="px-4 py-3">
              <div class="flex items-center gap-2">
                <!-- Gauge ring -->
                <svg width="32" height="32" viewBox="0 0 32 32">
                  <circle cx="16" cy="16" r="13" fill="none" stroke="var(--border)" stroke-width="3" />
                  <circle cx="16" cy="16" r="13" fill="none"
                    :stroke="scoreColor(h.score)" stroke-width="3"
                    :stroke-dasharray="`${81.7 * h.score / 100} ${81.7 * (1 - h.score / 100)}`"
                    stroke-linecap="round" transform="rotate(-90 16 16)" />
                  <text x="16" y="19" text-anchor="middle"
                    :fill="scoreColor(h.score)"
                    font-size="9" font-weight="700" font-family="var(--mono)">{{ h.score }}</text>
                </svg>
                <span class="text-xs font-bold" style="font-family: var(--mono)" :style="{ color: scoreColor(h.score) }">
                  {{ h.score }}/100
                </span>
              </div>
            </td>
            <td class="px-4 py-3">
              <span class="rounded-full px-2.5 py-1 text-[10px] font-semibold"
                :style="{ background: statusBg(h.status), color: statusColor(h.status) }">
                {{ h.status === 'no_data' ? 'No Data' : h.status?.charAt(0).toUpperCase() + h.status?.slice(1) }}
              </span>
            </td>
            <td class="px-4 py-3">
              <span class="text-sm font-bold" style="font-family: var(--mono)"
                :style="{ color: h.trend === 'bullish' ? 'var(--bull)' : h.trend === 'bearish' ? 'var(--bear)' : 'var(--dim)' }">
                {{ trendArrow(h.trend) }} {{ h.trend?.toUpperCase() }}
              </span>
            </td>
            <td class="px-4 py-3 text-xs" style="font-family: var(--mono); color: var(--muted)">{{ h.wave_count }}</td>
            <td class="px-4 py-3 text-xs" style="font-family: var(--mono); color: var(--muted)">{{ h.swing_count || 0 }}</td>
            <td class="px-4 py-3 text-xs" style="font-family: var(--mono); color: var(--muted)">{{ h.bos_count || 0 }}</td>
            <td class="px-4 py-3">
              <span v-if="h.violations?.length" class="text-[10px] font-semibold" style="color: var(--bear)">
                {{ h.violations.length }} violation{{ h.violations.length > 1 ? 's' : '' }}
              </span>
              <span v-else class="text-[10px]" style="color: var(--dim)">None</span>
            </td>
          </tr>

          <tr v-if="!healthData.length">
            <td colspan="8" class="px-4 py-8 text-center text-sm" style="color: var(--dim)">
              {{ loading ? 'Analyzing wave structure...' : 'Select a symbol and click Refresh' }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Violations Detail -->
    <div v-if="healthData.some(h => h.violations?.length)" class="mt-6 rounded-xl p-5"
      style="background: var(--card); border: 1px solid var(--border)">
      <h3 class="text-sm font-semibold mb-3" style="color: var(--bear)">Rule Violations</h3>
      <div class="space-y-2">
        <div v-for="h in healthData.filter(h => h.violations?.length)" :key="h.timeframe">
          <div v-for="(v, i) in h.violations" :key="i"
            class="flex items-center gap-3 rounded-lg p-3"
            style="background: rgba(255,59,92,0.06); border: 1px solid rgba(255,59,92,0.15)">
            <span class="text-xs font-bold" style="font-family: var(--mono); color: var(--text)">{{ h.timeframe }}</span>
            <span class="rounded-full px-2 py-0.5 text-[10px] font-bold"
              style="background: rgba(255,59,92,0.2); color: var(--bear)">Rule {{ v.rule }}</span>
            <span class="text-xs" style="color: var(--muted)">{{ v.description }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Rules Reference -->
    <div class="mt-6 rounded-xl p-5" style="background: var(--card); border: 1px solid var(--border)">
      <h3 class="text-xs font-semibold uppercase tracking-wider mb-4" style="color: var(--dim)">Elliott Wave Rules</h3>
      <div class="space-y-3 text-sm" style="color: var(--muted)">
        <div class="flex gap-3">
          <span class="text-xs font-bold" style="font-family: var(--mono); color: var(--accent)">Rule 2</span>
          <span>Wave 2 must not retrace below the start of Wave 1</span>
        </div>
        <div class="flex gap-3">
          <span class="text-xs font-bold" style="font-family: var(--mono); color: var(--accent)">Rule 3</span>
          <span>Wave 3 must not be the shortest impulse wave (1, 3, 5)</span>
        </div>
        <div class="flex gap-3">
          <span class="text-xs font-bold" style="font-family: var(--mono); color: var(--accent)">Rule 4</span>
          <span>Wave 4 must not overlap with Wave 1 price territory</span>
        </div>
      </div>
    </div>
  </div>
</template>
