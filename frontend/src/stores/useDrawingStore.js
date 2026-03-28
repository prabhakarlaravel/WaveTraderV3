import { ref, computed } from 'vue'
import { defineStore } from 'pinia'

const STORAGE_KEY = 'wt3_drawings'

export const useDrawingStore = defineStore('drawings', () => {
  const drawings = ref([])
  const activeSymbolId = ref(null)
  const activeTimeframe = ref(null)

  const currentDrawings = computed(() =>
    drawings.value.filter(d =>
      d.symbolId === activeSymbolId.value &&
      d.timeframe === activeTimeframe.value &&
      d.visible
    )
  )

  function addDrawing(drawing) {
    drawings.value.push(drawing)
    persist()
  }

  function removeDrawing(id) {
    drawings.value = drawings.value.filter(d => d.id !== id)
    persist()
  }

  function updateDrawing(id, updates) {
    const idx = drawings.value.findIndex(d => d.id === id)
    if (idx >= 0) {
      drawings.value[idx] = { ...drawings.value[idx], ...updates }
      persist()
    }
  }

  function clearAll() {
    drawings.value = drawings.value.filter(d =>
      d.symbolId !== activeSymbolId.value || d.timeframe !== activeTimeframe.value
    )
    persist()
  }

  function setContext(symbolId, timeframe) {
    activeSymbolId.value = symbolId
    activeTimeframe.value = timeframe
  }

  function persist() {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(drawings.value))
    } catch (e) { /* quota exceeded — silently ignore */ }
  }

  function load() {
    try {
      const raw = localStorage.getItem(STORAGE_KEY)
      if (raw) drawings.value = JSON.parse(raw)
    } catch (e) { drawings.value = [] }
  }

  // Auto-load on creation
  load()

  return {
    drawings, currentDrawings,
    activeSymbolId, activeTimeframe,
    addDrawing, removeDrawing, updateDrawing, clearAll,
    setContext, load, persist,
  }
})
