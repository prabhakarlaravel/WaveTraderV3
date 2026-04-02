<script setup>
import { ref, watch, onMounted, nextTick } from 'vue'
import axios from 'axios'

const props = defineProps({
  spot: { type: Number, required: true },
  symbolId: { type: Number, required: true },
  ticker: { type: String, required: true },
  confluence: { type: Object, default: null },
})

const emit = defineEmits(['select-strike'])

const loading = ref(false)
const chain = ref([])
const atmStrike = ref(null)
const selectedStrike = ref(null)
const selectedType = ref(null)
const expiries = ref([])
const selectedExpiry = ref(null)

const chainContainer = ref(null)

// Generate next 4 Thursdays as fallback expiries
function generateExpiries() {
  const dates = []
  const d = new Date()
  d.setHours(0, 0, 0, 0)
  while (dates.length < 4) {
    d.setDate(d.getDate() + 1)
    if (d.getDay() === 4) {
      dates.push(formatDate(d))
    }
  }
  return dates
}

function formatDate(d) {
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}

function formatExpiryLabel(dateStr) {
  const d = new Date(dateStr + 'T00:00:00')
  return d.toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' })
}

async function fetchChain() {
  if (!props.symbolId || !selectedExpiry.value) return
  loading.value = true
  try {
    const { data } = await axios.get('/api/v1/trades/options-chain', {
      params: { symbol_id: props.symbolId, expiry: selectedExpiry.value },
    })
    chain.value = data.chain || []
    atmStrike.value = data.atmStrike || null
    if (data.expiries && data.expiries.length) {
      expiries.value = data.expiries
    }
    await nextTick()
    scrollToAtm()
  } catch {
    chain.value = []
    atmStrike.value = null
  } finally {
    loading.value = false
  }
}

function scrollToAtm() {
  if (!chainContainer.value || !atmStrike.value) return
  const el = chainContainer.value.querySelector('[data-atm="true"]')
  if (el) {
    el.scrollIntoView({ block: 'center', behavior: 'smooth' })
  }
}

function selectStrike(row, type) {
  selectedStrike.value = row.strike
  selectedType.value = type
  const payload = {
    strike: row.strike,
    type,
    premium: type === 'CE' ? row.ce_premium : row.pe_premium,
    delta: type === 'CE' ? row.ce_delta : row.pe_delta,
  }
  emit('select-strike', payload)
}

function isItmCe(strike) {
  return props.spot > 0 && strike < props.spot
}

function isItmPe(strike) {
  return props.spot > 0 && strike > props.spot
}

function isAtm(strike) {
  return strike === atmStrike.value
}

function isSelected(strike) {
  return strike === selectedStrike.value
}

function fmtPrice(v) {
  if (v == null) return '—'
  return Number(v).toFixed(2)
}

function fmtDelta(v) {
  if (v == null) return '—'
  return Number(v).toFixed(2)
}

// Initialize expiries and fetch on mount
onMounted(() => {
  expiries.value = generateExpiries()
  selectedExpiry.value = expiries.value[0] || null
  fetchChain()
})

// Refetch when symbolId or expiry changes
watch(() => props.symbolId, () => {
  selectedStrike.value = null
  selectedType.value = null
  fetchChain()
})

watch(selectedExpiry, () => {
  selectedStrike.value = null
  selectedType.value = null
  fetchChain()
})
</script>

<template>
  <div :style="rootStyle">
    <!-- Expiry selector row -->
    <div :style="headerRowStyle">
      <div :style="{ display: 'flex', alignItems: 'center', gap: '8px' }">
        <label :style="{ color: '#8b9bbf', fontSize: '9px', textTransform: 'uppercase', letterSpacing: '0.5px' }">
          Expiry
        </label>
        <select
          v-model="selectedExpiry"
          :style="selectStyle"
        >
          <option v-for="exp in expiries" :key="exp" :value="exp">
            {{ formatExpiryLabel(exp) }}
          </option>
        </select>
      </div>
      <div :style="spotDisplayStyle">
        <span :style="{ color: '#8b9bbf', fontSize: '9px', marginRight: '6px' }">SPOT</span>
        <span :style="{ color: '#e8ecf4', fontFamily: monoFont, fontSize: '11px', fontWeight: 600 }">
          {{ fmtPrice(spot) }}
        </span>
      </div>
    </div>

    <!-- Confluence hint -->
    <div v-if="confluence" :style="confluenceBarStyle">
      <span :style="{ fontSize: '9px', color: '#8b9bbf' }">Confluence:</span>
      <span :style="{
        fontSize: '9px',
        fontWeight: 600,
        color: confluence.direction === 'bullish' ? '#00dc82' : '#ff3b5c',
        marginLeft: '4px',
      }">
        {{ confluence.direction === 'bullish' ? 'BUY CE' : 'BUY PE' }}
        {{ confluence.score ? `(${confluence.score})` : '' }}
      </span>
    </div>

    <!-- Table header -->
    <div :style="tableHeaderStyle">
      <span :style="colCeStyle">CE ₹</span>
      <span :style="colDeltaStyle">Δ</span>
      <span :style="colStrikeStyle">STRIKE</span>
      <span :style="colDeltaStyle">Δ</span>
      <span :style="colPeStyle">PE ₹</span>
    </div>

    <!-- Chain body -->
    <div ref="chainContainer" :style="chainBodyStyle">
      <!-- Loading skeleton -->
      <template v-if="loading">
        <div v-for="i in 12" :key="'skel-' + i" :style="skeletonRowStyle">
          <span :style="skelCellStyle" />
          <span :style="skelCellSmStyle" />
          <span :style="skelCellStyle" />
          <span :style="skelCellSmStyle" />
          <span :style="skelCellStyle" />
        </div>
      </template>

      <!-- Chain rows -->
      <template v-else-if="chain.length">
        <div
          v-for="row in chain"
          :key="row.strike"
          :data-atm="isAtm(row.strike) ? 'true' : undefined"
          :style="[
            rowBaseStyle,
            isAtm(row.strike) && atmRowStyle,
            isSelected(row.strike) && selectedRowStyle,
          ]"
        >
          <!-- CE cell -->
          <span
            :style="[
              cellStyle,
              cePremiumStyle,
              isItmCe(row.strike) && itmCeBgStyle,
            ]"
            @click="selectStrike(row, 'CE')"
          >
            {{ fmtPrice(row.ce_premium) }}
          </span>

          <!-- CE delta -->
          <span :style="[cellStyle, deltaStyle]">
            {{ fmtDelta(row.ce_delta) }}
          </span>

          <!-- Strike -->
          <span :style="[cellStyle, strikeStyle, isAtm(row.strike) && atmStrikeStyle]">
            {{ row.strike }}
            <span v-if="isAtm(row.strike)" :style="atmTagStyle">ATM</span>
          </span>

          <!-- PE delta -->
          <span :style="[cellStyle, deltaStyle]">
            {{ fmtDelta(row.pe_delta) }}
          </span>

          <!-- PE cell -->
          <span
            :style="[
              cellStyle,
              pePremiumStyle,
              isItmPe(row.strike) && itmPeBgStyle,
            ]"
            @click="selectStrike(row, 'PE')"
          >
            {{ fmtPrice(row.pe_premium) }}
          </span>
        </div>
      </template>

      <!-- Empty state -->
      <div v-else :style="emptyStyle">
        No chain data available
      </div>
    </div>
  </div>
</template>

<script>
// Inline style objects defined as a separate non-setup script block
// to keep the template clean and avoid recomputing static styles.
const monoFont = "'JetBrains Mono', 'SF Mono', monospace"

const rootStyle = {
  background: '#0f1729',
  border: '1px solid #243354',
  borderRadius: '6px',
  overflow: 'hidden',
  display: 'flex',
  flexDirection: 'column',
  fontFamily: "-apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif",
  color: '#e8ecf4',
  fontSize: '10px',
}

const headerRowStyle = {
  display: 'flex',
  justifyContent: 'space-between',
  alignItems: 'center',
  padding: '8px 10px',
  background: '#141e33',
  borderBottom: '1px solid #243354',
}

const selectStyle = {
  background: '#182240',
  color: '#e8ecf4',
  border: '1px solid #243354',
  borderRadius: '4px',
  padding: '3px 6px',
  fontSize: '10px',
  fontFamily: monoFont,
  outline: 'none',
  cursor: 'pointer',
}

const spotDisplayStyle = {
  display: 'flex',
  alignItems: 'center',
}

const confluenceBarStyle = {
  padding: '4px 10px',
  background: '#141e33',
  borderBottom: '1px solid #243354',
  display: 'flex',
  alignItems: 'center',
}

const tableHeaderStyle = {
  display: 'grid',
  gridTemplateColumns: '1fr 50px 80px 50px 1fr',
  padding: '5px 10px',
  background: '#141e33',
  borderBottom: '1px solid #243354',
  fontSize: '8px',
  textTransform: 'uppercase',
  letterSpacing: '0.5px',
  color: '#4a5a8a',
  fontWeight: 600,
}

const colCeStyle = { textAlign: 'right', paddingRight: '4px' }
const colDeltaStyle = { textAlign: 'center' }
const colStrikeStyle = { textAlign: 'center', color: '#8b9bbf' }
const colPeStyle = { textAlign: 'left', paddingLeft: '4px' }

const chainBodyStyle = {
  overflowY: 'auto',
  maxHeight: '400px',
  scrollbarWidth: 'thin',
  scrollbarColor: '#243354 #0f1729',
}

const rowBaseStyle = {
  display: 'grid',
  gridTemplateColumns: '1fr 50px 80px 50px 1fr',
  padding: '4px 10px',
  borderBottom: '1px solid rgba(36, 51, 84, 0.4)',
  transition: 'background 0.15s',
  cursor: 'default',
}

const atmRowStyle = {
  borderLeft: '3px solid #8b5cf6',
  background: 'rgba(139, 92, 246, 0.06)',
}

const selectedRowStyle = {
  borderLeft: '3px solid #3b82f6',
  background: 'rgba(59, 130, 246, 0.08)',
}

const cellStyle = {
  display: 'flex',
  alignItems: 'center',
  fontFamily: monoFont,
  fontSize: '10px',
  padding: '2px 4px',
  borderRadius: '3px',
}

const cePremiumStyle = {
  justifyContent: 'flex-end',
  color: '#00dc82',
  cursor: 'pointer',
}

const pePremiumStyle = {
  justifyContent: 'flex-start',
  color: '#ff3b5c',
  cursor: 'pointer',
}

const deltaStyle = {
  justifyContent: 'center',
  color: '#4a5a8a',
  fontSize: '9px',
}

const strikeStyle = {
  justifyContent: 'center',
  color: '#8b9bbf',
  fontWeight: 600,
  fontSize: '10px',
  position: 'relative',
  gap: '4px',
}

const atmStrikeStyle = {
  color: '#8b5cf6',
}

const atmTagStyle = {
  fontSize: '7px',
  background: '#8b5cf6',
  color: '#fff',
  padding: '1px 3px',
  borderRadius: '2px',
  fontWeight: 700,
  letterSpacing: '0.3px',
}

const itmCeBgStyle = {
  background: 'rgba(0, 220, 130, 0.05)',
}

const itmPeBgStyle = {
  background: 'rgba(255, 59, 92, 0.05)',
}

const skeletonRowStyle = {
  display: 'grid',
  gridTemplateColumns: '1fr 50px 80px 50px 1fr',
  padding: '6px 10px',
  gap: '6px',
  borderBottom: '1px solid rgba(36, 51, 84, 0.4)',
}

const skelCellStyle = {
  height: '12px',
  background: '#182240',
  borderRadius: '3px',
  animation: 'pulse 1.4s ease-in-out infinite',
}

const skelCellSmStyle = {
  height: '12px',
  background: '#182240',
  borderRadius: '3px',
  animation: 'pulse 1.4s ease-in-out infinite',
  width: '60%',
  margin: '0 auto',
}

const emptyStyle = {
  padding: '24px',
  textAlign: 'center',
  color: '#4a5a8a',
  fontSize: '10px',
}

export default {
  data() {
    return {
      monoFont,
      rootStyle,
      headerRowStyle,
      selectStyle,
      spotDisplayStyle,
      confluenceBarStyle,
      tableHeaderStyle,
      colCeStyle,
      colDeltaStyle,
      colStrikeStyle,
      colPeStyle,
      chainBodyStyle,
      rowBaseStyle,
      atmRowStyle,
      selectedRowStyle,
      cellStyle,
      cePremiumStyle,
      pePremiumStyle,
      deltaStyle,
      strikeStyle,
      atmStrikeStyle,
      atmTagStyle,
      itmCeBgStyle,
      itmPeBgStyle,
      skeletonRowStyle,
      skelCellStyle,
      skelCellSmStyle,
      emptyStyle,
    }
  },
}
</script>

<style>
@keyframes pulse {
  0%, 100% { opacity: 0.4; }
  50% { opacity: 0.8; }
}
</style>
