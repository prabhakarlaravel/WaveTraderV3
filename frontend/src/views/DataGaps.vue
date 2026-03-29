<script setup>
import { ref, onMounted } from 'vue'
import axios from 'axios'

const symbols = ref([])
const selectedSymbol = ref(null)
const scanResult = ref(null)
const scanning = ref(false)
const filling = ref('')

onMounted(async () => {
  const { data } = await axios.get('/api/v1/chart/symbols')
  symbols.value = data
  if (data.length) {
    selectedSymbol.value = data[0].id
    await smartScan()
  }
})

async function smartScan() {
  if (!selectedSymbol.value) return
  scanning.value = true
  try {
    const { data } = await axios.post('/api/v1/gaps/scan', { symbol_id: selectedSymbol.value })
    scanResult.value = data
  } finally {
    scanning.value = false
  }
}

async function fillGap(tf) {
  if (!selectedSymbol.value) return
  filling.value = tf
  try {
    await axios.post('/api/v1/gaps/fill', { symbol_id: selectedSymbol.value, timeframe: tf })
    await smartScan()
  } finally {
    filling.value = ''
  }
}

async function fillAll() {
  if (!scanResult.value) return
  const tfsWithGaps = Object.entries(scanResult.value.timeframes)
    .filter(([, v]) => v.gapCount > 0)
    .map(([k]) => k)
  for (const tf of tfsWithGaps) {
    await fillGap(tf)
  }
}

function formatTime(iso) {
  if (!iso) return ''
  return new Date(iso).toLocaleString('en-IN', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true })
}

function formatDuration(mins) {
  if (mins < 60) return `${mins}m`
  const h = Math.floor(mins / 60)
  const m = mins % 60
  return m > 0 ? `${h}h ${m}m` : `${h}h`
}

const tfOrder = ['1M', '5M', '15M', '1H', '4H', '1D']
</script>

<template>
  <div class="p-4 max-w-6xl mx-auto">
    <!-- Header -->
    <div class="flex items-center justify-between mb-5">
      <div>
        <h1 class="text-2xl font-bold" style="color: var(--text)">Data Gaps</h1>
        <p class="mt-1 text-xs" style="color: var(--muted)">Visual gap detection — see exactly where data is missing</p>
      </div>
      <div class="flex items-center gap-2">
        <select v-model="selectedSymbol" @change="smartScan()"
          class="rounded-md px-3 py-1.5 text-xs"
          style="background: var(--card); border: 1px solid var(--border); color: var(--text)">
          <option v-for="s in symbols" :key="s.id" :value="s.id">{{ s.ticker }}</option>
        </select>
        <button @click="smartScan" :disabled="scanning"
          class="rounded-md px-3 py-1.5 text-[11px] font-bold"
          style="background: #2563eb; color: #fff">
          {{ scanning ? '⟳ Scanning...' : '🔍 Smart Scan' }}
        </button>
        <button @click="fillAll" :disabled="!scanResult?.totalGaps"
          class="rounded-md px-3 py-1.5 text-[11px] font-bold"
          style="background: #059669; color: #fff">
          🔧 Auto-Fill All
        </button>
      </div>
    </div>

    <!-- Visual Gap Timeline -->
    <div v-if="scanResult" class="rounded-xl p-4 mb-5" style="background: var(--card); border: 1px solid var(--border)">
      <h3 class="text-sm font-bold mb-3" style="color: var(--text)">
        📊 Gap Timeline — {{ scanResult.symbol }}
        <span class="text-xs font-normal ml-2" style="color: var(--dim)">{{ scanResult.marketType }} market</span>
      </h3>

      <div v-for="tf in tfOrder" :key="tf" class="flex items-center gap-2.5 mb-2.5">
        <div class="w-8 text-right font-extrabold text-xs" style="font-family: var(--mono); color: var(--text)">{{ tf }}</div>

        <div class="flex-1 relative">
          <div class="flex h-5 rounded overflow-hidden" style="background: var(--surface); border: 1px solid var(--border)">
            <template v-if="scanResult.timeframes[tf]?.timeline?.length">
              <div v-for="(seg, i) in scanResult.timeframes[tf].timeline" :key="i"
                :style="{
                  width: seg.widthPct + '%',
                  background: seg.type === 'ok'
                    ? 'rgba(52,211,153,0.15)'
                    : 'repeating-linear-gradient(45deg, rgba(239,83,80,0.25), rgba(239,83,80,0.25) 3px, rgba(239,83,80,0.06) 3px, rgba(239,83,80,0.06) 6px)',
                  borderLeft: seg.type === 'gap' ? '2px solid var(--bear)' : 'none',
                  borderRight: seg.type === 'gap' ? '2px solid var(--bear)' : 'none',
                  display: 'flex', alignItems: 'center', justifyContent: 'center',
                }"
                :title="seg.type === 'gap' ? `Gap: ${formatTime(seg.start)} → ${formatTime(seg.end)}` : 'Data OK'">
                <span v-if="seg.type === 'gap' && seg.widthPct > 5"
                  class="text-[7px] font-extrabold whitespace-nowrap" style="color: var(--bear)">⚠ GAP</span>
              </div>
            </template>
            <div v-else class="w-full h-full flex items-center justify-center">
              <span class="text-[8px]" style="color: var(--dim)">No data</span>
            </div>
          </div>
        </div>

        <div class="w-12 text-right">
          <span class="text-[11px] font-extrabold"
            :style="{ color: (scanResult.timeframes[tf]?.healthPct ?? 0) >= 95 ? 'var(--bull)' : (scanResult.timeframes[tf]?.healthPct ?? 0) >= 80 ? '#fbbf24' : 'var(--bear)' }">
            {{ scanResult.timeframes[tf]?.healthPct ?? 0 }}%
          </span>
        </div>

        <div class="w-16 text-right">
          <span v-if="(scanResult.timeframes[tf]?.gapCount ?? 0) > 0"
            class="rounded px-1.5 py-0.5 text-[9px] font-bold"
            style="background: rgba(239,83,80,0.15); color: var(--bear)">
            {{ scanResult.timeframes[tf].gapCount }} gap{{ scanResult.timeframes[tf].gapCount > 1 ? 's' : '' }}
          </span>
          <span v-else class="text-[9px] font-bold" style="color: var(--bull)">✓ Clean</span>
        </div>

        <div class="w-12 text-right">
          <button v-if="(scanResult.timeframes[tf]?.gapCount ?? 0) > 0"
            @click="fillGap(tf)" :disabled="filling === tf"
            class="rounded px-2 py-0.5 text-[9px] font-bold"
            style="background: #059669; color: #fff">
            {{ filling === tf ? '⟳' : 'Fill' }}
          </button>
        </div>
      </div>

      <div class="flex gap-4 mt-3 pt-2.5" style="border-top: 1px solid var(--border)">
        <div class="flex items-center gap-1.5">
          <div class="w-3 h-2.5 rounded-sm" style="background: rgba(52,211,153,0.15); border: 1px solid rgba(52,211,153,0.3)"></div>
          <span class="text-[9px]" style="color: var(--dim)">Data present</span>
        </div>
        <div class="flex items-center gap-1.5">
          <div class="w-3 h-2.5 rounded-sm"
            style="background: repeating-linear-gradient(45deg, rgba(239,83,80,0.3), rgba(239,83,80,0.3) 2px, rgba(239,83,80,0.08) 2px, rgba(239,83,80,0.08) 4px); border: 1px solid rgba(239,83,80,0.4)"></div>
          <span class="text-[9px]" style="color: var(--dim)">Missing data (gap)</span>
        </div>
      </div>
    </div>

    <!-- Gap Details -->
    <div v-if="scanResult?.groupedGaps?.length" class="rounded-xl p-4 mb-5" style="background: var(--card); border: 1px solid var(--border)">
      <h3 class="text-sm font-bold mb-3" style="color: var(--bear)">
        ⚠ Gap Details ({{ scanResult.groupedGaps.length }} gap{{ scanResult.groupedGaps.length > 1 ? 's' : '' }})
      </h3>
      <div class="space-y-2">
        <div v-for="(gap, i) in scanResult.groupedGaps" :key="i"
          class="rounded-lg p-3 flex items-center gap-3"
          style="background: rgba(239,83,80,0.04); border: 1px solid rgba(239,83,80,0.12)">
          <div class="w-1 self-stretch rounded" :style="{ background: gap.gapType === 'trailing' ? 'var(--bear)' : '#fbbf24' }"></div>
          <div class="flex-1">
            <div class="flex items-center gap-2 mb-1">
              <span class="font-extrabold text-xs" style="font-family: var(--mono); color: var(--text)">
                {{ gap.timeframes.join(' · ') }}
              </span>
              <span class="rounded px-1.5 py-0.5 text-[9px] font-bold"
                :style="{
                  background: gap.gapType === 'trailing' ? 'rgba(239,83,80,0.15)' : 'rgba(251,191,36,0.15)',
                  color: gap.gapType === 'trailing' ? 'var(--bear)' : '#fbbf24'
                }">
                {{ gap.gapType === 'trailing' ? 'Trailing Gap' : 'Internal Gap' }}
              </span>
            </div>
            <div class="text-[11px]" style="color: var(--text); font-family: var(--mono)">
              {{ formatTime(gap.gapStart) }} <span style="color: var(--dim)">→</span> {{ formatTime(gap.gapEnd) }}
            </div>
            <div class="text-[10px] mt-1" style="color: var(--muted)">
              Duration: {{ formatDuration(gap.durationMinutes) }} ·
              Missing:
              <span v-for="(count, tf) in gap.missingByTf" :key="tf" class="ml-1">
                <span style="color: var(--bear); font-weight: 700">{{ count }}</span>
                <span style="color: var(--dim)">({{ tf }})</span>
              </span>
            </div>
          </div>
          <button @click="fillGap(gap.timeframes[0])" :disabled="filling !== ''"
            class="rounded px-3 py-1.5 text-[10px] font-bold shrink-0"
            style="background: #059669; color: #fff">
            🔧 Fill
          </button>
        </div>
      </div>
    </div>

    <div v-else-if="scanResult && !scanResult.groupedGaps?.length"
      class="rounded-xl p-8 text-center mb-5" style="background: var(--card); border: 1px solid var(--border)">
      <div class="text-3xl mb-2">✅</div>
      <div class="text-sm font-bold" style="color: var(--bull)">No gaps detected — all data is complete!</div>
    </div>

    <!-- Market Hours -->
    <div v-if="scanResult" class="rounded-xl p-4" style="background: var(--card); border: 1px solid var(--border)">
      <h3 class="text-xs font-bold mb-2" style="color: #7c3aed">📅 Market Hours (used for gap filtering)</h3>
      <div class="grid grid-cols-4 gap-2">
        <div class="rounded-lg p-2" style="background: var(--surface); border: 1px solid var(--border)">
          <div class="text-[10px] font-bold" style="color: #fbbf24">Binance</div>
          <div class="text-[9px]" style="color: var(--muted)">24/7 — every gap counts</div>
        </div>
        <div class="rounded-lg p-2" style="background: var(--surface); border: 1px solid var(--border)">
          <div class="text-[10px] font-bold" style="color: var(--bull)">NSE</div>
          <div class="text-[9px]" style="color: var(--muted)">Mon-Fri 9:15-15:30 IST</div>
        </div>
        <div class="rounded-lg p-2" style="background: var(--surface); border: 1px solid var(--border)">
          <div class="text-[10px] font-bold" style="color: #2563eb">Forex</div>
          <div class="text-[9px]" style="color: var(--muted)">Sun 10PM - Fri 10PM UTC</div>
        </div>
        <div class="rounded-lg p-2" style="background: var(--surface); border: 1px solid var(--border)">
          <div class="text-[10px] font-bold" style="color: var(--bear)">Yahoo</div>
          <div class="text-[9px]" style="color: var(--muted)">Varies by instrument</div>
        </div>
      </div>
    </div>

    <div v-if="!scanResult && scanning" class="text-center py-20">
      <div class="text-sm" style="color: var(--dim)">⟳ Scanning for data gaps across all timeframes...</div>
    </div>
  </div>
</template>
