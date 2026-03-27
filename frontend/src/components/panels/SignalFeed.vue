<script setup>
import { computed } from 'vue'

const props = defineProps({
  signals: { type: Array, default: () => [] },
})

const signals = computed(() => {
  return [...props.signals]
    .sort((a, b) => new Date(b.candle_timestamp) - new Date(a.candle_timestamp))
    .slice(0, 50)
})

function formatTime(ts) {
  if (!ts) return '--'
  const d = new Date(ts)
  return d.toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
}

function formatPrice(p) {
  if (!p) return '--'
  return parseFloat(p).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
</script>

<template>
  <div class="flex h-full flex-col overflow-hidden">
    <div class="flex items-center gap-3 border-b border-gray-800 bg-gray-900/70 px-4 py-2">
      <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500">Signal Feed</h3>
      <span class="ml-auto rounded-full bg-gray-800 px-2 py-0.5 text-xs text-gray-500">
        {{ signals.length }}
      </span>
    </div>

    <div class="flex-1 overflow-y-auto">
      <div v-if="!signals.length" class="p-4 text-center text-sm text-gray-600">
        No signals yet. Run engines to generate signals.
      </div>

      <div
        v-for="(s, i) in signals"
        :key="i"
        class="flex items-center gap-3 border-b border-gray-800/50 px-4 py-2 hover:bg-gray-800/30"
      >
        <!-- Direction badge -->
        <span
          :class="[
            'w-10 rounded px-1.5 py-0.5 text-center text-[10px] font-bold uppercase',
            s.direction === 'buy'
              ? 'bg-green-900/40 text-green-400'
              : s.direction === 'sell'
                ? 'bg-red-900/40 text-red-400'
                : 'bg-gray-800 text-gray-500',
          ]"
        >
          {{ s.direction }}
        </span>

        <!-- Engine + price -->
        <div class="min-w-0 flex-1">
          <div class="text-xs text-gray-300">{{ s.engine?.replace('_', ' ') }}</div>
          <div class="font-mono text-[11px] text-gray-500">{{ formatPrice(s.entry) }}</div>
        </div>

        <!-- Confluence score -->
        <span
          v-if="s.confluence_score"
          class="rounded-full bg-blue-900/30 px-2 py-0.5 text-[10px] text-blue-400"
        >
          {{ s.confluence_score }}
        </span>

        <!-- Time -->
        <span class="text-[10px] text-gray-600 whitespace-nowrap">{{ formatTime(s.candle_timestamp) }}</span>
      </div>
    </div>
  </div>
</template>
