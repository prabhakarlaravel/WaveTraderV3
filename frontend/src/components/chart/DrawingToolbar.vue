<script setup>
defineProps({
  activeTool: { type: String, default: null },
  drawingCount: { type: Number, default: 0 },
})

const emit = defineEmits(['select', 'clear-all'])

const tools = [
  { key: 'trendline', label: 'Trend', icon: '╱', color: '#3b82f6' },
  { key: 'hline', label: 'H-Line', icon: '─', color: '#f59e0b' },
  { key: 'fib', label: 'Fib', icon: '⊿', color: '#8b5cf6' },
  { key: 'rect', label: 'Rect', icon: '▭', color: '#06b6d4' },
]
</script>

<template>
  <div class="drawing-toolbar">
    <button
      v-for="t in tools"
      :key="t.key"
      @click="emit('select', t.key)"
      :class="['draw-btn', { active: activeTool === t.key }]"
      :style="activeTool === t.key ? `--dc: ${t.color}; background: ${t.color}18; border-color: ${t.color}50; color: ${t.color}` : ''"
      :title="`Draw ${t.label}`"
    >
      <span class="draw-icon">{{ t.icon }}</span>
      <span class="draw-label">{{ t.label }}</span>
    </button>

    <button
      v-if="drawingCount > 0"
      class="draw-btn clear-btn"
      @click="emit('clear-all')"
      title="Clear all drawings"
    >
      <span class="draw-icon">✕</span>
      <span class="draw-label">Clear</span>
    </button>
  </div>
</template>

<style scoped>
.drawing-toolbar { display: flex; gap: 2px; align-items: center; }

.draw-btn {
  display: flex; align-items: center; gap: 3px;
  padding: 3px 8px;
  background: transparent;
  border: 1px solid var(--border);
  border-radius: 5px;
  color: var(--muted);
  font-size: 10px;
  font-family: 'DM Sans', sans-serif;
  cursor: pointer;
  transition: all 0.15s;
}

.draw-btn:hover { background: var(--surface); color: var(--text); border-color: var(--border-hi); }

.draw-btn.active { font-weight: 700; }

.draw-icon { font-size: 12px; line-height: 1; }

.draw-label { font-weight: 600; }

.clear-btn { color: var(--bear); }
.clear-btn:hover { background: var(--bear-fade); border-color: var(--bear); color: var(--bear); }
</style>
