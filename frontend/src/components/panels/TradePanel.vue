<script setup>
import { ref, computed, watch, defineAsyncComponent } from 'vue'
import axios from 'axios'
import { useTradeStore } from '../../stores/useTradeStore'
import { useChartStore } from '../../stores/useChartStore'

const OptionsChainPanel = defineAsyncComponent(() =>
  import('./OptionsChainPanel.vue')
)

const tradeStore = useTradeStore()
const chartStore = useChartStore()

// ---------------------------------------------------------------------------
// Instrument detection
// ---------------------------------------------------------------------------
const instrumentType = computed(() => {
  const sym = chartStore.activeSymbol
  if (!sym) return 'equity'
  const exchange = (sym.exchange || '').toLowerCase()
  const type = (sym.type || '').toLowerCase()
  const ticker = (sym.ticker || '').toUpperCase()

  if (
    ['zerodha', 'nse', 'nfo'].includes(exchange) &&
    (type.includes('option') || type.includes('index') || ticker.includes('NIFTY') || ticker.includes('BANKNIFTY'))
  ) {
    return 'options'
  }
  if (exchange === 'binance') return 'crypto'
  if (exchange === 'oanda') return 'forex'
  return 'equity'
})

const currencySymbol = computed(() => {
  const ex = (chartStore.activeSymbol?.exchange || '').toLowerCase()
  return ['zerodha', 'nse', 'nfo'].includes(ex) ? '\u20B9' : '$'
})

const defaultRisk = computed(() => (currencySymbol.value === '\u20B9' ? 5000 : 200))

// ---------------------------------------------------------------------------
// Form state
// ---------------------------------------------------------------------------
const direction = ref('long')
const quantity = ref(1)
const slInput = ref('')
const tpInput = ref('')
const notesInput = ref('')
const submitting = ref(false)
const riskAmount = ref(null) // null = use defaultRisk
const trailingEnabled = ref(false)
const trailingInput = ref('')

// Options-specific
const optionStrike = ref(null)
const optionType = ref('CE')
const optionPremium = ref(null)
const optionExpiry = ref(null)
const optionLotSize = ref(null)

// Forex-specific
const forexLotSize = ref(0.1)
const forexLotPreset = ref('mini')

// Signal confirmation dialog
const signalConfirmation = ref(null)

const effectiveRisk = computed(() =>
  riskAmount.value != null && riskAmount.value > 0 ? riskAmount.value : defaultRisk.value
)

// ---------------------------------------------------------------------------
// Price helpers
// ---------------------------------------------------------------------------
const currentPrice = computed(() => {
  const c = chartStore.candles
  if (!c.length) return 0
  return parseFloat(c[c.length - 1].close)
})

const unrealizedPnls = computed(() => {
  const price = currentPrice.value
  const result = {}
  for (const trade of tradeStore.openTrades) {
    result[trade.id] = tradeStore.calcUnrealizedPnl(trade, price)
  }
  return result
})

const totalUnrealized = computed(() => tradeStore.totalUnrealizedPnl(currentPrice.value))

// ---------------------------------------------------------------------------
// Confluence / Signal Bridge
// ---------------------------------------------------------------------------
const confluence = computed(() =>
  chartStore.overlays?.confluence || chartStore.mtfConfluence
)

const signalLabel = computed(() => {
  if (!confluence.value) return null
  const cp = confluence.value.callPut || confluence.value.action || ''
  if (cp.includes('CALL')) return 'BUY CALL'
  if (cp.includes('PUT')) return 'BUY PUT'
  return 'WAIT'
})

const signalPct = computed(() =>
  confluence.value?.adjustedPct || confluence.value?.pct || 0
)

function onSignalClick() {
  if (!confluence.value || signalLabel.value === 'WAIT') return
  // Auto-fill the form instead of executing
  const isBuyCall = signalLabel.value === 'BUY CALL'
  direction.value = isBuyCall ? 'long' : 'short'
  if (confluence.value.sl != null) slInput.value = String(confluence.value.sl)
  if (confluence.value.tp != null) tpInput.value = String(confluence.value.tp)
  if (confluence.value.strike != null) optionStrike.value = confluence.value.strike
  if (confluence.value.premium != null) optionPremium.value = confluence.value.premium
  optionType.value = isBuyCall ? 'CE' : 'PE'
  notesInput.value = `Signal: ${signalLabel.value} (${signalPct.value}%)`
  // Show confirmation
  signalConfirmation.value = confluence.value
}

function confirmSignalTrade() {
  if (!signalConfirmation.value) return
  tradeStore.createFromSignal(signalConfirmation.value, effectiveRisk.value, {
    trailing_stop: trailingEnabled.value && trailingInput.value ? parseFloat(trailingInput.value) : null,
  })
  signalConfirmation.value = null
}

function cancelSignalTrade() {
  signalConfirmation.value = null
}

// ---------------------------------------------------------------------------
// Options chain event
// ---------------------------------------------------------------------------
function onSelectStrike(payload) {
  const type = payload.type || payload.option_type || 'CE'
  optionStrike.value = payload.strike
  optionType.value = type
  optionPremium.value = payload.premium
  optionExpiry.value = payload.expiry || null
  optionLotSize.value = payload.lot_size || null
  direction.value = type === 'PE' ? 'short' : 'long'
}

// ---------------------------------------------------------------------------
// Forex lot presets
// ---------------------------------------------------------------------------
function setForexPreset(preset) {
  forexLotPreset.value = preset
  const map = { micro: 0.01, mini: 0.10, std: 1.00 }
  forexLotSize.value = map[preset] || 0.10
}

// ---------------------------------------------------------------------------
// Risk calculator for options
// ---------------------------------------------------------------------------
const optionsCalc = computed(() => {
  if (instrumentType.value !== 'options') return null
  const premium = parseFloat(optionPremium.value) || 0
  const lotSz = parseInt(optionLotSize.value) || 1
  const risk = effectiveRisk.value
  if (!premium || !risk) return null
  const lots = Math.floor(risk / (premium * lotSz))
  const totalQty = lots * lotSz
  const totalCost = premium * totalQty
  return { lots, totalQty, totalCost }
})

// ---------------------------------------------------------------------------
// Crypto quantity calc
// ---------------------------------------------------------------------------
const cryptoCalc = computed(() => {
  if (instrumentType.value !== 'crypto') return null
  const price = currentPrice.value
  if (!price) return null
  const risk = effectiveRisk.value
  const qty = risk / price
  return { qty: parseFloat(qty.toFixed(6)), value: risk }
})

// ---------------------------------------------------------------------------
// Forex pip info
// ---------------------------------------------------------------------------
const forexPipInfo = computed(() => {
  if (instrumentType.value !== 'forex') return null
  const price = currentPrice.value
  if (!price) return null
  const lot = forexLotSize.value
  const pipValue = lot * 10 // Approximate for major pairs
  const sl = parseFloat(slInput.value) || 0
  const slDist = sl ? Math.abs(price - sl) : 0
  const slPips = Math.round(slDist * 10000)
  const riskDollars = slPips * pipValue
  const margin = lot * 100000 * 0.01 // 1% margin approx
  return { pipValue: pipValue.toFixed(2), margin: margin.toFixed(0), slPips, risk: riskDollars.toFixed(2) }
})

// ---------------------------------------------------------------------------
// Auto-check SL/TP
// ---------------------------------------------------------------------------
watch(currentPrice, (price) => {
  if (!price || !tradeStore.openTrades.length) return
  const autoClosed = tradeStore.checkStops(price)
  if (autoClosed.length) {
    console.log(`[Trade] Auto-closed ${autoClosed.length} positions at SL/TP`)
  }
})

// ---------------------------------------------------------------------------
// Submit trade
// ---------------------------------------------------------------------------
function submitTrade() {
  if (!chartStore.activeSymbolId || !currentPrice.value) return
  submitting.value = true
  try {
    const base = {
      symbol_id: chartStore.activeSymbolId,
      symbol_ticker: chartStore.activeSymbol?.ticker || '',
      type: direction.value,
      entry_price: currentPrice.value,
      quantity: quantity.value,
      sl: slInput.value ? parseFloat(slInput.value) : null,
      tp: tpInput.value ? parseFloat(tpInput.value) : null,
      notes: notesInput.value || null,
      timeframe: chartStore.activeTimeframe,
      trailing_stop: trailingEnabled.value && trailingInput.value ? parseFloat(trailingInput.value) : null,
      instrument_type: instrumentType.value,
    }

    if (instrumentType.value === 'options') {
      base.strike = optionStrike.value
      base.option_type = optionType.value
      base.premium = optionPremium.value
      base.expiry = optionExpiry.value
      base.lot_size = optionLotSize.value
      base.quantity = optionsCalc.value?.lots || 1
    } else if (instrumentType.value === 'crypto') {
      base.quantity = cryptoCalc.value?.qty || quantity.value
    } else if (instrumentType.value === 'forex') {
      base.quantity = forexLotSize.value
    }

    tradeStore.openTrade(base)
    slInput.value = ''
    tpInput.value = ''
    notesInput.value = ''
    trailingInput.value = ''
    trailingEnabled.value = false
  } finally {
    submitting.value = false
  }
}

function closeTrade(trade) {
  tradeStore.closeTrade(trade.id, currentPrice.value)
}

// ---------------------------------------------------------------------------
// Formatting helpers
// ---------------------------------------------------------------------------
function formatPrice(p) {
  if (!p) return '--'
  const n = parseFloat(p)
  return n.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function formatPnl(val) {
  const n = typeof val === 'number' ? val : parseFloat(val || 0)
  const sign = n >= 0 ? '+' : ''
  return `${sign}${currencySymbol.value}${Math.abs(n).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}

function formatCurrency(val) {
  const n = parseFloat(val || 0)
  return `${currencySymbol.value}${n.toLocaleString('en-IN', { minimumFractionDigits: 0, maximumFractionDigits: 0 })}`
}

// Trade badge label
function tradeBadge(trade) {
  if (trade.instrument_type === 'options' && trade.option_type) {
    return trade.option_type // CE or PE
  }
  return trade.type === 'long' ? 'LONG' : 'SHORT'
}

function tradeBadgeIsBull(trade) {
  if (trade.instrument_type === 'options') return trade.option_type === 'CE'
  return trade.type === 'long'
}

// Options buy button label
const optionsBuyLabel = computed(() => {
  if (!optionStrike.value || !optionPremium.value) return 'Select Strike First'
  const lots = optionsCalc.value?.lots || 0
  return `BUY ${optionStrike.value} ${optionType.value} @ ${currencySymbol.value}${parseFloat(optionPremium.value).toFixed(2)} x ${lots}`
})

// Crypto/Forex button label
const actionLabel = computed(() => {
  const price = formatPrice(currentPrice.value)
  if (instrumentType.value === 'crypto') {
    return direction.value === 'long'
      ? `LONG @ ${price}`
      : `SHORT @ ${price}`
  }
  if (instrumentType.value === 'forex') {
    return direction.value === 'long'
      ? `BUY @ ${price}`
      : `SELL @ ${price}`
  }
  return direction.value === 'long'
    ? `BUY @ ${price}`
    : `SELL @ ${price}`
})
</script>

<template>
  <div style="display:flex;flex-direction:column;height:100%;overflow:hidden;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;font-size:11px;color:#c8d3e8;background:#0a1628;">

    <!-- ================================================================== -->
    <!-- 1. VIRTUAL ACCOUNT BAR -->
    <!-- ================================================================== -->
    <div style="padding:8px 12px;border-bottom:1px solid #1a2844;background:#0d1b30;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
        <span style="font-size:10px;color:#5a6a8a;text-transform:uppercase;letter-spacing:0.5px;">Virtual Account: <span style="color:#c8d3e8;font-family:'SF Mono',Consolas,monospace;font-weight:600;">{{ formatCurrency(tradeStore.virtualCapital) }}</span></span>
        <span style="font-size:10px;color:#5a6a8a;">Available: <span style="font-family:'SF Mono',Consolas,monospace;font-weight:600;" :style="{ color: tradeStore.availableMargin >= 0 ? '#00dc82' : '#ff3b5c' }">{{ formatCurrency(tradeStore.availableMargin) }}</span></span>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px;text-align:center;">
        <div>
          <div style="font-size:9px;color:#4a5a7a;text-transform:uppercase;">Today P&L</div>
          <div style="font-family:'SF Mono',Consolas,monospace;font-size:10px;font-weight:600;" :style="{ color: tradeStore.dailyPnl >= 0 ? '#00dc82' : '#ff3b5c' }">{{ formatPnl(tradeStore.dailyPnl) }}</div>
        </div>
        <div>
          <div style="font-size:9px;color:#4a5a7a;text-transform:uppercase;">Win Rate</div>
          <div style="font-family:'SF Mono',Consolas,monospace;font-size:10px;font-weight:600;" :style="{ color: tradeStore.winRate >= 50 ? '#00dc82' : tradeStore.winRate > 0 ? '#ff3b5c' : '#5a6a8a' }">{{ tradeStore.winRate }}%</div>
        </div>
        <div>
          <div style="font-size:9px;color:#4a5a7a;text-transform:uppercase;">Open P&L</div>
          <div style="font-family:'SF Mono',Consolas,monospace;font-size:10px;font-weight:600;" :style="{ color: totalUnrealized >= 0 ? '#00dc82' : '#ff3b5c' }">{{ formatPnl(totalUnrealized) }}</div>
        </div>
      </div>
    </div>

    <!-- ================================================================== -->
    <!-- 2. SIGNAL BRIDGE -->
    <!-- ================================================================== -->
    <div v-if="confluence" style="padding:6px 12px;border-bottom:1px solid #1a2844;">
      <div
        @click="onSignalClick"
        style="display:flex;align-items:center;justify-content:space-between;padding:8px 10px;border-radius:6px;cursor:pointer;transition:opacity 0.15s;"
        :style="{
          background: signalLabel === 'BUY CALL' ? 'rgba(0,220,130,0.12)' : signalLabel === 'BUY PUT' ? 'rgba(255,59,92,0.12)' : 'rgba(245,166,35,0.12)',
          border: '1px solid ' + (signalLabel === 'BUY CALL' ? 'rgba(0,220,130,0.3)' : signalLabel === 'BUY PUT' ? 'rgba(255,59,92,0.3)' : 'rgba(245,166,35,0.3)'),
        }"
      >
        <div style="display:flex;align-items:center;gap:8px;">
          <span style="font-size:14px;">{{ signalLabel === 'BUY CALL' ? '\u{1F7E2}' : signalLabel === 'BUY PUT' ? '\u{1F534}' : '\u{1F7E1}' }}</span>
          <span style="font-size:11px;font-weight:700;letter-spacing:0.5px;"
            :style="{ color: signalLabel === 'BUY CALL' ? '#00dc82' : signalLabel === 'BUY PUT' ? '#ff3b5c' : '#f5a623' }">
            {{ signalLabel }}
          </span>
          <span style="font-family:'SF Mono',Consolas,monospace;font-size:10px;padding:1px 6px;border-radius:3px;font-weight:600;"
            :style="{
              background: signalPct >= 60 ? 'rgba(0,220,130,0.15)' : 'rgba(100,100,100,0.15)',
              color: signalPct >= 60 ? '#00dc82' : '#8892a8',
            }">
            {{ signalPct }}%
          </span>
        </div>
        <span style="font-size:12px;color:#5a6a8a;">\u2192</span>
      </div>

      <!-- Signal confirmation dialog -->
      <div v-if="signalConfirmation" style="margin-top:6px;padding:8px 10px;border-radius:6px;background:rgba(59,130,246,0.08);border:1px solid rgba(59,130,246,0.25);">
        <div style="font-size:10px;color:#7ba4e8;margin-bottom:6px;">Confirm signal trade?</div>
        <div style="display:flex;gap:6px;">
          <button @click="confirmSignalTrade" style="flex:1;padding:5px 0;border-radius:4px;font-size:10px;font-weight:600;border:none;cursor:pointer;background:rgba(0,220,130,0.2);color:#00dc82;">Execute</button>
          <button @click="cancelSignalTrade" style="flex:1;padding:5px 0;border-radius:4px;font-size:10px;font-weight:600;border:none;cursor:pointer;background:rgba(255,59,92,0.1);color:#ff3b5c;">Cancel</button>
        </div>
      </div>
    </div>

    <!-- ================================================================== -->
    <!-- 3. INSTRUMENT-SPECIFIC FORM -->
    <!-- ================================================================== -->
    <div style="padding:10px 12px;border-bottom:1px solid #1a2844;overflow-y:auto;flex-shrink:0;">

      <!-- ============================================================== -->
      <!-- 3A. NSE OPTIONS FORM -->
      <!-- ============================================================== -->
      <template v-if="instrumentType === 'options'">
        <!-- Options chain component -->
        <div style="margin-bottom:8px;">
          <OptionsChainPanel
            :spot="currentPrice"
            :symbol-id="chartStore.activeSymbolId"
            :ticker="chartStore.activeSymbol?.ticker || ''"
            :confluence="confluence"
            @select-strike="onSelectStrike"
          />
        </div>

        <!-- Selected strike display -->
        <div v-if="optionStrike" style="display:flex;align-items:center;gap:8px;padding:6px 10px;border-radius:5px;margin-bottom:8px;background:#182240;border:1px solid #243354;">
          <span style="font-size:10px;font-weight:700;padding:2px 6px;border-radius:3px;"
            :style="{ background: optionType === 'CE' ? 'rgba(0,220,130,0.15)' : 'rgba(255,59,92,0.15)', color: optionType === 'CE' ? '#00dc82' : '#ff3b5c' }">
            {{ optionType }}
          </span>
          <span style="font-family:'SF Mono',Consolas,monospace;font-size:11px;color:#c8d3e8;font-weight:600;">{{ optionStrike }}</span>
          <span style="font-family:'SF Mono',Consolas,monospace;font-size:10px;color:#8892a8;">@ {{ currencySymbol }}{{ parseFloat(optionPremium || 0).toFixed(2) }}</span>
        </div>

        <!-- Risk amount -->
        <div style="margin-bottom:8px;">
          <div style="font-size:9px;color:#5a6a8a;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:3px;">Risk Amount</div>
          <input v-model.number="riskAmount" type="number" :placeholder="String(defaultRisk)"
            style="width:100%;padding:6px 10px;border-radius:5px;font-size:11px;background:#182240;border:1px solid #243354;color:#c8d3e8;font-family:'SF Mono',Consolas,monospace;outline:none;" />
        </div>

        <!-- Lots calc display -->
        <div v-if="optionsCalc" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px;margin-bottom:8px;text-align:center;">
          <div style="padding:4px;border-radius:4px;background:rgba(24,34,64,0.5);">
            <div style="font-size:9px;color:#4a5a7a;">Lots</div>
            <div style="font-family:'SF Mono',Consolas,monospace;font-size:11px;font-weight:600;color:#c8d3e8;">{{ optionsCalc.lots }}</div>
          </div>
          <div style="padding:4px;border-radius:4px;background:rgba(24,34,64,0.5);">
            <div style="font-size:9px;color:#4a5a7a;">Qty</div>
            <div style="font-family:'SF Mono',Consolas,monospace;font-size:11px;font-weight:600;color:#c8d3e8;">{{ optionsCalc.totalQty }}</div>
          </div>
          <div style="padding:4px;border-radius:4px;background:rgba(24,34,64,0.5);">
            <div style="font-size:9px;color:#4a5a7a;">Cost</div>
            <div style="font-family:'SF Mono',Consolas,monospace;font-size:11px;font-weight:600;color:#c8d3e8;">{{ formatCurrency(optionsCalc.totalCost) }}</div>
          </div>
        </div>

        <!-- SL / TP in premium terms -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:8px;">
          <div>
            <div style="font-size:9px;color:#ff3b5c;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:3px;">SL (Premium)</div>
            <input v-model="slInput" type="number" step="any" placeholder="e.g. 180"
              style="width:100%;padding:6px 10px;border-radius:5px;font-size:11px;background:#182240;border:1px solid #243354;color:#c8d3e8;font-family:'SF Mono',Consolas,monospace;outline:none;" />
          </div>
          <div>
            <div style="font-size:9px;color:#00dc82;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:3px;">TP (Premium)</div>
            <input v-model="tpInput" type="number" step="any" placeholder="e.g. 450"
              style="width:100%;padding:6px 10px;border-radius:5px;font-size:11px;background:#182240;border:1px solid #243354;color:#c8d3e8;font-family:'SF Mono',Consolas,monospace;outline:none;" />
          </div>
        </div>

        <!-- Trailing stop toggle -->
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:10px;color:#8892a8;">
            <input type="checkbox" v-model="trailingEnabled" style="accent-color:#3b82f6;" />
            Trailing Stop
          </label>
          <input v-if="trailingEnabled" v-model="trailingInput" type="number" step="any" placeholder="Distance"
            style="flex:1;padding:4px 8px;border-radius:4px;font-size:10px;background:#182240;border:1px solid #243354;color:#c8d3e8;font-family:'SF Mono',Consolas,monospace;outline:none;" />
        </div>

        <!-- BUY button -->
        <button @click="submitTrade" :disabled="submitting || !optionStrike"
          style="width:100%;padding:8px 0;border-radius:5px;font-size:11px;font-weight:700;border:none;cursor:pointer;transition:opacity 0.15s;"
          :style="{
            background: optionType === 'CE' ? '#00dc82' : '#ff3b5c',
            color: optionType === 'CE' ? '#000' : '#fff',
            opacity: (!optionStrike || submitting) ? 0.5 : 1,
          }">
          {{ submitting ? 'Placing...' : optionsBuyLabel }}
        </button>
      </template>

      <!-- ============================================================== -->
      <!-- 3B. CRYPTO FORM -->
      <!-- ============================================================== -->
      <template v-else-if="instrumentType === 'crypto'">
        <!-- Direction toggle -->
        <div style="display:flex;gap:4px;margin-bottom:8px;padding:3px;border-radius:6px;background:#111d35;border:1px solid #1a2844;">
          <button @click="direction = 'long'"
            style="flex:1;padding:6px 0;border-radius:4px;font-size:10px;font-weight:700;border:none;cursor:pointer;transition:all 0.15s;"
            :style="direction === 'long' ? 'background:rgba(0,220,130,0.15);color:#00dc82;border:1px solid rgba(0,220,130,0.3)' : 'background:transparent;color:#5a6a8a;border:1px solid transparent'">
            LONG
          </button>
          <button @click="direction = 'short'"
            style="flex:1;padding:6px 0;border-radius:4px;font-size:10px;font-weight:700;border:none;cursor:pointer;transition:all 0.15s;"
            :style="direction === 'short' ? 'background:rgba(255,59,92,0.15);color:#ff3b5c;border:1px solid rgba(255,59,92,0.3)' : 'background:transparent;color:#5a6a8a;border:1px solid transparent'">
            SHORT
          </button>
        </div>

        <!-- Entry price display -->
        <div style="margin-bottom:8px;">
          <div style="font-size:9px;color:#5a6a8a;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:3px;">Entry (Market)</div>
          <div style="padding:6px 10px;border-radius:5px;font-family:'SF Mono',Consolas,monospace;font-size:12px;font-weight:700;background:#182240;border:1px solid #243354;"
            :style="{ color: direction === 'long' ? '#00dc82' : '#ff3b5c' }">
            {{ formatPrice(currentPrice) }}
          </div>
        </div>

        <!-- Risk amount -->
        <div style="margin-bottom:8px;">
          <div style="font-size:9px;color:#5a6a8a;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:3px;">Risk Amount ($)</div>
          <input v-model.number="riskAmount" type="number" :placeholder="String(defaultRisk)"
            style="width:100%;padding:6px 10px;border-radius:5px;font-size:11px;background:#182240;border:1px solid #243354;color:#c8d3e8;font-family:'SF Mono',Consolas,monospace;outline:none;" />
        </div>

        <!-- Quantity + Value -->
        <div v-if="cryptoCalc" style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:8px;">
          <div style="padding:5px 8px;border-radius:4px;background:rgba(24,34,64,0.5);">
            <div style="font-size:9px;color:#4a5a7a;">Quantity</div>
            <div style="font-family:'SF Mono',Consolas,monospace;font-size:11px;font-weight:600;color:#c8d3e8;">{{ cryptoCalc.qty }}</div>
          </div>
          <div style="padding:5px 8px;border-radius:4px;background:rgba(24,34,64,0.5);">
            <div style="font-size:9px;color:#4a5a7a;">Value</div>
            <div style="font-family:'SF Mono',Consolas,monospace;font-size:11px;font-weight:600;color:#c8d3e8;">${{ cryptoCalc.value }}</div>
          </div>
        </div>

        <!-- SL / TP -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:8px;">
          <div>
            <div style="font-size:9px;color:#ff3b5c;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:3px;">Stop Loss</div>
            <input v-model="slInput" type="number" step="any" placeholder="Optional"
              style="width:100%;padding:6px 10px;border-radius:5px;font-size:11px;background:#182240;border:1px solid #243354;color:#c8d3e8;font-family:'SF Mono',Consolas,monospace;outline:none;" />
          </div>
          <div>
            <div style="font-size:9px;color:#00dc82;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:3px;">Take Profit</div>
            <input v-model="tpInput" type="number" step="any" placeholder="Optional"
              style="width:100%;padding:6px 10px;border-radius:5px;font-size:11px;background:#182240;border:1px solid #243354;color:#c8d3e8;font-family:'SF Mono',Consolas,monospace;outline:none;" />
          </div>
        </div>

        <!-- Trailing stop -->
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:10px;color:#8892a8;">
            <input type="checkbox" v-model="trailingEnabled" style="accent-color:#3b82f6;" />
            Trailing Stop
          </label>
          <input v-if="trailingEnabled" v-model="trailingInput" type="number" step="any" placeholder="Distance"
            style="flex:1;padding:4px 8px;border-radius:4px;font-size:10px;background:#182240;border:1px solid #243354;color:#c8d3e8;font-family:'SF Mono',Consolas,monospace;outline:none;" />
        </div>

        <!-- Submit -->
        <button @click="submitTrade" :disabled="submitting || !currentPrice"
          style="width:100%;padding:8px 0;border-radius:5px;font-size:11px;font-weight:700;border:none;cursor:pointer;transition:opacity 0.15s;"
          :style="{
            background: direction === 'long' ? '#00dc82' : '#ff3b5c',
            color: direction === 'long' ? '#000' : '#fff',
            opacity: submitting ? 0.5 : 1,
          }">
          {{ submitting ? 'Placing...' : actionLabel }}
        </button>
      </template>

      <!-- ============================================================== -->
      <!-- 3C. FOREX FORM -->
      <!-- ============================================================== -->
      <template v-else-if="instrumentType === 'forex'">
        <!-- Direction toggle -->
        <div style="display:flex;gap:4px;margin-bottom:8px;padding:3px;border-radius:6px;background:#111d35;border:1px solid #1a2844;">
          <button @click="direction = 'long'"
            style="flex:1;padding:6px 0;border-radius:4px;font-size:10px;font-weight:700;border:none;cursor:pointer;transition:all 0.15s;"
            :style="direction === 'long' ? 'background:rgba(0,220,130,0.15);color:#00dc82;border:1px solid rgba(0,220,130,0.3)' : 'background:transparent;color:#5a6a8a;border:1px solid transparent'">
            BUY (LONG)
          </button>
          <button @click="direction = 'short'"
            style="flex:1;padding:6px 0;border-radius:4px;font-size:10px;font-weight:700;border:none;cursor:pointer;transition:all 0.15s;"
            :style="direction === 'short' ? 'background:rgba(255,59,92,0.15);color:#ff3b5c;border:1px solid rgba(255,59,92,0.3)' : 'background:transparent;color:#5a6a8a;border:1px solid transparent'">
            SELL (SHORT)
          </button>
        </div>

        <!-- Lot size selector -->
        <div style="margin-bottom:8px;">
          <div style="font-size:9px;color:#5a6a8a;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:3px;">Lot Size</div>
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:4px;">
            <button @click="setForexPreset('micro')"
              style="padding:5px 0;border-radius:4px;font-size:9px;font-weight:600;border:none;cursor:pointer;"
              :style="forexLotPreset === 'micro' ? 'background:rgba(59,130,246,0.2);color:#7ba4e8;border:1px solid rgba(59,130,246,0.3)' : 'background:#182240;color:#5a6a8a;border:1px solid #243354'">
              0.01<br><span style="font-size:8px;opacity:0.7;">Micro</span>
            </button>
            <button @click="setForexPreset('mini')"
              style="padding:5px 0;border-radius:4px;font-size:9px;font-weight:600;border:none;cursor:pointer;"
              :style="forexLotPreset === 'mini' ? 'background:rgba(59,130,246,0.2);color:#7ba4e8;border:1px solid rgba(59,130,246,0.3)' : 'background:#182240;color:#5a6a8a;border:1px solid #243354'">
              0.10<br><span style="font-size:8px;opacity:0.7;">Mini</span>
            </button>
            <button @click="setForexPreset('std')"
              style="padding:5px 0;border-radius:4px;font-size:9px;font-weight:600;border:none;cursor:pointer;"
              :style="forexLotPreset === 'std' ? 'background:rgba(59,130,246,0.2);color:#7ba4e8;border:1px solid rgba(59,130,246,0.3)' : 'background:#182240;color:#5a6a8a;border:1px solid #243354'">
              1.00<br><span style="font-size:8px;opacity:0.7;">Std</span>
            </button>
            <div>
              <input v-model.number="forexLotSize" type="number" step="0.01" min="0.01"
                @focus="forexLotPreset = 'custom'"
                style="width:100%;height:100%;padding:4px 6px;border-radius:4px;font-size:9px;background:#182240;border:1px solid #243354;color:#c8d3e8;font-family:'SF Mono',Consolas,monospace;outline:none;text-align:center;" />
            </div>
          </div>
        </div>

        <!-- Pip info row -->
        <div v-if="forexPipInfo" style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:4px;margin-bottom:8px;">
          <div style="padding:4px;border-radius:4px;background:rgba(24,34,64,0.5);text-align:center;">
            <div style="font-size:8px;color:#4a5a7a;">Pip Val</div>
            <div style="font-family:'SF Mono',Consolas,monospace;font-size:10px;color:#c8d3e8;">${{ forexPipInfo.pipValue }}</div>
          </div>
          <div style="padding:4px;border-radius:4px;background:rgba(24,34,64,0.5);text-align:center;">
            <div style="font-size:8px;color:#4a5a7a;">Margin</div>
            <div style="font-family:'SF Mono',Consolas,monospace;font-size:10px;color:#c8d3e8;">${{ forexPipInfo.margin }}</div>
          </div>
          <div style="padding:4px;border-radius:4px;background:rgba(24,34,64,0.5);text-align:center;">
            <div style="font-size:8px;color:#4a5a7a;">SL Pips</div>
            <div style="font-family:'SF Mono',Consolas,monospace;font-size:10px;color:#c8d3e8;">{{ forexPipInfo.slPips || '--' }}</div>
          </div>
          <div style="padding:4px;border-radius:4px;background:rgba(24,34,64,0.5);text-align:center;">
            <div style="font-size:8px;color:#4a5a7a;">Risk</div>
            <div style="font-family:'SF Mono',Consolas,monospace;font-size:10px;" :style="{ color: parseFloat(forexPipInfo.risk) > 0 ? '#ff3b5c' : '#5a6a8a' }">${{ forexPipInfo.risk }}</div>
          </div>
        </div>

        <!-- SL / TP -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:8px;">
          <div>
            <div style="font-size:9px;color:#ff3b5c;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:3px;">Stop Loss</div>
            <input v-model="slInput" type="number" step="any" placeholder="Price"
              style="width:100%;padding:6px 10px;border-radius:5px;font-size:11px;background:#182240;border:1px solid #243354;color:#c8d3e8;font-family:'SF Mono',Consolas,monospace;outline:none;" />
          </div>
          <div>
            <div style="font-size:9px;color:#00dc82;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:3px;">Take Profit</div>
            <input v-model="tpInput" type="number" step="any" placeholder="Price"
              style="width:100%;padding:6px 10px;border-radius:5px;font-size:11px;background:#182240;border:1px solid #243354;color:#c8d3e8;font-family:'SF Mono',Consolas,monospace;outline:none;" />
          </div>
        </div>

        <!-- Trailing stop -->
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:10px;color:#8892a8;">
            <input type="checkbox" v-model="trailingEnabled" style="accent-color:#3b82f6;" />
            Trailing Stop
          </label>
          <input v-if="trailingEnabled" v-model="trailingInput" type="number" step="any" placeholder="Pips"
            style="flex:1;padding:4px 8px;border-radius:4px;font-size:10px;background:#182240;border:1px solid #243354;color:#c8d3e8;font-family:'SF Mono',Consolas,monospace;outline:none;" />
        </div>

        <!-- Submit -->
        <button @click="submitTrade" :disabled="submitting || !currentPrice"
          style="width:100%;padding:8px 0;border-radius:5px;font-size:11px;font-weight:700;border:none;cursor:pointer;transition:opacity 0.15s;"
          :style="{
            background: direction === 'long' ? '#00dc82' : '#ff3b5c',
            color: direction === 'long' ? '#000' : '#fff',
            opacity: submitting ? 0.5 : 1,
          }">
          {{ submitting ? 'Placing...' : actionLabel }}
        </button>
      </template>

      <!-- ============================================================== -->
      <!-- 3D. DEFAULT / EQUITY FORM -->
      <!-- ============================================================== -->
      <template v-else>
        <!-- Direction toggle -->
        <div style="display:flex;gap:4px;margin-bottom:8px;padding:3px;border-radius:6px;background:#111d35;border:1px solid #1a2844;">
          <button @click="direction = 'long'"
            style="flex:1;padding:6px 0;border-radius:4px;font-size:10px;font-weight:700;border:none;cursor:pointer;transition:all 0.15s;"
            :style="direction === 'long' ? 'background:rgba(0,220,130,0.15);color:#00dc82;border:1px solid rgba(0,220,130,0.3)' : 'background:transparent;color:#5a6a8a;border:1px solid transparent'">
            LONG
          </button>
          <button @click="direction = 'short'"
            style="flex:1;padding:6px 0;border-radius:4px;font-size:10px;font-weight:700;border:none;cursor:pointer;transition:all 0.15s;"
            :style="direction === 'short' ? 'background:rgba(255,59,92,0.15);color:#ff3b5c;border:1px solid rgba(255,59,92,0.3)' : 'background:transparent;color:#5a6a8a;border:1px solid transparent'">
            SHORT
          </button>
        </div>

        <!-- Entry price -->
        <div style="margin-bottom:8px;">
          <div style="font-size:9px;color:#5a6a8a;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:3px;">Entry (Market)</div>
          <div style="padding:6px 10px;border-radius:5px;font-family:'SF Mono',Consolas,monospace;font-size:12px;font-weight:700;background:#182240;border:1px solid #243354;"
            :style="{ color: direction === 'long' ? '#00dc82' : '#ff3b5c' }">
            {{ formatPrice(currentPrice) }}
          </div>
        </div>

        <!-- Quantity -->
        <div style="margin-bottom:8px;">
          <div style="font-size:9px;color:#5a6a8a;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:3px;">Quantity</div>
          <input v-model.number="quantity" type="number" min="1" step="1"
            style="width:100%;padding:6px 10px;border-radius:5px;font-size:11px;background:#182240;border:1px solid #243354;color:#c8d3e8;font-family:'SF Mono',Consolas,monospace;outline:none;" />
        </div>

        <!-- SL / TP -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:8px;">
          <div>
            <div style="font-size:9px;color:#ff3b5c;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:3px;">Stop Loss</div>
            <input v-model="slInput" type="number" step="any" placeholder="Optional"
              style="width:100%;padding:6px 10px;border-radius:5px;font-size:11px;background:#182240;border:1px solid #243354;color:#c8d3e8;font-family:'SF Mono',Consolas,monospace;outline:none;" />
          </div>
          <div>
            <div style="font-size:9px;color:#00dc82;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:3px;">Take Profit</div>
            <input v-model="tpInput" type="number" step="any" placeholder="Optional"
              style="width:100%;padding:6px 10px;border-radius:5px;font-size:11px;background:#182240;border:1px solid #243354;color:#c8d3e8;font-family:'SF Mono',Consolas,monospace;outline:none;" />
          </div>
        </div>

        <!-- Notes -->
        <div style="margin-bottom:8px;">
          <div style="font-size:9px;color:#5a6a8a;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:3px;">Notes</div>
          <input v-model="notesInput" type="text" placeholder="Trade reason..."
            style="width:100%;padding:6px 10px;border-radius:5px;font-size:10px;background:#182240;border:1px solid #243354;color:#c8d3e8;outline:none;" />
        </div>

        <!-- Trailing stop -->
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:10px;color:#8892a8;">
            <input type="checkbox" v-model="trailingEnabled" style="accent-color:#3b82f6;" />
            Trailing Stop
          </label>
          <input v-if="trailingEnabled" v-model="trailingInput" type="number" step="any" placeholder="Distance"
            style="flex:1;padding:4px 8px;border-radius:4px;font-size:10px;background:#182240;border:1px solid #243354;color:#c8d3e8;font-family:'SF Mono',Consolas,monospace;outline:none;" />
        </div>

        <!-- Submit -->
        <button @click="submitTrade" :disabled="submitting || !currentPrice"
          style="width:100%;padding:8px 0;border-radius:5px;font-size:11px;font-weight:700;border:none;cursor:pointer;transition:opacity 0.15s;"
          :style="{
            background: direction === 'long' ? '#00dc82' : '#ff3b5c',
            color: direction === 'long' ? '#000' : '#fff',
            opacity: submitting ? 0.5 : 1,
          }">
          {{ submitting ? 'Placing...' : actionLabel }}
        </button>
      </template>
    </div>

    <!-- ================================================================== -->
    <!-- 4. OPEN POSITIONS -->
    <!-- ================================================================== -->
    <div style="flex:1;overflow-y:auto;min-height:0;">
      <div style="display:flex;align-items:center;justify-content:space-between;padding:6px 12px;border-bottom:1px solid #1a2844;">
        <span style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;color:#5a6a8a;">Open Positions</span>
        <span style="font-size:9px;font-weight:700;padding:1px 7px;border-radius:10px;background:#182240;color:#8892a8;">{{ tradeStore.openTrades.length }}</span>
      </div>

      <div v-if="!tradeStore.openTrades.length" style="padding:24px 12px;text-align:center;font-size:10px;color:#3a4a6a;">
        No open positions
      </div>

      <div v-for="trade in tradeStore.openTrades" :key="trade.id"
        style="padding:8px 12px;border-bottom:1px solid rgba(26,40,68,0.5);">
        <!-- Row 1: Badge + unrealized P&L -->
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;">
          <span style="font-size:9px;font-weight:700;padding:2px 6px;border-radius:3px;"
            :style="{
              background: tradeBadgeIsBull(trade) ? 'rgba(0,220,130,0.15)' : 'rgba(255,59,92,0.15)',
              color: tradeBadgeIsBull(trade) ? '#00dc82' : '#ff3b5c',
            }">
            {{ tradeBadge(trade) }}
          </span>
          <span style="font-family:'SF Mono',Consolas,monospace;font-size:11px;font-weight:700;"
            :style="{ color: (unrealizedPnls[trade.id] || 0) >= 0 ? '#00dc82' : '#ff3b5c' }">
            {{ formatPnl(unrealizedPnls[trade.id] || 0) }}
          </span>
        </div>

        <!-- Row 2: Entry / Now prices -->
        <div style="display:flex;align-items:center;justify-content:space-between;font-family:'SF Mono',Consolas,monospace;font-size:10px;color:#6a7a9a;margin-bottom:3px;">
          <span>Entry: {{ formatPrice(trade.entry_price) }}</span>
          <span style="color:#5a6a8a;">-></span>
          <span>Now: {{ formatPrice(currentPrice) }}</span>
        </div>

        <!-- Row 3: SL / TP / Trailing indicators -->
        <div v-if="trade.sl || trade.tp || trade.trailing_stop" style="display:flex;gap:8px;font-family:'SF Mono',Consolas,monospace;font-size:9px;margin-bottom:4px;">
          <span v-if="trade.sl" style="color:#ff3b5c;">SL: {{ formatPrice(trade.sl) }}</span>
          <span v-if="trade.tp" style="color:#00dc82;">TP: {{ formatPrice(trade.tp) }}</span>
          <span v-if="trade.trailing_stop" style="color:#3b82f6;">TS: {{ trade.trailing_stop }}</span>
        </div>

        <!-- Close button -->
        <button @click="closeTrade(trade)"
          style="width:100%;padding:4px 0;border-radius:4px;font-size:9px;font-weight:600;border:none;cursor:pointer;background:rgba(255,59,92,0.1);border:1px solid rgba(255,59,92,0.25);color:#ff3b5c;transition:background 0.15s;">
          Close @ {{ formatPrice(currentPrice) }}
        </button>
      </div>
    </div>

    <!-- ================================================================== -->
    <!-- 5. P&L SUMMARY FOOTER -->
    <!-- ================================================================== -->
    <div style="padding:8px 12px;border-top:1px solid #1a2844;background:#0d1b30;flex-shrink:0;">
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px;text-align:center;">
        <div>
          <div style="font-size:9px;color:#4a5a7a;text-transform:uppercase;">Realized</div>
          <div style="font-family:'SF Mono',Consolas,monospace;font-size:11px;font-weight:600;"
            :style="{ color: tradeStore.totalPnl >= 0 ? '#00dc82' : '#ff3b5c' }">
            {{ formatPnl(tradeStore.totalPnl) }}
          </div>
        </div>
        <div>
          <div style="font-size:9px;color:#4a5a7a;text-transform:uppercase;">Unrealized</div>
          <div style="font-family:'SF Mono',Consolas,monospace;font-size:11px;font-weight:600;"
            :style="{ color: totalUnrealized >= 0 ? '#00dc82' : '#ff3b5c' }">
            {{ formatPnl(totalUnrealized) }}
          </div>
        </div>
        <div>
          <div style="font-size:9px;color:#4a5a7a;text-transform:uppercase;">Win Rate</div>
          <div style="font-family:'SF Mono',Consolas,monospace;font-size:11px;font-weight:600;"
            :style="{ color: tradeStore.winRate >= 50 ? '#00dc82' : tradeStore.winRate > 0 ? '#ff3b5c' : '#5a6a8a' }">
            {{ tradeStore.winRate }}%
          </div>
        </div>
      </div>
      <div style="font-size:9px;text-align:center;margin-top:4px;color:#4a5a7a;">
        {{ tradeStore.closedTrades.length }} closed &middot; {{ tradeStore.openTrades.length }} open
      </div>
    </div>
  </div>
</template>
