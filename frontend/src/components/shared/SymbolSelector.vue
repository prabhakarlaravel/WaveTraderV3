<script setup>
/**
 * SymbolSelector — Shared professional symbol picker for 50+ symbols.
 *
 * Usage:
 *   <SymbolSelector v-model="selectedId" :symbols="allSymbols" />
 *   <SymbolSelector v-model="selectedId" :symbols="allSymbols" compact />
 *
 * Props:
 *   symbols    — Array of { id, ticker, name, exchange, type, active }
 *   modelValue — Currently selected symbol id
 *   compact    — If true, renders as a dropdown button (for toolbars)
 *   multi      — If true, allows multi-select (for gaps/wave-health batch ops)
 *   showStatus — If true, shows a status dot per symbol (pass statusMap prop)
 *   statusMap  — Object { [symbolId]: 'ok' | 'warn' | 'error' | 'idle' }
 */
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue'

const props = defineProps({
  modelValue: { type: [Number, Array], default: null },
  symbols: { type: Array, default: () => [] },
  compact: { type: Boolean, default: false },
  multi: { type: Boolean, default: false },
  showStatus: { type: Boolean, default: false },
  statusMap: { type: Object, default: () => ({}) },
  placeholder: { type: String, default: 'Select symbol…' },
})

const emit = defineEmits(['update:modelValue', 'change'])

// ── State ────────────────────────────────────────────────────────────────────
const open = ref(false)
const search = ref('')
const searchInput = ref(null)
const panelRef = ref(null)
const activeGroup = ref('ALL')

// ── Favorites (persisted in localStorage) ────────────────────────────────────
const FAVS_KEY = 'wt_symbol_favs'
const favorites = ref(JSON.parse(localStorage.getItem(FAVS_KEY) || '[]'))

function toggleFav(id) {
  const idx = favorites.value.indexOf(id)
  if (idx >= 0) favorites.value.splice(idx, 1)
  else favorites.value.push(id)
  localStorage.setItem(FAVS_KEY, JSON.stringify(favorites.value))
}

// ── Groups ───────────────────────────────────────────────────────────────────
const groups = computed(() => {
  const map = {}
  for (const s of props.symbols) {
    const g = s.type === 'INDEX' ? 'INDICES' : s.exchange?.toUpperCase() || 'OTHER'
    if (!map[g]) map[g] = []
    map[g].push(s)
  }
  // Sort groups: INDICES first, then alphabetical
  const sorted = {}
  if (map['INDICES']) sorted['INDICES'] = map['INDICES']
  for (const k of Object.keys(map).sort()) {
    if (k !== 'INDICES') sorted[k] = map[k]
  }
  return sorted
})

const groupTabs = computed(() => {
  const tabs = [{ key: 'ALL', label: 'All', count: props.symbols.length }]
  if (favorites.value.length) {
    tabs.push({ key: 'FAVS', label: '★', count: favorites.value.filter(id => props.symbols.some(s => s.id === id)).length })
  }
  for (const [g, items] of Object.entries(groups.value)) {
    tabs.push({ key: g, label: g === 'INDICES' ? 'Indices' : g, count: items.length })
  }
  return tabs
})

// ── Filtered list ────────────────────────────────────────────────────────────
const filtered = computed(() => {
  let list = props.symbols

  // Group filter
  if (activeGroup.value === 'FAVS') {
    list = list.filter(s => favorites.value.includes(s.id))
  } else if (activeGroup.value === 'INDICES') {
    list = list.filter(s => s.type === 'INDEX')
  } else if (activeGroup.value !== 'ALL') {
    list = list.filter(s => s.exchange?.toUpperCase() === activeGroup.value && s.type !== 'INDEX')
  }

  // Search filter
  if (search.value.trim()) {
    const q = search.value.trim().toLowerCase()
    list = list.filter(s =>
      s.ticker.toLowerCase().includes(q) ||
      (s.name && s.name.toLowerCase().includes(q))
    )
  }

  // Sort: favorites first, then alphabetical by ticker
  return [...list].sort((a, b) => {
    const aFav = favorites.value.includes(a.id) ? 0 : 1
    const bFav = favorites.value.includes(b.id) ? 0 : 1
    if (aFav !== bFav) return aFav - bFav
    return a.ticker.localeCompare(b.ticker)
  })
})

// ── Selection ────────────────────────────────────────────────────────────────
const selected = computed(() => {
  if (props.multi) return Array.isArray(props.modelValue) ? props.modelValue : []
  return props.modelValue
})

const selectedSymbol = computed(() =>
  props.symbols.find(s => s.id === props.modelValue)
)

function select(sym) {
  if (props.multi) {
    const list = [...selected.value]
    const idx = list.indexOf(sym.id)
    if (idx >= 0) list.splice(idx, 1)
    else list.push(sym.id)
    emit('update:modelValue', list)
    emit('change', list)
  } else {
    emit('update:modelValue', sym.id)
    emit('change', sym.id)
    open.value = false
  }
}

function isSelected(id) {
  if (props.multi) return selected.value.includes(id)
  return props.modelValue === id
}

// ── Open/close ───────────────────────────────────────────────────────────────
function toggle() {
  open.value = !open.value
  if (open.value) {
    search.value = ''
    nextTick(() => searchInput.value?.focus())
  }
}

function onClickOutside(e) {
  if (panelRef.value && !panelRef.value.contains(e.target)) {
    open.value = false
  }
}

onMounted(() => document.addEventListener('mousedown', onClickOutside))
onUnmounted(() => document.removeEventListener('mousedown', onClickOutside))

// ── Helpers ──────────────────────────────────────────────────────────────────
const exchangeColors = {
  NSE: '#6366f1', BSE: '#f59e0b', BINANCE: '#F0B90B', OANDA: '#2196F3',
  NFO: '#8b5cf6', MCX: '#ef4444',
}

function statusColor(id) {
  const st = props.statusMap[id]
  if (st === 'ok') return '#10b981'
  if (st === 'warn') return '#f59e0b'
  if (st === 'error') return '#ef4444'
  return '#334155'
}
</script>

<template>
  <div ref="panelRef" class="sym-selector" :class="{ compact }">

    <!-- ── Trigger button ─────────────────────────────────────────── -->
    <button class="sym-trigger" @click="toggle" :class="{ open }">
      <template v-if="selectedSymbol">
        <span class="sym-exchange-dot" :style="{ background: exchangeColors[selectedSymbol.exchange?.toUpperCase()] || '#6366f1' }"></span>
        <span class="sym-ticker">{{ selectedSymbol.ticker }}</span>
        <span v-if="!compact" class="sym-name">{{ selectedSymbol.name }}</span>
      </template>
      <template v-else>
        <span class="sym-placeholder">{{ placeholder }}</span>
      </template>
      <svg class="sym-chevron" :class="{ flipped: open }" width="10" height="6" viewBox="0 0 10 6" fill="none">
        <path d="M1 1l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
    </button>

    <!-- ── Dropdown panel ─────────────────────────────────────────── -->
    <Transition name="sym-dd">
      <div v-if="open" class="sym-panel">

        <!-- Search -->
        <div class="sym-search-row">
          <svg class="sym-search-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>
          </svg>
          <input ref="searchInput" v-model="search" type="text"
            placeholder="Search ticker or name…"
            class="sym-search" @keydown.escape="open = false" />
          <span class="sym-count">{{ filtered.length }}</span>
        </div>

        <!-- Group tabs -->
        <div class="sym-tabs">
          <button v-for="tab in groupTabs" :key="tab.key"
            @click="activeGroup = tab.key"
            :class="['sym-tab', { active: activeGroup === tab.key }]">
            {{ tab.label }}
            <span class="sym-tab-count">{{ tab.count }}</span>
          </button>
        </div>

        <!-- Symbol list -->
        <div class="sym-list">
          <div v-if="!filtered.length" class="sym-empty">
            No symbols match "{{ search }}"
          </div>
          <button v-for="s in filtered" :key="s.id"
            @click="select(s)"
            :class="['sym-item', { selected: isSelected(s.id) }]">

            <!-- Favorite star -->
            <span class="sym-fav" @click.stop="toggleFav(s.id)"
              :class="{ starred: favorites.includes(s.id) }">
              {{ favorites.includes(s.id) ? '★' : '☆' }}
            </span>

            <!-- Status dot (optional) -->
            <span v-if="showStatus" class="sym-status-dot"
              :style="{ background: statusColor(s.id) }"></span>

            <!-- Multi-select checkbox -->
            <span v-if="multi" class="sym-check">
              {{ isSelected(s.id) ? '☑' : '☐' }}
            </span>

            <!-- Ticker + name -->
            <span class="sym-item-ticker">{{ s.ticker }}</span>
            <span class="sym-item-name">{{ s.name }}</span>

            <!-- Badges -->
            <span class="sym-badge" :style="{ background: (exchangeColors[s.exchange?.toUpperCase()] || '#6366f1') + '18', color: exchangeColors[s.exchange?.toUpperCase()] || '#6366f1' }">
              {{ s.exchange }}
            </span>
            <span class="sym-badge type-badge">{{ s.type }}</span>
          </button>
        </div>
      </div>
    </Transition>
  </div>
</template>

<style scoped>
/* ── Selector root ──────────────────────────────────────────── */
.sym-selector { position: relative; display: inline-block; }
.sym-selector.compact { font-size: 12px; }

/* ── Trigger button ─────────────────────────────────────────── */
.sym-trigger {
  display: flex; align-items: center; gap: 6px;
  padding: 6px 12px; border-radius: 8px;
  background: var(--card); border: 1px solid var(--border);
  color: var(--text); cursor: pointer; font-size: 12px;
  transition: all 0.15s;
  min-width: 160px;
}
.compact .sym-trigger { min-width: 120px; padding: 4px 10px; }
.sym-trigger:hover, .sym-trigger.open {
  border-color: #6366f1; box-shadow: 0 0 0 1px rgba(99,102,241,0.2);
}
.sym-exchange-dot {
  width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0;
}
.sym-ticker {
  font-family: 'JetBrains Mono', 'Fira Code', monospace;
  font-weight: 700; font-size: 12px; white-space: nowrap;
}
.sym-name {
  color: var(--dim); font-size: 11px; overflow: hidden;
  text-overflow: ellipsis; white-space: nowrap; flex: 1;
}
.sym-placeholder { color: var(--dim); font-size: 11px; }
.sym-chevron {
  margin-left: auto; color: var(--dim); transition: transform 0.2s;
  flex-shrink: 0;
}
.sym-chevron.flipped { transform: rotate(180deg); }

/* ── Dropdown panel ─────────────────────────────────────────── */
.sym-panel {
  position: absolute; top: calc(100% + 4px); left: 0;
  width: 420px; max-height: 460px;
  background: var(--card); border: 1px solid var(--border);
  border-radius: 12px; box-shadow: 0 20px 50px rgba(0,0,0,0.5);
  z-index: 999; display: flex; flex-direction: column;
  overflow: hidden;
}

/* ── Search ─────────────────────────────────────────────────── */
.sym-search-row {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 12px; border-bottom: 1px solid var(--border);
}
.sym-search-icon { color: var(--dim); flex-shrink: 0; }
.sym-search {
  flex: 1; background: none; border: none; outline: none;
  color: var(--text); font-size: 13px;
  font-family: inherit;
}
.sym-search::placeholder { color: var(--dim); }
.sym-count {
  font-size: 10px; color: var(--dim); background: var(--surface);
  padding: 2px 6px; border-radius: 4px; font-weight: 600;
}

/* ── Group tabs ─────────────────────────────────────────────── */
.sym-tabs {
  display: flex; gap: 2px; padding: 6px 8px;
  border-bottom: 1px solid var(--border);
  overflow-x: auto; flex-shrink: 0;
}
.sym-tabs::-webkit-scrollbar { display: none; }
.sym-tab {
  display: flex; align-items: center; gap: 4px;
  padding: 4px 10px; border-radius: 6px;
  background: none; border: none; cursor: pointer;
  color: var(--dim); font-size: 11px; font-weight: 600;
  white-space: nowrap; transition: all 0.15s;
}
.sym-tab:hover { color: var(--text); background: var(--surface); }
.sym-tab.active { color: #6366f1; background: rgba(99,102,241,0.1); }
.sym-tab-count {
  font-size: 9px; background: var(--surface); padding: 1px 5px;
  border-radius: 3px; color: var(--dim);
}
.sym-tab.active .sym-tab-count { background: rgba(99,102,241,0.15); color: #818cf8; }

/* ── Symbol list ────────────────────────────────────────────── */
.sym-list {
  overflow-y: auto; flex: 1; padding: 4px;
  max-height: 340px;
}
.sym-list::-webkit-scrollbar { width: 4px; }
.sym-list::-webkit-scrollbar-thumb { background: var(--border); border-radius: 2px; }

.sym-empty {
  padding: 24px; text-align: center; color: var(--dim); font-size: 12px;
}

.sym-item {
  display: flex; align-items: center; gap: 8px;
  width: 100%; padding: 7px 10px; border-radius: 8px;
  background: none; border: none; cursor: pointer;
  color: var(--text); font-size: 12px; text-align: left;
  transition: background 0.1s;
}
.sym-item:hover { background: var(--surface); }
.sym-item.selected { background: rgba(99,102,241,0.08); }

/* Favorite star */
.sym-fav {
  font-size: 12px; color: var(--dim); cursor: pointer;
  transition: color 0.15s; flex-shrink: 0; width: 16px; text-align: center;
}
.sym-fav:hover { color: #f59e0b; }
.sym-fav.starred { color: #f59e0b; }

/* Status dot */
.sym-status-dot {
  width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0;
}

/* Checkbox */
.sym-check {
  font-size: 14px; color: var(--dim); flex-shrink: 0; width: 16px; text-align: center;
}
.sym-item.selected .sym-check { color: #6366f1; }

/* Ticker */
.sym-item-ticker {
  font-family: 'JetBrains Mono', 'Fira Code', monospace;
  font-weight: 700; font-size: 12px; min-width: 100px;
  flex-shrink: 0;
}

/* Name */
.sym-item-name {
  flex: 1; color: var(--dim); font-size: 11px;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}

/* Badges */
.sym-badge {
  font-size: 9px; font-weight: 700; letter-spacing: 0.5px;
  padding: 2px 6px; border-radius: 4px; flex-shrink: 0;
  text-transform: uppercase;
}
.type-badge {
  background: var(--surface); color: var(--dim);
}

/* ── Transition ─────────────────────────────────────────────── */
.sym-dd-enter-active, .sym-dd-leave-active {
  transition: opacity 0.15s, transform 0.15s;
}
.sym-dd-enter-from, .sym-dd-leave-to {
  opacity: 0; transform: translateY(-6px);
}
</style>
