<script setup>
import { ref, onMounted } from 'vue'
import { useSettingsStore } from '../../stores/useSettingsStore'

const store = useSettingsStore()
const showAdd = ref(false)
const newSymbol = ref({ exchange: 'binance', ticker: '', name: '', type: 'crypto' })

onMounted(() => store.fetchSymbols())

async function addSymbol() {
  if (!newSymbol.value.ticker) return
  await store.addSymbol({ ...newSymbol.value })
  newSymbol.value = { exchange: 'binance', ticker: '', name: '', type: 'crypto' }
  showAdd.value = false
}

async function toggleActive(sym) {
  await store.updateSymbol(sym.id, { active: !sym.active })
}

async function remove(sym) {
  if (confirm(`Remove ${sym.ticker}? This will delete all its candle data.`)) {
    await store.deleteSymbol(sym.id)
  }
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-sm font-semibold text-[var(--text)]">Active Symbols</h3>
      <button @click="showAdd = !showAdd" class="rounded-md px-3 py-1.5 text-xs font-semibold"
        :style="{ background: 'var(--accent-bg)', border: '1px solid rgba(59,130,246,0.4)', color: 'var(--accent)' }">
        {{ showAdd ? 'Cancel' : '+ Add Symbol' }}
      </button>
    </div>

    <!-- Add form -->
    <div v-if="showAdd" class="mb-4 rounded-lg p-4" style="background: var(--card); border: 1px solid var(--border)">
      <div class="grid grid-cols-2 gap-3 mb-3">
        <div>
          <label class="block text-[10px] mb-1" style="color: var(--dim)">Exchange</label>
          <select v-model="newSymbol.exchange" class="w-full rounded-md px-3 py-2 text-sm"
            style="background: var(--surface); border: 1px solid var(--border); color: var(--text)">
            <option value="binance">Binance</option>
            <option value="zerodha">Zerodha</option>
            <option value="oanda">OANDA</option>
          </select>
        </div>
        <div>
          <label class="block text-[10px] mb-1" style="color: var(--dim)">Type</label>
          <select v-model="newSymbol.type" class="w-full rounded-md px-3 py-2 text-sm"
            style="background: var(--surface); border: 1px solid var(--border); color: var(--text)">
            <option value="crypto">Crypto</option>
            <option value="equity">Equity</option>
            <option value="forex">Forex</option>
            <option value="commodity">Commodity</option>
          </select>
        </div>
        <div>
          <label class="block text-[10px] mb-1" style="color: var(--dim)">Ticker</label>
          <input v-model="newSymbol.ticker" placeholder="ETHUSDT" class="w-full rounded-md px-3 py-2 text-sm"
            style="background: var(--surface); border: 1px solid var(--border); color: var(--text)" />
        </div>
        <div>
          <label class="block text-[10px] mb-1" style="color: var(--dim)">Name</label>
          <input v-model="newSymbol.name" placeholder="Ethereum/USDT" class="w-full rounded-md px-3 py-2 text-sm"
            style="background: var(--surface); border: 1px solid var(--border); color: var(--text)" />
        </div>
      </div>
      <button @click="addSymbol" class="rounded-md px-4 py-2 text-xs font-semibold"
        style="background: var(--bull); color: #000">Add Symbol</button>
    </div>

    <!-- Symbol list -->
    <div class="space-y-2">
      <div v-for="sym in store.symbols" :key="sym.id"
        class="flex items-center gap-3 rounded-lg px-4 py-3"
        style="background: var(--card); border: 1px solid var(--border)">
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2">
            <span class="text-sm font-semibold" style="font-family: var(--mono); color: var(--text)">{{ sym.ticker }}</span>
            <span class="text-[10px] px-2 py-0.5 rounded" style="background: var(--surface); color: var(--dim)">{{ sym.exchange.toUpperCase() }}</span>
            <span class="text-[10px] px-2 py-0.5 rounded" style="background: var(--surface); color: var(--dim)">{{ sym.type }}</span>
          </div>
          <div class="text-xs mt-0.5" style="color: var(--muted)">{{ sym.name }}</div>
        </div>
        <button @click="toggleActive(sym)" class="rounded-md px-3 py-1 text-[10px] font-semibold"
          :style="sym.active
            ? 'background: rgba(0,220,130,0.1); border: 1px solid rgba(0,220,130,0.3); color: var(--bull)'
            : 'background: var(--surface); border: 1px solid var(--border); color: var(--dim)'">
          {{ sym.active ? 'Active' : 'Inactive' }}
        </button>
        <button @click="remove(sym)" class="rounded-md px-2 py-1 text-[10px]"
          style="color: var(--bear); background: rgba(255,59,92,0.06); border: 1px solid rgba(255,59,92,0.2)">
          Remove
        </button>
      </div>
      <div v-if="!store.symbols.length" class="text-center py-6 text-sm" style="color: var(--dim)">
        No symbols added yet
      </div>
    </div>
  </div>
</template>
