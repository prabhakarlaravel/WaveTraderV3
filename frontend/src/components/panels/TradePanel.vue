<script setup>
import { ref, computed } from 'vue'
import { useTradeStore } from '../../stores/useTradeStore'
import { useChartStore } from '../../stores/useChartStore'

const tradeStore = useTradeStore()
const chartStore = useChartStore()

const direction = ref('long')
const quantity = ref(1)
const slInput = ref('')
const tpInput = ref('')
const notesInput = ref('')
const submitting = ref(false)
const autoTrading = ref(false)
const autoResult = ref(null)

const currentPrice = computed(() => {
  const c = chartStore.candles
  if (!c.length) return 0
  return parseFloat(c[c.length - 1].close)
})

async function submitTrade() {
  if (!chartStore.activeSymbolId || !currentPrice.value) return
  submitting.value = true
  try {
    await tradeStore.openTrade({
      symbol_id: chartStore.activeSymbolId,
      type: direction.value,
      entry_price: currentPrice.value,
      quantity: quantity.value,
      sl: slInput.value ? parseFloat(slInput.value) : null,
      tp: tpInput.value ? parseFloat(tpInput.value) : null,
      notes: notesInput.value || null,
    })
    slInput.value = ''
    tpInput.value = ''
  } finally {
    submitting.value = false
  }
}

async function runAuto() {
  if (!chartStore.activeSymbolId) return
  autoTrading.value = true
  autoResult.value = null
  try {
    autoResult.value = await tradeStore.runAutoTrade(
      chartStore.activeSymbolId,
      chartStore.activeTimeframe,
      60
    )
  } finally {
    autoTrading.value = false
  }
}

async function closeTrade(trade) {
  await tradeStore.closeTrade(trade.id, currentPrice.value)
}

function formatPrice(p) {
  return p ? parseFloat(p).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '--'
}

function tradePnl(trade) {
  const entry = parseFloat(trade.entry_price)
  const current = currentPrice.value
  const mult = trade.type === 'long' ? 1 : -1
  return ((current - entry) * mult * parseFloat(trade.quantity)).toFixed(2)
}
</script>

<template>
  <div class="flex flex-col h-full overflow-hidden">
    <!-- Header -->
    <div class="px-4 py-2" style="border-bottom: 1px solid var(--border); background: var(--card)">
      <div class="text-xs font-semibold uppercase tracking-wider" style="color: var(--dim)">Paper Trade</div>
    </div>

    <!-- Order form -->
    <div class="px-4 py-3 space-y-3" style="border-bottom: 1px solid var(--border)">
      <!-- Direction toggle -->
      <div class="flex gap-1 rounded-md p-0.5" style="background: var(--surface); border: 1px solid var(--border)">
        <button @click="direction = 'long'"
          class="flex-1 rounded px-3 py-1.5 text-xs font-bold transition"
          :style="direction === 'long' ? 'background: rgba(0,220,130,0.15); color: var(--bull); border: 1px solid rgba(0,220,130,0.3)' : 'color: var(--dim); border: 1px solid transparent'">
          LONG
        </button>
        <button @click="direction = 'short'"
          class="flex-1 rounded px-3 py-1.5 text-xs font-bold transition"
          :style="direction === 'short' ? 'background: rgba(255,59,92,0.15); color: var(--bear); border: 1px solid rgba(255,59,92,0.3)' : 'color: var(--dim); border: 1px solid transparent'">
          SHORT
        </button>
      </div>

      <!-- Entry price -->
      <div>
        <div class="text-[10px] mb-1 uppercase tracking-wider" style="color: var(--dim)">Entry (Market)</div>
        <div class="rounded-md px-3 py-2 text-sm font-bold" style="background: var(--surface); border: 1px solid var(--border); font-family: var(--mono)"
          :style="{ color: direction === 'long' ? 'var(--bull)' : 'var(--bear)' }">
          {{ formatPrice(currentPrice) }}
        </div>
      </div>

      <!-- Quantity -->
      <div>
        <div class="text-[10px] mb-1 uppercase tracking-wider" style="color: var(--dim)">Quantity</div>
        <input v-model.number="quantity" type="number" min="0.001" step="0.1"
          class="w-full rounded-md px-3 py-2 text-sm"
          style="background: var(--surface); border: 1px solid var(--border); color: var(--text); font-family: var(--mono)" />
      </div>

      <!-- SL / TP row -->
      <div class="grid grid-cols-2 gap-2">
        <div>
          <div class="text-[10px] mb-1 uppercase tracking-wider" style="color: var(--bear)">Stop Loss</div>
          <input v-model="slInput" type="number" step="any" placeholder="Optional"
            class="w-full rounded-md px-3 py-2 text-sm"
            style="background: var(--surface); border: 1px solid var(--border); color: var(--text); font-family: var(--mono)" />
        </div>
        <div>
          <div class="text-[10px] mb-1 uppercase tracking-wider" style="color: var(--bull)">Take Profit</div>
          <input v-model="tpInput" type="number" step="any" placeholder="Optional"
            class="w-full rounded-md px-3 py-2 text-sm"
            style="background: var(--surface); border: 1px solid var(--border); color: var(--text); font-family: var(--mono)" />
        </div>
      </div>

      <!-- Notes -->
      <div>
        <div class="text-[10px] mb-1 uppercase tracking-wider" style="color: var(--dim)">Notes (journal)</div>
        <input v-model="notesInput" type="text" placeholder="Trade reason..."
          class="w-full rounded-md px-3 py-2 text-xs"
          style="background: var(--surface); border: 1px solid var(--border); color: var(--text)" />
      </div>

      <!-- Submit -->
      <button @click="submitTrade" :disabled="submitting || !currentPrice"
        class="w-full rounded-md py-2.5 text-sm font-bold transition"
        :style="direction === 'long'
          ? 'background: var(--bull); color: #000'
          : 'background: var(--bear); color: #fff'">
        {{ submitting ? 'Placing...' : `${direction === 'long' ? 'BUY' : 'SELL'} @ ${formatPrice(currentPrice)}` }}
      </button>

      <!-- Auto trade -->
      <button @click="runAuto" :disabled="autoTrading"
        class="w-full rounded-md py-2 text-[10px] font-semibold uppercase tracking-wider"
        style="background: var(--accent-bg); border: 1px solid rgba(59,130,246,0.3); color: var(--accent)">
        {{ autoTrading ? 'Analyzing...' : 'Auto Trade (Confluence)' }}
      </button>
      <div v-if="autoResult" class="text-[10px] text-center" :style="{ color: autoResult.action === 'trade_opened' ? 'var(--bull)' : 'var(--dim)' }">
        {{ autoResult.action === 'trade_opened' ? `Opened ${autoResult.trade?.type} @ ${formatPrice(autoResult.trade?.entry_price)}` : autoResult.reason }}
      </div>
    </div>

    <!-- Open Positions -->
    <div class="flex-1 overflow-y-auto">
      <div class="px-4 py-2 flex items-center justify-between" style="border-bottom: 1px solid var(--border)">
        <span class="text-[10px] font-semibold uppercase tracking-wider" style="color: var(--dim)">Open Positions</span>
        <span class="rounded-full px-2 py-0.5 text-[10px] font-bold"
          style="background: var(--surface); color: var(--muted)">{{ tradeStore.openTrades.length }}</span>
      </div>

      <div v-if="!tradeStore.openTrades.length" class="px-4 py-6 text-center text-xs" style="color: var(--dim)">
        No open positions
      </div>

      <div v-for="trade in tradeStore.openTrades" :key="trade.id"
        class="px-4 py-3" style="border-bottom: 1px solid rgba(22,32,64,0.3)">
        <div class="flex items-center justify-between mb-1">
          <span class="rounded px-1.5 py-0.5 text-[10px] font-bold"
            :style="trade.type === 'long' ? 'background: rgba(0,220,130,0.15); color: var(--bull)' : 'background: rgba(255,59,92,0.15); color: var(--bear)'">
            {{ trade.type.toUpperCase() }}
          </span>
          <span class="text-xs font-bold" style="font-family: var(--mono)"
            :style="{ color: parseFloat(tradePnl(trade)) >= 0 ? 'var(--bull)' : 'var(--bear)' }">
            {{ parseFloat(tradePnl(trade)) >= 0 ? '+' : '' }}${{ tradePnl(trade) }}
          </span>
        </div>
        <div class="flex items-center justify-between text-[10px]" style="font-family: var(--mono); color: var(--muted)">
          <span>Entry: {{ formatPrice(trade.entry_price) }}</span>
          <span>Qty: {{ trade.quantity }}</span>
        </div>
        <div v-if="trade.sl || trade.tp" class="flex gap-3 mt-1 text-[10px]" style="font-family: var(--mono)">
          <span v-if="trade.sl" style="color: var(--bear)">SL: {{ formatPrice(trade.sl) }}</span>
          <span v-if="trade.tp" style="color: var(--bull)">TP: {{ formatPrice(trade.tp) }}</span>
        </div>
        <div v-if="trade.auto_trade || trade.engine" class="flex gap-2 mt-1 text-[10px]">
          <span v-if="trade.auto_trade" class="rounded px-1.5 py-0.5" style="background: var(--accent-bg); color: var(--accent)">AUTO</span>
          <span v-if="trade.engine" style="color: var(--dim)">{{ trade.engine }}</span>
          <span v-if="trade.confluence_score" style="color: var(--wave)">{{ trade.confluence_score }}%</span>
        </div>
        <div v-if="trade.notes" class="mt-1 text-[10px] truncate" style="color: var(--dim)">{{ trade.notes }}</div>
        <button @click="closeTrade(trade)"
          class="mt-2 w-full rounded px-2 py-1 text-[10px] font-semibold"
          style="background: rgba(255,59,92,0.1); border: 1px solid rgba(255,59,92,0.25); color: var(--bear)">
          Close @ {{ formatPrice(currentPrice) }}
        </button>
      </div>
    </div>

    <!-- P&L Summary footer -->
    <div class="px-4 py-3" style="border-top: 1px solid var(--border); background: var(--card)">
      <div class="grid grid-cols-2 gap-2 text-center">
        <div>
          <div class="text-[10px]" style="color: var(--dim)">Total P&L</div>
          <div class="text-sm font-bold" style="font-family: var(--mono)"
            :style="{ color: tradeStore.totalPnl >= 0 ? 'var(--bull)' : 'var(--bear)' }">
            {{ tradeStore.totalPnl >= 0 ? '+' : '' }}${{ tradeStore.totalPnl.toFixed(2) }}
          </div>
        </div>
        <div>
          <div class="text-[10px]" style="color: var(--dim)">Win Rate</div>
          <div class="text-sm font-bold" style="font-family: var(--mono)"
            :style="{ color: tradeStore.winRate >= 50 ? 'var(--bull)' : tradeStore.winRate > 0 ? 'var(--bear)' : 'var(--dim)' }">
            {{ tradeStore.winRate }}%
          </div>
        </div>
      </div>
      <div class="text-[10px] text-center mt-1" style="color: var(--dim)">
        {{ tradeStore.closedTrades.length }} closed · {{ tradeStore.openTrades.length }} open
      </div>
    </div>
  </div>
</template>
