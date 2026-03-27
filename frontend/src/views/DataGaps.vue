<script setup>
import { ref, onMounted } from 'vue'
import axios from 'axios'

const symbols = ref([])
const selectedSymbol = ref(null)
const selectedTf = ref('1H')
const gaps = ref([])
const health = ref([])
const scanning = ref(false)
const filling = ref(false)
const loaded = ref(false)

const timeframes = ['1M', '5M', '15M', '1H', '4H', '1D']

onMounted(async () => {
  const { data } = await axios.get('/api/v1/chart/symbols')
  symbols.value = data
  if (data.length) selectedSymbol.value = data[0].id
  await fetchHealth()
  await fetchGaps()
  loaded.value = true
})

async function fetchGaps() {
  const params = {}
  if (selectedSymbol.value) params.symbol_id = selectedSymbol.value
  if (selectedTf.value) params.timeframe = selectedTf.value
  const { data } = await axios.get('/api/v1/gaps', { params })
  gaps.value = data.data || data || []
}

async function fetchHealth() {
  const { data } = await axios.get('/api/v1/gaps/health')
  health.value = data
}

async function scanGaps() {
  if (!selectedSymbol.value) return
  scanning.value = true
  try {
    await axios.post('/api/v1/gaps/scan', {
      symbol_id: selectedSymbol.value,
      timeframe: selectedTf.value,
    })
    await fetchGaps()
    await fetchHealth()
  } finally {
    scanning.value = false
  }
}

async function fillGaps() {
  if (!selectedSymbol.value) return
  filling.value = true
  try {
    await axios.post('/api/v1/gaps/fill', {
      symbol_id: selectedSymbol.value,
      timeframe: selectedTf.value,
    })
    await fetchGaps()
    await fetchHealth()
  } finally {
    filling.value = false
  }
}

function formatDate(ts) {
  if (!ts) return '--'
  return new Date(ts).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
}
</script>

<template>
  <div class="p-4 max-w-5xl mx-auto">
    <h1 class="text-2xl font-bold" style="color: var(--text)">Data Gaps</h1>
    <p class="mt-1 text-sm" style="color: var(--muted)">Detect and fill missing candle data across symbols and timeframes.</p>

    <!-- Controls -->
    <div class="flex items-center gap-3 mt-6 flex-wrap">
      <select v-model="selectedSymbol" @change="fetchGaps()"
        class="rounded-md px-3 py-2 text-sm"
        style="background: var(--card); border: 1px solid var(--border); color: var(--text)">
        <option v-for="s in symbols" :key="s.id" :value="s.id">{{ s.ticker }}</option>
      </select>

      <div class="flex gap-1 rounded-md p-0.5" style="background: var(--card); border: 1px solid var(--border)">
        <button v-for="tf in timeframes" :key="tf" @click="selectedTf = tf; fetchGaps()"
          class="px-3 py-1.5 rounded text-[10px] font-bold"
          :style="selectedTf === tf ? 'background: var(--border-hi); color: var(--text)' : 'color: var(--dim)'"
          style="font-family: var(--mono)">{{ tf }}</button>
      </div>

      <button @click="scanGaps" :disabled="scanning"
        class="rounded-md px-4 py-2 text-xs font-semibold"
        style="background: var(--accent); color: #fff">
        {{ scanning ? 'Scanning...' : 'Scan for Gaps' }}
      </button>

      <button @click="fillGaps" :disabled="filling"
        class="rounded-md px-4 py-2 text-xs font-semibold"
        style="background: var(--surface); border: 1px solid var(--border); color: var(--muted)">
        {{ filling ? 'Filling...' : 'Fill All Gaps' }}
      </button>
    </div>

    <!-- Health Summary -->
    <div class="grid gap-4 md:grid-cols-3 mt-6">
      <div v-for="h in health" :key="h.symbol" class="rounded-xl p-5"
        style="background: var(--card); border: 1px solid var(--border)">
        <div class="flex items-center justify-between mb-3">
          <span class="text-sm font-semibold" style="font-family: var(--mono); color: var(--text)">{{ h.symbol }}</span>
          <span class="rounded-full px-2.5 py-1 text-[10px] font-semibold"
            :style="h.health_pct >= 90 ? 'background: rgba(0,220,130,0.1); color: var(--bull)' : h.health_pct >= 50 ? 'background: rgba(245,158,11,0.1); color: var(--ob)' : 'background: rgba(255,59,92,0.1); color: var(--bear)'">
            {{ h.health_pct }}%
          </span>
        </div>
        <div class="h-2 rounded-full" style="background: var(--border)">
          <div class="h-2 rounded-full transition-all"
            :style="{ width: h.health_pct + '%', background: h.health_pct >= 90 ? 'var(--bull)' : h.health_pct >= 50 ? 'var(--ob)' : 'var(--bear)' }"></div>
        </div>
        <div class="flex justify-between mt-2 text-[10px]" style="color: var(--dim); font-family: var(--mono)">
          <span>{{ h.total_gaps }} total gaps</span>
          <span>{{ h.unfilled_gaps }} unfilled</span>
        </div>
      </div>

      <div v-if="!health.length" class="col-span-3 text-center py-8 text-sm" style="color: var(--dim)">
        No gap data. Click "Scan for Gaps" to detect missing candles.
      </div>
    </div>

    <!-- Gap List -->
    <div class="mt-6 rounded-xl overflow-hidden" style="border: 1px solid var(--border)" v-if="gaps.length">
      <table class="w-full text-left text-sm">
        <thead style="background: var(--card); border-bottom: 1px solid var(--border)">
          <tr>
            <th class="px-4 py-3 font-medium" style="color: var(--dim)">Symbol</th>
            <th class="px-4 py-3 font-medium" style="color: var(--dim)">Timeframe</th>
            <th class="px-4 py-3 font-medium" style="color: var(--dim)">Gap Start</th>
            <th class="px-4 py-3 font-medium" style="color: var(--dim)">Gap End</th>
            <th class="px-4 py-3 font-medium" style="color: var(--dim)">Status</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="g in gaps" :key="g.id" style="border-bottom: 1px solid var(--border)">
            <td class="px-4 py-3" style="font-family: var(--mono); color: var(--text)">{{ g.symbol?.ticker || '--' }}</td>
            <td class="px-4 py-3" style="color: var(--muted)">{{ g.timeframe }}</td>
            <td class="px-4 py-3 text-xs" style="font-family: var(--mono); color: var(--muted)">{{ formatDate(g.gap_start) }}</td>
            <td class="px-4 py-3 text-xs" style="font-family: var(--mono); color: var(--muted)">{{ formatDate(g.gap_end) }}</td>
            <td class="px-4 py-3">
              <span v-if="g.filled_at" class="rounded-full px-2 py-0.5 text-[10px] font-semibold"
                style="background: rgba(0,220,130,0.1); color: var(--bull)">Filled</span>
              <span v-else class="rounded-full px-2 py-0.5 text-[10px] font-semibold"
                style="background: rgba(255,59,92,0.1); color: var(--bear)">Unfilled</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Empty state -->
    <div v-else-if="loaded" class="mt-8 rounded-xl p-12 text-center"
      style="border: 1px dashed var(--border)">
      <p class="text-lg" style="color: var(--dim)">No gaps detected</p>
      <p class="mt-1 text-sm" style="color: var(--border-hi)">Run a gap scan to check for missing candle data.</p>
    </div>
  </div>
</template>
