<script setup>
import { ref, reactive, onMounted, computed } from 'vue'
import axios from 'axios'
import SymbolSelector from '../components/shared/SymbolSelector.vue'

const symbols = ref([])
const selectedSymbol = ref(null)
const scanResult = ref(null)
const scanning = ref(false)
const fillingTf = ref('')
const fillProgress = reactive({})  // { tf: { status, filled, total, pct, startTime, elapsed } }
const activityLog = ref([])

const tfOrder = ['1M', '5M', '15M', '1H', '4H', '1D']

onMounted(async () => {
  const { data } = await axios.get('/api/v1/chart/symbols')
  symbols.value = data
  if (data.length) {
    selectedSymbol.value = data[0].id
    await smartScan()
  }
})

function addLog(msg, color = '#34d399') {
  activityLog.value.unshift({ msg, color, time: new Date().toLocaleTimeString() })
  if (activityLog.value.length > 30) activityLog.value.pop()
}

async function smartScan() {
  if (!selectedSymbol.value) return
  scanning.value = true
  addLog('Scanning 6 timeframes...', '#7c3aed')
  try {
    const { data } = await axios.post('/api/v1/gaps/scan', { symbol_id: selectedSymbol.value })
    scanResult.value = data
    addLog(`Smart Scan completed — found ${data.totalGaps} gaps`, '#34d399')
  } catch (e) {
    addLog(`Scan failed: ${e.message}`, '#ef5350')
  } finally {
    scanning.value = false
  }
}

async function fillGap(tf) {
  if (!selectedSymbol.value || fillingTf.value) return
  fillingTf.value = tf

  const tfData = scanResult.value?.timeframes?.[tf]
  const totalMissing = tfData?.gaps?.reduce((s, g) => s + g.missingCandles, 0) || 0

  fillProgress[tf] = { status: 'filling', filled: 0, total: totalMissing, pct: 0, startTime: Date.now(), elapsed: '0s' }
  addLog(`${tf} gap fill started — ${totalMissing} candles to fetch`, '#2563eb')

  // Simulate progress ticks while API runs
  const progressTimer = setInterval(() => {
    if (fillProgress[tf]?.status === 'filling') {
      const elapsed = ((Date.now() - fillProgress[tf].startTime) / 1000).toFixed(1)
      fillProgress[tf].elapsed = `${elapsed}s`
      // Gradual progress while waiting
      if (fillProgress[tf].pct < 90) {
        fillProgress[tf].pct = Math.min(90, fillProgress[tf].pct + 2)
        fillProgress[tf].filled = Math.floor(totalMissing * fillProgress[tf].pct / 100)
      }
    }
  }, 200)

  try {
    const { data } = await axios.post('/api/v1/gaps/fill', { symbol_id: selectedSymbol.value, timeframe: tf })

    clearInterval(progressTimer)
    const elapsed = ((Date.now() - fillProgress[tf].startTime) / 1000).toFixed(1)
    const actualFilled = data.filled || 0

    if (data.success) {
      fillProgress[tf] = { status: 'done', filled: actualFilled, total: totalMissing, pct: 100, elapsed: `${elapsed}s` }
      addLog(`✓ ${tf} filled — ${actualFilled} candles fetched in ${elapsed}s`, '#34d399')
    } else {
      fillProgress[tf] = { status: 'error', filled: 0, total: totalMissing, pct: 0, elapsed: `${elapsed}s` }
      addLog(`✕ ${tf}: ${data.message}`, '#ef5350')
    }

    // Refresh scan data after fill
    await smartScan()
  } catch (e) {
    clearInterval(progressTimer)
    fillProgress[tf] = { ...fillProgress[tf], status: 'error' }
    addLog(`✕ ${tf} fill failed: ${e.response?.data?.message || e.message}`, '#ef5350')
  } finally {
    fillingTf.value = ''
  }
}

async function fillAll() {
  if (!scanResult.value) return
  const tfsWithGaps = tfOrder.filter(tf => (scanResult.value.timeframes[tf]?.gapCount ?? 0) > 0)
  addLog(`Auto-Fill All started — ${tfsWithGaps.length} timeframes`, '#7c3aed')
  for (const tf of tfsWithGaps) {
    await fillGap(tf)
  }
  addLog('Auto-Fill All completed!', '#34d399')
}

const totalMissing = computed(() => {
  if (!scanResult.value) return 0
  return Object.values(scanResult.value.timeframes).reduce((s, tf) =>
    s + (tf.gaps?.reduce((gs, g) => gs + g.missingCandles, 0) || 0), 0)
})

const totalFilled = computed(() => {
  return Object.values(fillProgress).reduce((s, p) => s + (p.status === 'done' ? p.total : 0), 0)
})

const overallPct = computed(() => {
  const t = totalMissing.value
  return t > 0 ? Math.round(totalFilled.value / t * 100) : 0
})

function formatTime(iso) {
  if (!iso) return ''
  return new Date(iso).toLocaleString('en-IN', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true })
}

function formatDuration(mins) {
  if (!mins) return '—'
  if (mins < 60) return `${mins}m`
  const h = Math.floor(mins / 60)
  const m = mins % 60
  return m > 0 ? `${h}h ${m}m` : `${h}h`
}

function gapStatus(tf) {
  return fillProgress[tf]?.status || 'pending'
}
</script>

<template>
  <div class="p-4 max-w-7xl mx-auto">
    <!-- Header -->
    <div class="flex items-center justify-between mb-5">
      <div>
        <h1 class="text-2xl font-bold" style="color: var(--text)">Data Gaps</h1>
        <p class="mt-1 text-xs" style="color: var(--muted)">Visual gap detection — see exactly where data is missing</p>
      </div>
      <div class="flex items-center gap-2">
        <SymbolSelector
          :symbols="symbols"
          v-model="selectedSymbol"
          @change="smartScan()"
          compact
        />
        <button @click="smartScan" :disabled="scanning"
          class="rounded-md px-3 py-1.5 text-[11px] font-bold"
          style="background: #2563eb; color: #fff">
          {{ scanning ? '⟳ Scanning...' : '🔍 Smart Scan' }}
        </button>
        <button @click="fillAll" :disabled="!scanResult?.totalGaps || fillingTf"
          class="rounded-md px-3 py-1.5 text-[11px] font-bold"
          style="background: #059669; color: #fff">
          🔧 Auto-Fill All
        </button>
      </div>
    </div>

    <div v-if="scanResult" class="grid gap-4" style="grid-template-columns: 1fr 320px">
      <!-- LEFT: Timeline + Gap Cards -->
      <div>
        <!-- Visual Timeline -->
        <div class="rounded-xl p-4 mb-4" style="background: var(--card); border: 1px solid var(--border)">
          <div class="flex justify-between items-center mb-3">
            <h3 class="text-sm font-bold" style="color: var(--text)">📊 Gap Timeline</h3>
            <span class="text-[10px]" style="color: var(--dim)">{{ scanResult.marketType }} market</span>
          </div>

          <div v-for="tf in tfOrder" :key="tf" class="flex items-center gap-2 mb-1.5">
            <div class="w-7 text-right font-extrabold text-[11px]" style="font-family: var(--mono); color: var(--text)">{{ tf }}</div>
            <div class="flex-1 flex h-4 rounded overflow-hidden" style="background: var(--surface); border: 1px solid var(--border)">
              <template v-if="scanResult.timeframes[tf]?.timeline?.length">
                <div v-for="(seg, i) in scanResult.timeframes[tf].timeline" :key="i"
                  :style="{
                    width: seg.widthPct + '%',
                    background: seg.type === 'ok' ? 'rgba(52,211,153,0.12)' : 'repeating-linear-gradient(45deg,rgba(239,83,80,0.2),rgba(239,83,80,0.2) 3px,rgba(239,83,80,0.05) 3px,rgba(239,83,80,0.05) 6px)',
                    borderLeft: seg.type === 'gap' ? '2px solid var(--bear)' : 'none',
                    borderRight: seg.type === 'gap' ? '2px solid var(--bear)' : 'none',
                    display: 'flex', alignItems: 'center', justifyContent: 'center',
                  }"
                  :title="seg.type === 'gap' ? `${formatTime(seg.start)} → ${formatTime(seg.end)}` : ''">
                  <span v-if="seg.type === 'gap' && seg.widthPct > 6" class="text-[6px] font-extrabold" style="color: var(--bear)">GAP</span>
                </div>
              </template>
            </div>
            <span class="w-8 text-right text-[10px] font-extrabold"
              :style="{ color: (scanResult.timeframes[tf]?.healthPct ?? 0) >= 95 ? 'var(--bull)' : (scanResult.timeframes[tf]?.healthPct ?? 0) >= 80 ? '#fbbf24' : 'var(--bear)' }">
              {{ scanResult.timeframes[tf]?.healthPct ?? 0 }}%
            </span>
            <span class="w-12 text-right text-[9px] font-bold"
              :style="{ color: (scanResult.timeframes[tf]?.gapCount ?? 0) > 0 ? 'var(--bear)' : 'var(--bull)' }">
              {{ (scanResult.timeframes[tf]?.gapCount ?? 0) > 0 ? scanResult.timeframes[tf].gapCount + ' gap' + (scanResult.timeframes[tf].gapCount > 1 ? 's' : '') : '✓ OK' }}
            </span>
          </div>
        </div>

        <!-- Gap Cards -->
        <div class="space-y-3">
          <template v-for="tf in tfOrder" :key="'card-'+tf">
            <template v-for="(gap, gi) in (scanResult.timeframes[tf]?.gaps || [])" :key="tf+'-'+gi">
              <div class="rounded-xl p-3.5 relative overflow-hidden" style="background: var(--card)"
                :style="{ border: gapStatus(tf) === 'done' ? '1px solid rgba(52,211,153,0.3)' : '1px solid var(--border)' }">

                <!-- Top progress line -->
                <div v-if="gapStatus(tf) !== 'pending'" class="absolute top-0 left-0 h-[3px] rounded-r transition-all duration-300"
                  :style="{ width: (fillProgress[tf]?.pct || 0) + '%', background: gapStatus(tf) === 'done' ? '#34d399' : 'linear-gradient(90deg,#059669,#34d399)' }"></div>

                <!-- Header -->
                <div class="flex justify-between items-start mb-2">
                  <div class="flex items-center gap-1.5">
                    <span class="font-extrabold text-xs" style="font-family: var(--mono); color: var(--text)">{{ tf }}</span>
                    <span class="rounded px-1.5 py-0.5 text-[8px] font-bold"
                      :style="{ background: gap.gapType === 'trailing' ? 'rgba(239,83,80,0.15)' : 'rgba(251,191,36,0.15)', color: gap.gapType === 'trailing' ? 'var(--bear)' : '#fbbf24' }">
                      {{ gap.gapType === 'trailing' ? 'Trailing' : 'Internal' }}
                    </span>
                    <!-- Status badge -->
                    <span v-if="gapStatus(tf) === 'filling'" class="rounded px-1.5 py-0.5 text-[8px] font-bold animate-pulse"
                      style="background: rgba(37,99,235,0.15); color: #2563eb">⟳ FILLING...</span>
                    <span v-else-if="gapStatus(tf) === 'done'" class="rounded px-1.5 py-0.5 text-[8px] font-bold"
                      style="background: rgba(52,211,153,0.15); color: #34d399">✓ FILLED</span>
                    <span v-else-if="gapStatus(tf) === 'error'" class="rounded px-1.5 py-0.5 text-[8px] font-bold"
                      style="background: rgba(239,83,80,0.15); color: var(--bear)">✕ FAILED</span>
                  </div>
                  <div v-if="gapStatus(tf) === 'pending'">
                    <button @click="fillGap(tf)" :disabled="!!fillingTf"
                      class="rounded px-2.5 py-1 text-[9px] font-bold" style="background: #059669; color: #fff">🔧 Fill</button>
                  </div>
                  <span v-else class="text-[10px] font-bold" :style="{ color: gapStatus(tf) === 'done' ? '#34d399' : '#2563eb' }">
                    {{ fillProgress[tf]?.pct || 0 }}%
                  </span>
                </div>

                <!-- Time range -->
                <div class="text-[11px] mb-2" style="font-family: var(--mono); color: var(--text)">
                  {{ formatTime(gap.gapStart) }} <span style="color: var(--dim)">→</span> {{ formatTime(gap.gapEnd) }}
                </div>

                <!-- Stats row -->
                <div class="flex gap-4 mb-2">
                  <div>
                    <span class="text-[8px]" style="color: var(--dim)">Duration</span>
                    <div class="text-xs font-bold" style="color: #fbbf24">{{ formatDuration(gap.durationMinutes) }}</div>
                  </div>
                  <div>
                    <span class="text-[8px]" style="color: var(--dim)">Missing</span>
                    <div class="text-xs font-bold" style="color: var(--bear)">{{ gap.missingCandles }}</div>
                  </div>
                  <div v-if="gapStatus(tf) === 'filling' || gapStatus(tf) === 'done'">
                    <span class="text-[8px]" style="color: var(--dim)">Filled</span>
                    <div class="text-xs font-bold" style="color: var(--bull)">{{ fillProgress[tf]?.filled || 0 }}</div>
                  </div>
                  <div v-if="gapStatus(tf) === 'filling' || gapStatus(tf) === 'done'">
                    <span class="text-[8px]" style="color: var(--dim)">Time</span>
                    <div class="text-xs font-bold" style="color: var(--muted)">{{ fillProgress[tf]?.elapsed || '—' }}</div>
                  </div>
                </div>

                <!-- Progress bar (filling/done) -->
                <div v-if="gapStatus(tf) !== 'pending'" class="rounded-full overflow-hidden" style="height: 5px; background: var(--surface)">
                  <div class="h-full rounded-full transition-all duration-300"
                    :style="{ width: (fillProgress[tf]?.pct || 0) + '%', background: gapStatus(tf) === 'done' ? '#34d399' : 'linear-gradient(90deg,#059669,#34d399)' }"></div>
                </div>
                <div v-if="gapStatus(tf) === 'filling'" class="flex justify-between mt-1">
                  <span class="text-[8px]" style="color: var(--dim)">Fetching from exchange...</span>
                  <span class="text-[8px]" style="color: var(--bull)">{{ fillProgress[tf]?.filled || 0 }}/{{ fillProgress[tf]?.total || 0 }}</span>
                </div>
                <div v-if="gapStatus(tf) === 'done'" class="text-right mt-1">
                  <span class="text-[8px]" style="color: var(--bull)">Completed in {{ fillProgress[tf]?.elapsed }}</span>
                </div>
              </div>
            </template>
          </template>

          <!-- No gaps -->
          <div v-if="!scanResult.totalGaps" class="rounded-xl p-8 text-center" style="background: var(--card); border: 1px solid var(--border)">
            <div class="text-3xl mb-2">✅</div>
            <div class="text-sm font-bold" style="color: var(--bull)">All data complete!</div>
          </div>
        </div>
      </div>

      <!-- RIGHT: Status Panel -->
      <div>
        <!-- Scan Summary -->
        <div class="rounded-xl p-4 mb-3" style="background: var(--card); border: 1px solid var(--border)">
          <h3 class="text-xs font-bold mb-3" style="color: var(--text)">📋 Scan Summary</h3>
          <div class="grid grid-cols-2 gap-2 mb-3">
            <div class="rounded-lg p-2 text-center" style="background: var(--surface)">
              <div class="text-[8px] uppercase" style="color: var(--dim)">Gaps</div>
              <div class="text-xl font-black" style="color: var(--bear)">{{ scanResult.totalGaps }}</div>
            </div>
            <div class="rounded-lg p-2 text-center" style="background: var(--surface)">
              <div class="text-[8px] uppercase" style="color: var(--dim)">Missing</div>
              <div class="text-xl font-black" style="color: #fbbf24">{{ totalMissing.toLocaleString() }}</div>
            </div>
            <div class="rounded-lg p-2 text-center" style="background: var(--surface)">
              <div class="text-[8px] uppercase" style="color: var(--dim)">Filled</div>
              <div class="text-xl font-black" style="color: var(--bull)">{{ totalFilled.toLocaleString() }}</div>
            </div>
            <div class="rounded-lg p-2 text-center" style="background: var(--surface)">
              <div class="text-[8px] uppercase" style="color: var(--dim)">Remaining</div>
              <div class="text-xl font-black" style="color: #fbbf24">{{ (totalMissing - totalFilled).toLocaleString() }}</div>
            </div>
          </div>
          <div>
            <div class="flex justify-between mb-1">
              <span class="text-[9px]" style="color: var(--dim)">Overall Progress</span>
              <span class="text-[9px] font-bold" style="color: var(--bull)">{{ overallPct }}%</span>
            </div>
            <div class="rounded-full overflow-hidden" style="height: 6px; background: var(--surface)">
              <div class="h-full rounded-full transition-all duration-500"
                :style="{ width: overallPct + '%', background: 'linear-gradient(90deg,#059669,#34d399)' }"></div>
            </div>
          </div>
        </div>

        <!-- Activity Log -->
        <div class="rounded-xl p-4 mb-3" style="background: var(--card); border: 1px solid var(--border)">
          <h3 class="text-xs font-bold mb-3" style="color: var(--text)">📝 Activity Log</h3>
          <div class="space-y-1.5 max-h-80 overflow-y-auto">
            <div v-for="(log, i) in activityLog" :key="i" class="flex gap-2 items-start">
              <div class="w-1.5 h-1.5 rounded-full mt-1.5 shrink-0" :style="{ background: log.color }"></div>
              <div>
                <div class="text-[10px] font-semibold" :style="{ color: log.color }">{{ log.msg }}</div>
                <div class="text-[8px]" style="color: var(--dim)">{{ log.time }}</div>
              </div>
            </div>
            <div v-if="!activityLog.length" class="text-[10px] text-center py-4" style="color: var(--dim)">No activity yet</div>
          </div>
        </div>

        <!-- Market Hours -->
        <div class="rounded-xl p-3" style="background: var(--card); border: 1px solid var(--border)">
          <h3 class="text-[10px] font-bold mb-2" style="color: #7c3aed">📅 Market Hours</h3>
          <div class="space-y-1">
            <div class="flex justify-between text-[9px]">
              <span class="font-bold" style="color: #fbbf24">Binance</span><span style="color: var(--muted)">24/7</span>
            </div>
            <div class="flex justify-between text-[9px]">
              <span class="font-bold" style="color: var(--bull)">NSE</span><span style="color: var(--muted)">9:15-15:30 IST</span>
            </div>
            <div class="flex justify-between text-[9px]">
              <span class="font-bold" style="color: #2563eb">Forex</span><span style="color: var(--muted)">Sun-Fri</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="!scanResult && scanning" class="text-center py-20">
      <div class="text-sm" style="color: var(--dim)">⟳ Scanning for data gaps across all timeframes...</div>
    </div>
  </div>
</template>
