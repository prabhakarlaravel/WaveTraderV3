<script setup>
import { ref, onMounted, computed } from 'vue'
import { createChart, CandlestickSeries, LineSeries } from 'lightweight-charts'
import axios from 'axios'

const symbols = ref([])
const selectedSymbol = ref(null)
const selectedTf = ref('1H')
const mode = ref('auto')
const fromDate = ref('')
const toDate = ref('')
const config = ref({ risk_pct: 1, max_positions: 3, initial_capital: 10000 })

const running = ref(false)
const result = ref(null)
const backtests = ref([])

const equityContainer = ref(null)
let equityChart = null

const timeframes = ['1M', '5M', '15M', '1H', '4H', '1D']
const modes = [
  { id: 'auto', label: 'Auto Trade', desc: 'System trades from engine signals' },
  { id: 'replay', label: 'Replay', desc: 'Visual bar-by-bar replay' },
]

onMounted(async () => {
  const { data } = await axios.get('/api/v1/chart/symbols')
  symbols.value = data
  if (data.length) selectedSymbol.value = data[0].id

  // Default date range: last 30 days
  const now = new Date()
  toDate.value = now.toISOString().split('T')[0]
  const from = new Date(now); from.setDate(from.getDate() - 30)
  fromDate.value = from.toISOString().split('T')[0]

  // Load past backtests
  const bt = await axios.get('/api/v1/backtests')
  backtests.value = bt.data.data || bt.data || []
})

async function runBacktest() {
  if (!selectedSymbol.value || !fromDate.value || !toDate.value) return
  running.value = true
  result.value = null

  try {
    const { data } = await axios.post('/api/v1/backtests', {
      symbol_id: selectedSymbol.value,
      timeframe: selectedTf.value,
      from_date: fromDate.value,
      to_date: toDate.value,
      mode: mode.value,
      config: config.value,
    })

    result.value = data.results_json
    backtests.value.unshift(data)

    // Render equity curve
    if (result.value?.equity_curve?.length) {
      renderEquityCurve(result.value.equity_curve)
    }
  } catch (e) {
    console.error('Backtest failed:', e)
  } finally {
    running.value = false
  }
}

function renderEquityCurve(curve) {
  if (!equityContainer.value) return
  if (equityChart) equityChart.remove()

  equityChart = createChart(equityContainer.value, {
    width: equityContainer.value.clientWidth,
    height: 200,
    layout: { background: { color: '#06090f' }, textColor: '#7b8ba8' },
    grid: { vertLines: { color: '#162040' }, horzLines: { color: '#162040' } },
    rightPriceScale: { borderColor: '#162040' },
    timeScale: { visible: false },
  })

  const series = equityChart.addSeries(LineSeries, {
    color: '#00dc82', lineWidth: 2,
    lastValueVisible: true, priceLineVisible: false,
  })

  const data = curve.map((v, i) => ({ time: 1000000 + i, value: v }))
  series.setData(data)
  equityChart.timeScale().fitContent()
}

function loadResult(bt) {
  result.value = bt.results_json
  if (result.value?.equity_curve?.length) {
    setTimeout(() => renderEquityCurve(result.value.equity_curve), 100)
  }
}

const winTrades = computed(() => result.value?.trades?.filter(t => t.pnl > 0) || [])
const lossTrades = computed(() => result.value?.trades?.filter(t => t.pnl <= 0) || [])
</script>

<template>
  <div class="p-4 max-w-6xl mx-auto">
    <h1 class="text-2xl font-bold" style="color: var(--text)">Backtest</h1>
    <p class="mt-1 text-sm" style="color: var(--muted)">Replay historical data and test trading strategies with real engine signals.</p>

    <!-- Config panel -->
    <div class="mt-6 rounded-xl p-5" style="background: var(--card); border: 1px solid var(--border)">
      <div class="grid gap-4 md:grid-cols-6">
        <div>
          <label class="block text-[10px] mb-1 uppercase tracking-wider" style="color: var(--dim)">Symbol</label>
          <select v-model="selectedSymbol" class="w-full rounded-md px-3 py-2 text-sm"
            style="background: var(--surface); border: 1px solid var(--border); color: var(--text)">
            <option v-for="s in symbols" :key="s.id" :value="s.id">{{ s.ticker }}</option>
          </select>
        </div>
        <div>
          <label class="block text-[10px] mb-1 uppercase tracking-wider" style="color: var(--dim)">Timeframe</label>
          <select v-model="selectedTf" class="w-full rounded-md px-3 py-2 text-sm"
            style="background: var(--surface); border: 1px solid var(--border); color: var(--text)">
            <option v-for="tf in timeframes" :key="tf" :value="tf">{{ tf }}</option>
          </select>
        </div>
        <div>
          <label class="block text-[10px] mb-1 uppercase tracking-wider" style="color: var(--dim)">From</label>
          <input v-model="fromDate" type="date" class="w-full rounded-md px-3 py-2 text-sm"
            style="background: var(--surface); border: 1px solid var(--border); color: var(--text)" />
        </div>
        <div>
          <label class="block text-[10px] mb-1 uppercase tracking-wider" style="color: var(--dim)">To</label>
          <input v-model="toDate" type="date" class="w-full rounded-md px-3 py-2 text-sm"
            style="background: var(--surface); border: 1px solid var(--border); color: var(--text)" />
        </div>
        <div>
          <label class="block text-[10px] mb-1 uppercase tracking-wider" style="color: var(--dim)">Mode</label>
          <select v-model="mode" class="w-full rounded-md px-3 py-2 text-sm"
            style="background: var(--surface); border: 1px solid var(--border); color: var(--text)">
            <option v-for="m in modes" :key="m.id" :value="m.id">{{ m.label }}</option>
          </select>
        </div>
        <div class="flex items-end">
          <button @click="runBacktest" :disabled="running"
            class="w-full rounded-md px-4 py-2 text-sm font-bold"
            style="background: var(--accent); color: #fff">
            {{ running ? 'Running...' : 'Run Backtest' }}
          </button>
        </div>
      </div>

      <!-- Advanced config -->
      <div class="mt-4 grid gap-4 md:grid-cols-3">
        <div>
          <label class="block text-[10px] mb-1 uppercase tracking-wider" style="color: var(--dim)">Risk per trade (%)</label>
          <input v-model.number="config.risk_pct" type="number" step="0.5" min="0.1" max="10"
            class="w-full rounded-md px-3 py-2 text-sm"
            style="background: var(--surface); border: 1px solid var(--border); color: var(--text)" />
        </div>
        <div>
          <label class="block text-[10px] mb-1 uppercase tracking-wider" style="color: var(--dim)">Max positions</label>
          <input v-model.number="config.max_positions" type="number" min="1" max="10"
            class="w-full rounded-md px-3 py-2 text-sm"
            style="background: var(--surface); border: 1px solid var(--border); color: var(--text)" />
        </div>
        <div>
          <label class="block text-[10px] mb-1 uppercase tracking-wider" style="color: var(--dim)">Initial capital ($)</label>
          <input v-model.number="config.initial_capital" type="number" min="100"
            class="w-full rounded-md px-3 py-2 text-sm"
            style="background: var(--surface); border: 1px solid var(--border); color: var(--text)" />
        </div>
      </div>
    </div>

    <!-- Results -->
    <div v-if="result" class="mt-6 space-y-4">
      <!-- Metrics grid -->
      <div class="grid gap-3 md:grid-cols-4 lg:grid-cols-8">
        <div v-for="m in [
          { label: 'Net P&L', value: `$${result.net_pnl}`, color: result.net_pnl >= 0 ? 'var(--bull)' : 'var(--bear)' },
          { label: 'Return', value: `${result.return_pct}%`, color: result.return_pct >= 0 ? 'var(--bull)' : 'var(--bear)' },
          { label: 'Win Rate', value: `${result.win_rate}%`, color: result.win_rate >= 50 ? 'var(--bull)' : 'var(--bear)' },
          { label: 'Profit Factor', value: result.profit_factor, color: result.profit_factor >= 1 ? 'var(--bull)' : 'var(--bear)' },
          { label: 'Sharpe Ratio', value: result.sharpe_ratio, color: result.sharpe_ratio >= 1 ? 'var(--bull)' : 'var(--ob)' },
          { label: 'Max Drawdown', value: `${result.max_drawdown}%`, color: 'var(--bear)' },
          { label: 'Avg RRR', value: result.avg_rrr, color: 'var(--text)' },
          { label: 'Total Trades', value: result.total_trades, color: 'var(--text)' },
        ]" :key="m.label" class="rounded-lg p-3 text-center"
          style="background: var(--card); border: 1px solid var(--border)">
          <div class="text-lg font-bold" style="font-family: var(--mono)" :style="{ color: m.color }">{{ m.value }}</div>
          <div class="text-[10px] mt-1" style="color: var(--dim)">{{ m.label }}</div>
        </div>
      </div>

      <!-- Equity curve -->
      <div class="rounded-xl overflow-hidden" style="background: var(--card); border: 1px solid var(--border)">
        <div class="px-4 py-3 text-xs font-semibold uppercase tracking-wider" style="color: var(--dim); border-bottom: 1px solid var(--border)">
          Equity Curve
        </div>
        <div ref="equityContainer" style="height: 200px"></div>
      </div>

      <!-- Trade distribution by engine -->
      <div v-if="result.by_engine && Object.keys(result.by_engine).length" class="rounded-xl p-5"
        style="background: var(--card); border: 1px solid var(--border)">
        <div class="text-xs font-semibold uppercase tracking-wider mb-3" style="color: var(--dim)">Trade Distribution by Engine</div>
        <div class="grid gap-3 md:grid-cols-4">
          <div v-for="(stats, eng) in result.by_engine" :key="eng"
            class="rounded-lg p-3" style="background: var(--surface); border: 1px solid var(--border)">
            <div class="text-xs font-semibold mb-1" style="color: var(--text)">{{ eng.replace('_', ' ') }}</div>
            <div class="flex justify-between text-[10px]" style="font-family: var(--mono)">
              <span style="color: var(--muted)">{{ stats.count }} trades</span>
              <span :style="{ color: stats.pnl >= 0 ? 'var(--bull)' : 'var(--bear)' }">${{ stats.pnl.toFixed(2) }}</span>
            </div>
            <div class="text-[10px] mt-1" style="color: var(--dim); font-family: var(--mono)">
              Win: {{ stats.count > 0 ? Math.round(stats.wins / stats.count * 100) : 0 }}%
            </div>
          </div>
        </div>
      </div>

      <!-- Trade log -->
      <div class="rounded-xl overflow-hidden" style="border: 1px solid var(--border)">
        <div class="px-4 py-3 text-xs font-semibold uppercase tracking-wider"
          style="color: var(--dim); background: var(--card); border-bottom: 1px solid var(--border)">
          Trade Log ({{ result.trades?.length || 0 }})
        </div>
        <div style="max-height: 300px; overflow-y: auto">
          <table class="w-full text-xs">
            <thead style="background: var(--surface)">
              <tr>
                <th class="px-3 py-2 text-left font-medium" style="color: var(--dim)">#</th>
                <th class="px-3 py-2 text-left font-medium" style="color: var(--dim)">Dir</th>
                <th class="px-3 py-2 text-left font-medium" style="color: var(--dim)">Engine</th>
                <th class="px-3 py-2 text-right font-medium" style="color: var(--dim)">Entry</th>
                <th class="px-3 py-2 text-right font-medium" style="color: var(--dim)">Exit</th>
                <th class="px-3 py-2 text-right font-medium" style="color: var(--dim)">P&L</th>
                <th class="px-3 py-2 text-left font-medium" style="color: var(--dim)">Reason</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(t, i) in (result.trades || []).slice(0, 100)" :key="i"
                style="border-bottom: 1px solid rgba(22,32,64,0.3)">
                <td class="px-3 py-2" style="color: var(--dim)">{{ i + 1 }}</td>
                <td class="px-3 py-2">
                  <span class="rounded px-1.5 py-0.5 text-[10px] font-bold"
                    :style="t.direction === 'buy' ? 'background: rgba(0,220,130,0.15); color: var(--bull)' : 'background: rgba(255,59,92,0.15); color: var(--bear)'">
                    {{ t.direction?.toUpperCase() }}
                  </span>
                </td>
                <td class="px-3 py-2" style="color: var(--muted)">{{ t.engine?.replace('_', ' ') }}</td>
                <td class="px-3 py-2 text-right" style="font-family: var(--mono); color: var(--text)">{{ parseFloat(t.entry).toFixed(2) }}</td>
                <td class="px-3 py-2 text-right" style="font-family: var(--mono); color: var(--text)">{{ parseFloat(t.exit).toFixed(2) }}</td>
                <td class="px-3 py-2 text-right font-bold" style="font-family: var(--mono)"
                  :style="{ color: t.pnl >= 0 ? 'var(--bull)' : 'var(--bear)' }">
                  {{ t.pnl >= 0 ? '+' : '' }}${{ t.pnl.toFixed(2) }}
                </td>
                <td class="px-3 py-2 text-[10px]" style="color: var(--dim)">{{ t.reason?.replace('_', ' ') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Empty state -->
    <div v-else-if="!running" class="mt-8 rounded-xl p-12 text-center"
      style="border: 1px dashed var(--border)">
      <p class="text-lg" style="color: var(--dim)">Configure and run a backtest</p>
      <p class="mt-1 text-sm" style="color: var(--border-hi)">Select symbol, timeframe, date range, and mode to begin.</p>
    </div>

    <!-- Running indicator -->
    <div v-if="running" class="mt-8 text-center py-12">
      <div class="text-lg font-bold" style="color: var(--accent)">Running backtest...</div>
      <p class="mt-2 text-sm" style="color: var(--muted)">Processing bar-by-bar with all engines. This may take a moment.</p>
    </div>
  </div>
</template>
