<script setup>
import { ref, computed, watch } from 'vue'
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

// Unrealized P&L for each open trade (reactive to price changes)
const unrealizedPnls = computed(() => {
  const price = currentPrice.value
  const result = {}
  for (const trade of tradeStore.openTrades) {
    result[trade.id] = tradeStore.calcUnrealizedPnl(trade, price)
  }
  return result
})

// Total unrealized P&L across all open positions
const totalUnrealized = computed(() => tradeStore.totalUnrealizedPnl(currentPrice.value))

// Combined P&L: realized + unrealized
const combinedPnl = computed(() => tradeStore.totalPnl + totalUnrealized.value)

// Auto-check SL/TP whenever price changes
watch(currentPrice, (price) => {
  if (!price || !tradeStore.openTrades.length) return
  const autoClosed = tradeStore.checkStops(price)
  if (autoClosed.length) {
    console.log(`[Trade] Auto-closed ${autoClosed.length} positions at SL/TP`)
  }
})

function submitTrade() {
  if (!chartStore.activeSymbolId || !currentPrice.value) return
  submitting.value = true
  try {
    tradeStore.openTrade({
      symbol_id: chartStore.activeSymbolId,
      symbol_ticker: chartStore.activeSymbol?.ticker || '',
      type: direction.value,
      entry_price: currentPrice.value,
      quantity: quantity.value,
      sl: slInput.value ? parseFloat(slInput.value) : null,
      tp: tpInput.value ? parseFloat(tpInput.value) : null,
      notes: notesInput.value || null,
      timeframe: chartStore.activeTimeframe,
    })
    slInput.value = ''
    tpInput.value = ''
    notesInput.value = ''
  } finally {
    submitting.value = false
  }
}

function closeTrade(trade) {
  tradeStore.closeTrade(trade.id, currentPrice.value)
}

function formatPrice(p) {
  return p ? parseFloat(p).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '--'
}

function formatPnl(val) {
  const n = typeof val === 'number' ? val : parseFloat(val || 0)
  const sign = n >= 0 ? '+' : ''
  return `${sign}$${n.toFixed(2)}`
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
          <!-- Live unrealized P&L -->
          <span class="text-xs font-bold" style="font-family: var(--mono)"
            :style="{ color: (unrealizedPnls[trade.id] || 0) >= 0 ? 'var(--bull)' : 'var(--bear)' }">
            {{ formatPnl(unrealizedPnls[trade.id] || 0) }}
          </span>
        </div>
        <div class="flex items-center justify-between text-[10px]" style="font-family: var(--mono); color: var(--muted)">
          <span>Entry: {{ formatPrice(trade.entry_price) }}</span>
          <span>Now: {{ formatPrice(currentPrice) }}</span>
          <span>Qty: {{ trade.quantity }}</span>
        </div>
        <div v-if="trade.sl || trade.tp" class="flex gap-3 mt-1 text-[10px]" style="font-family: var(--mono)">
          <span v-if="trade.sl" style="color: var(--bear)">SL: {{ formatPrice(trade.sl) }}</span>
          <span v-if="trade.tp" style="color: var(--bull)">TP: {{ formatPrice(trade.tp) }}</span>
        </div>
        <div v-if="trade.notes" class="mt-1 text-[10px] truncate" style="color: var(--dim)">📝 {{ trade.notes }}</div>
        <button @click="closeTrade(trade)"
          class="mt-2 w-full rounded px-2 py-1 text-[10px] font-semibold"
          style="background: rgba(255,59,92,0.1); border: 1px solid rgba(255,59,92,0.25); color: var(--bear)">
          Close @ {{ formatPrice(currentPrice) }}
        </button>
      </div>
    </div>

    <!-- P&L Summary footer -->
    <div class="px-4 py-3" style="border-top: 1px solid var(--border); background: var(--card)">
      <div class="grid grid-cols-3 gap-2 text-center">
        <div>
          <div class="text-[10px]" style="color: var(--dim)">Realized</div>
          <div class="text-xs font-bold" style="font-family: var(--mono)"
            :style="{ color: tradeStore.totalPnl >= 0 ? 'var(--bull)' : 'var(--bear)' }">
            {{ formatPnl(tradeStore.totalPnl) }}
          </div>
        </div>
        <div>
          <div class="text-[10px]" style="color: var(--dim)">Unrealized</div>
          <div class="text-xs font-bold" style="font-family: var(--mono)"
            :style="{ color: totalUnrealized >= 0 ? 'var(--bull)' : 'var(--bear)' }">
            {{ formatPnl(totalUnrealized) }}
          </div>
        </div>
        <div>
          <div class="text-[10px]" style="color: var(--dim)">Win Rate</div>
          <div class="text-xs font-bold" style="font-family: var(--mono)"
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
