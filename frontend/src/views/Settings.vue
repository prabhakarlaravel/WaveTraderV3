<script setup>
import { ref, onMounted, reactive, computed } from 'vue'
import { useRoute } from 'vue-router'
import { useSettingsStore } from '../stores/useSettingsStore'
import SymbolManager from '../components/settings/SymbolManager.vue'
import axios from 'axios'

const store    = useSettingsStore()
const route    = useRoute()
const activeTab = ref('exchange')
const tabs = [
  { id: 'exchange', label: 'Exchange' },
  { id: 'engine',   label: 'Engines' },
  { id: 'system',   label: 'System' },
  { id: 'backup',   label: 'Backup' },
]

// ── Exchange state ──────────────────────────────────────────────────────────
// Zerodha has no access-token field here — it is generated via KiteConnect OAuth.
const exchanges = reactive([
  {
    id: 'binance', name: 'Binance', desc: 'Crypto — REST API v3',
    fields: [
      { key: 'binance_api_key',    label: 'API Key',    type: 'password', encrypted: true },
      { key: 'binance_api_secret', label: 'API Secret', type: 'password', encrypted: true },
    ],
    saving: false, testing: false, status: null,
  },
  {
    id: 'zerodha', name: 'Zerodha', desc: 'NSE/BSE — KiteConnect v3',
    fields: [
      { key: 'zerodha_api_key',    label: 'API Key',    type: 'password', encrypted: true },
      { key: 'zerodha_api_secret', label: 'API Secret', type: 'password', encrypted: true },
    ],
    saving: false, testing: false, status: null,
  },
  {
    id: 'oanda', name: 'OANDA', desc: 'Forex — REST v20',
    fields: [
      { key: 'oanda_account_id',    label: 'Account ID',    type: 'text',     encrypted: false },
      { key: 'oanda_bearer_token',  label: 'Bearer Token',  type: 'password', encrypted: true  },
      { key: 'oanda_mode',          label: 'Mode',          type: 'select',   options: ['practice', 'live'], encrypted: false },
    ],
    saving: false, testing: false, status: null,
  },
])
const exchangeValues = reactive({})

// ── Zerodha-specific state ──────────────────────────────────────────────────
const zerodha = reactive({
  tokenLoading:    false,
  tokenExchanging: false,
  balanceLoading:  false,
  hasToken:        false,
  expired:         false,
  userName:        '',
  equity:          null,
  commodity:       null,
  tokenMsg:        '',
  tokenMsgType:    'success', // 'success' | 'error'
})

// ── Engine state ────────────────────────────────────────────────────────────
const engines = reactive([
  { key: 'elliott_wave',    name: 'Elliott Wave',    enabled: true, params: [] },
  { key: 'market_structure', name: 'Market Structure', enabled: true, params: [
      { key: 'ms_lookback', label: 'Swing Lookback', type: 'number', default: 5 },
  ]},
  { key: 'order_block', name: 'Order Block', enabled: true, params: [
      { key: 'ob_atr_period',   label: 'ATR Period',          type: 'number', default: 14  },
      { key: 'ob_impulse_mult', label: 'Impulse Multiplier',  type: 'number', default: 1.5 },
  ]},
  { key: 'fvg',          name: 'FVG',          enabled: true, params: [] },
  { key: 'smc',          name: 'SMC',          enabled: true, params: [] },
  { key: 'vwap',         name: 'VWAP',         enabled: true, params: [] },
  { key: 'price_action', name: 'Price Action', enabled: true, params: [] },
])
const engineValues  = reactive({})
const expandedEngine = ref(null)
const engineSaving   = ref(false)

// ── System state ────────────────────────────────────────────────────────────
const systemValues = reactive({
  fetch_interval:   '30',
  historical_depth: '3',
  telegram_webhook: '',
  horizon_workers:  '3',
})
const systemSaving = ref(false)

// ── Backup state ────────────────────────────────────────────────────────────
const backupValues = reactive({
  local_enabled:        false,
  local_retention_days: '7',
  r2_account_id:        '',
  r2_access_key:        '',
  r2_secret_key:        '',
  r2_bucket:            '',
  scope_candles:  true,
  scope_waves:    true,
  scope_settings: true,
  scope_trades:   true,
})
const backupSaving = ref(false)

// ────────────────────────────────────────────────────────────────────────────
onMounted(async () => {
  await store.fetchAll()
  loadValues()
  checkZerodhaRedirect()
  fetchZerodhaBalance()
})

function loadValues() {
  for (const ex of exchanges) {
    for (const f of ex.fields) {
      exchangeValues[f.key] = store.get(f.key, f.type === 'select' ? (f.options?.[0] || '') : '')
    }
  }
  for (const eng of engines) {
    eng.enabled = store.get(`engine_${eng.key}_enabled`, 'true') !== 'false'
    for (const p of eng.params) {
      engineValues[p.key] = store.get(p.key, String(p.default))
    }
  }
  systemValues.fetch_interval   = store.get('fetch_interval',   '30')
  systemValues.historical_depth = store.get('historical_depth', '3')
  systemValues.telegram_webhook = store.get('telegram_webhook', '')
  systemValues.horizon_workers  = store.get('horizon_workers',  '3')
  backupValues.local_enabled          = store.get('backup_local_enabled', 'false') === 'true'
  backupValues.local_retention_days   = store.get('backup_local_retention_days', '7')
  backupValues.r2_account_id          = store.get('r2_account_id', '')
  backupValues.r2_access_key          = store.get('r2_access_key', '')
  backupValues.r2_secret_key          = store.get('r2_secret_key', '')
  backupValues.r2_bucket              = store.get('r2_bucket', '')
}

// ── Exchange save / test ─────────────────────────────────────────────────────
async function saveExchange(ex) {
  ex.saving = true
  try {
    const settings = ex.fields.map(f => ({
      key:       f.key,
      value:     exchangeValues[f.key] || '',
      group:     'exchange',
      encrypted: f.encrypted,
    }))
    await axios.put('/api/v1/settings', { settings })
    store.showToast(`${ex.name} settings saved`, 'success')
    await store.fetchAll()
    loadValues()
  } catch (e) {
    store.showToast(e.response?.data?.message || 'Failed to save', 'error')
  } finally {
    ex.saving = false
  }
}

async function testExchange(ex) {
  ex.testing = true
  ex.status  = null
  const result = await store.testConnection(ex.id)
  ex.status  = result
  ex.testing = false
}

// ── Zerodha token flow ───────────────────────────────────────────────────────
async function openZerodhaLogin() {
  zerodha.tokenLoading = true
  zerodha.tokenMsg     = ''
  try {
    const { data } = await axios.get('/api/v1/settings/zerodha/login-url')
    if (data.success) {
      window.open(data.url, '_blank', 'width=800,height=600')
      zerodha.tokenMsg     = 'Zerodha login opened in a new window. After authorising, paste the request_token below.'
      zerodha.tokenMsgType = 'info'
      showManualTokenInput.value = true
    } else {
      zerodha.tokenMsg     = data.message
      zerodha.tokenMsgType = 'error'
    }
  } catch (e) {
    zerodha.tokenMsg     = e.response?.data?.message || 'Failed to get login URL'
    zerodha.tokenMsgType = 'error'
  } finally {
    zerodha.tokenLoading = false
  }
}

const showManualTokenInput = ref(false)
const manualRequestToken   = ref('')

async function exchangeToken(requestToken) {
  if (!requestToken) return
  zerodha.tokenExchanging = true
  zerodha.tokenMsg        = ''
  try {
    const { data } = await axios.post('/api/v1/settings/zerodha/exchange-token', {
      request_token: requestToken,
    })
    zerodha.tokenMsg     = data.message
    zerodha.tokenMsgType = data.success ? 'success' : 'error'
    if (data.success) {
      zerodha.userName         = data.user_name || ''
      showManualTokenInput.value = false
      manualRequestToken.value   = ''
      await fetchZerodhaBalance()
    }
  } catch (e) {
    zerodha.tokenMsg     = e.response?.data?.message || 'Token exchange failed'
    zerodha.tokenMsgType = 'error'
  } finally {
    zerodha.tokenExchanging = false
  }
}

async function fetchZerodhaBalance() {
  zerodha.balanceLoading = true
  try {
    const { data } = await axios.get('/api/v1/settings/zerodha/balance')
    zerodha.hasToken   = data.has_token   ?? false
    zerodha.expired    = data.expired     ?? false
    zerodha.equity     = data.equity      ?? null
    zerodha.commodity  = data.commodity   ?? null
    if (data.expired) {
      zerodha.tokenMsg     = 'Session expired. Please regenerate the token.'
      zerodha.tokenMsgType = 'error'
    }
  } catch {
    zerodha.hasToken = false
  } finally {
    zerodha.balanceLoading = false
  }
}

// Handle Zerodha OAuth callback result.
// New flow: Laravel /zerodha/callback exchanges token server-side then
//   redirects to frontend/settings?zerodha_status=success&user_name=...
// Fallback: request_token still in URL (shouldn't happen with callback URL configured)
function checkZerodhaRedirect() {
  const params        = new URLSearchParams(window.location.search)
  const zerodhaStatus = params.get('zerodha_status')   // 'success' | 'error'
  const message       = params.get('message')
  const userName      = params.get('user_name')
  const rt            = params.get('request_token')
  const status        = params.get('status')

  // Clean Zerodha params from URL without page reload
  if (zerodhaStatus || rt) {
    window.history.replaceState({}, '', window.location.pathname)
  }

  if (zerodhaStatus === 'success') {
    // Backend already exchanged the token — show success and load balance
    activeTab.value      = 'exchange'
    zerodha.userName     = userName || ''
    zerodha.tokenMsg     = `✓ Token generated successfully${userName ? ' for ' + userName : ''}. Session is active.`
    zerodha.tokenMsgType = 'success'
    fetchZerodhaBalance()
  } else if (zerodhaStatus === 'error') {
    activeTab.value      = 'exchange'
    zerodha.tokenMsg     = message || 'Token generation failed'
    zerodha.tokenMsgType = 'error'
  } else if (rt && status === 'success') {
    // Safety net: frontend received request_token directly
    activeTab.value = 'exchange'
    exchangeToken(rt)
  }
}

// Zerodha add-funds URL (Kite web)
const zerodhaFundsUrl = 'https://zerodha.com/portfolio/add-funds'

// ── Engine save ──────────────────────────────────────────────────────────────
async function saveEngines() {
  engineSaving.value = true
  try {
    const settings = []
    for (const eng of engines) {
      settings.push({ key: `engine_${eng.key}_enabled`, value: String(eng.enabled), group: 'engine', encrypted: false })
      for (const p of eng.params) {
        settings.push({ key: p.key, value: engineValues[p.key] || String(p.default), group: 'engine', encrypted: false })
      }
    }
    await axios.put('/api/v1/settings', { settings })
    store.showToast('Engine settings saved', 'success')
    await store.fetchAll()
  } catch (e) {
    store.showToast(e.response?.data?.message || 'Failed to save', 'error')
  } finally {
    engineSaving.value = false
  }
}

// ── System save ───────────────────────────────────────────────────────────────
async function saveSystem() {
  systemSaving.value = true
  try {
    await axios.put('/api/v1/settings', { settings: [
      { key: 'fetch_interval',   value: systemValues.fetch_interval,   group: 'system' },
      { key: 'historical_depth', value: systemValues.historical_depth, group: 'system' },
      { key: 'telegram_webhook', value: systemValues.telegram_webhook, group: 'system' },
      { key: 'horizon_workers',  value: systemValues.horizon_workers,  group: 'system' },
    ]})
    store.showToast('System settings saved', 'success')
    await store.fetchAll()
  } catch (e) {
    store.showToast(e.response?.data?.message || 'Failed to save', 'error')
  } finally {
    systemSaving.value = false
  }
}

// ── Backup save ───────────────────────────────────────────────────────────────
async function saveBackup() {
  backupSaving.value = true
  try {
    await axios.put('/api/v1/settings', { settings: [
      { key: 'backup_local_enabled',        value: String(backupValues.local_enabled),        group: 'backup' },
      { key: 'backup_local_retention_days', value: backupValues.local_retention_days,          group: 'backup' },
      { key: 'r2_account_id',               value: backupValues.r2_account_id,                 group: 'backup', encrypted: false },
      { key: 'r2_access_key',               value: backupValues.r2_access_key,                 group: 'backup', encrypted: true  },
      { key: 'r2_secret_key',               value: backupValues.r2_secret_key,                 group: 'backup', encrypted: true  },
      { key: 'r2_bucket',                   value: backupValues.r2_bucket,                     group: 'backup', encrypted: false },
    ]})
    store.showToast('Backup settings saved', 'success')
    await store.fetchAll()
  } catch (e) {
    store.showToast(e.response?.data?.message || 'Failed to save', 'error')
  } finally {
    backupSaving.value = false
  }
}
</script>

<template>
  <div class="p-4 max-w-4xl mx-auto">

    <!-- Toast -->
    <Teleport to="body">
      <div v-if="store.toast"
        class="fixed top-4 right-4 z-50 rounded-lg px-4 py-3 text-sm font-semibold shadow-lg transition-all"
        :style="store.toast.type === 'success'
          ? 'background: rgba(0,220,130,0.15); border: 1px solid rgba(0,220,130,0.4); color: var(--bull)'
          : 'background: rgba(255,59,92,0.15);  border: 1px solid rgba(255,59,92,0.4);  color: var(--bear)'">
        {{ store.toast.message }}
      </div>
    </Teleport>

    <h1 class="text-2xl font-bold" style="color: var(--text)">Settings</h1>
    <p class="mt-1 text-sm" style="color: var(--muted)">Configure exchange connections, engine parameters, backups, and system preferences.</p>

    <!-- Tabs -->
    <div class="mt-6 flex gap-1" style="border-bottom: 1px solid var(--border)">
      <button v-for="tab in tabs" :key="tab.id" @click="activeTab = tab.id"
        class="px-4 py-2.5 text-sm font-medium transition"
        :style="activeTab === tab.id
          ? 'border-bottom: 2px solid var(--accent); color: var(--text)'
          : 'color: var(--dim)'">
        {{ tab.label }}
      </button>
    </div>

    <div class="mt-6">

      <!-- ══════ EXCHANGE TAB ══════ -->
      <div v-if="activeTab === 'exchange'" class="space-y-4">
        <div v-for="ex in exchanges" :key="ex.id" class="rounded-xl p-5"
          style="background: var(--card); border: 1px solid var(--border)">

          <!-- Card header -->
          <div class="flex items-center justify-between mb-4">
            <div>
              <h3 class="font-semibold" style="color: var(--text)">{{ ex.name }}</h3>
              <p class="text-xs" style="color: var(--dim)">{{ ex.desc }}</p>
            </div>
            <span v-if="ex.status" class="rounded-full px-2.5 py-1 text-xs font-semibold"
              :style="ex.status.success
                ? 'background: rgba(0,220,130,0.1); color: var(--bull)'
                : 'background: rgba(255,59,92,0.1); color: var(--bear)'">
              {{ ex.status.success ? '● Connected' : '● Failed' }}
            </span>
            <span v-else class="rounded-full px-2.5 py-1 text-xs" style="background: var(--surface); color: var(--dim)">
              ○ Not Connected
            </span>
          </div>

          <!-- API key / secret fields (all exchanges) -->
          <div class="grid gap-3 md:grid-cols-2">
            <div v-for="f in ex.fields" :key="f.key">
              <label class="block text-[10px] mb-1 uppercase tracking-wider" style="color: var(--dim)">{{ f.label }}</label>
              <select v-if="f.type === 'select'" v-model="exchangeValues[f.key]"
                class="w-full rounded-md px-3 py-2 text-sm"
                style="background: var(--surface); border: 1px solid var(--border); color: var(--text)">
                <option v-for="opt in f.options" :key="opt" :value="opt">{{ opt }}</option>
              </select>
              <input v-else v-model="exchangeValues[f.key]" :type="f.type" :placeholder="f.label"
                class="w-full rounded-md px-3 py-2 text-sm"
                style="background: var(--surface); border: 1px solid var(--border); color: var(--text)" />
            </div>
          </div>

          <!-- ── Zerodha-specific: token + balance section ─────────────────── -->
          <template v-if="ex.id === 'zerodha'">
            <div class="mt-5 pt-4" style="border-top: 1px solid var(--border)">
              <div class="flex items-center gap-2 mb-3">
                <span class="text-xs font-semibold uppercase tracking-wider" style="color: var(--dim)">Access Token</span>
                <span v-if="zerodha.hasToken && !zerodha.expired"
                  class="rounded-full px-2 py-0.5 text-[10px] font-semibold"
                  style="background: rgba(0,220,130,0.12); color: var(--bull)">● Active</span>
                <span v-else-if="zerodha.expired"
                  class="rounded-full px-2 py-0.5 text-[10px] font-semibold"
                  style="background: rgba(255,59,92,0.12); color: var(--bear)">● Expired</span>
                <span v-else
                  class="rounded-full px-2 py-0.5 text-[10px] font-semibold"
                  style="background: var(--surface); color: var(--dim)">○ Not Generated</span>
              </div>

              <!-- Token message -->
              <div v-if="zerodha.tokenMsg" class="mb-3 rounded-md px-3 py-2 text-xs"
                :style="zerodha.tokenMsgType === 'success'
                  ? 'background: rgba(0,220,130,0.08); border: 1px solid rgba(0,220,130,0.25); color: var(--bull)'
                  : zerodha.tokenMsgType === 'error'
                  ? 'background: rgba(255,59,92,0.08); border: 1px solid rgba(255,59,92,0.25); color: var(--bear)'
                  : 'background: rgba(99,102,241,0.08); border: 1px solid rgba(99,102,241,0.25); color: #818cf8'">
                {{ zerodha.tokenMsg }}
              </div>

              <!-- Token buttons row -->
              <div class="flex flex-wrap gap-2 items-center">
                <button @click="openZerodhaLogin" :disabled="zerodha.tokenLoading"
                  class="flex items-center gap-1.5 rounded-md px-4 py-2 text-xs font-semibold"
                  style="background: linear-gradient(135deg,#6366f1,#4f46e5); color: #fff">
                  <span v-if="zerodha.tokenLoading">⏳</span>
                  <span v-else>🔑</span>
                  {{ zerodha.hasToken && !zerodha.expired ? 'Refresh Token' : 'Generate Token' }}
                </button>

                <button v-if="zerodha.hasToken && !zerodha.expired" @click="fetchZerodhaBalance"
                  :disabled="zerodha.balanceLoading"
                  class="flex items-center gap-1.5 rounded-md px-3 py-2 text-xs font-semibold"
                  style="background: var(--surface); border: 1px solid var(--border); color: var(--muted)">
                  {{ zerodha.balanceLoading ? '…' : '↻ Refresh Balance' }}
                </button>

                <a v-if="zerodha.hasToken && !zerodha.expired"
                  :href="zerodhaFundsUrl" target="_blank" rel="noopener"
                  class="flex items-center gap-1.5 rounded-md px-4 py-2 text-xs font-semibold"
                  style="background: rgba(0,220,130,0.12); border: 1px solid rgba(0,220,130,0.3); color: var(--bull); text-decoration: none">
                  💰 Add Funds
                </a>
              </div>

              <!-- Manual request-token input (shown after login window opens) -->
              <div v-if="showManualTokenInput" class="mt-3 flex gap-2">
                <input v-model="manualRequestToken" type="text"
                  placeholder="Paste request_token from redirect URL…"
                  class="flex-1 rounded-md px-3 py-2 text-xs"
                  style="background: var(--surface); border: 1px solid var(--border); color: var(--text)" />
                <button @click="exchangeToken(manualRequestToken)" :disabled="zerodha.tokenExchanging || !manualRequestToken"
                  class="rounded-md px-4 py-2 text-xs font-semibold"
                  style="background: var(--accent); color: #fff">
                  {{ zerodha.tokenExchanging ? '…' : 'Verify' }}
                </button>
              </div>

              <!-- Balance cards -->
              <div v-if="zerodha.hasToken && !zerodha.expired && (zerodha.equity || zerodha.commodity)"
                class="mt-4 grid grid-cols-2 gap-3">
                <div v-if="zerodha.equity" class="rounded-lg p-3"
                  style="background: var(--surface); border: 1px solid var(--border)">
                  <p class="text-[10px] uppercase tracking-wider mb-2" style="color: var(--dim)">Equity Segment</p>
                  <div class="flex justify-between items-end">
                    <div>
                      <p class="text-[10px]" style="color: var(--dim)">Available</p>
                      <p class="text-base font-bold" style="color: var(--bull)">₹ {{ zerodha.equity.available }}</p>
                    </div>
                    <div class="text-right">
                      <p class="text-[10px]" style="color: var(--dim)">Used</p>
                      <p class="text-sm font-semibold" style="color: var(--bear)">₹ {{ zerodha.equity.used }}</p>
                    </div>
                  </div>
                </div>
                <div v-if="zerodha.commodity" class="rounded-lg p-3"
                  style="background: var(--surface); border: 1px solid var(--border)">
                  <p class="text-[10px] uppercase tracking-wider mb-2" style="color: var(--dim)">Commodity Segment</p>
                  <div class="flex justify-between items-end">
                    <div>
                      <p class="text-[10px]" style="color: var(--dim)">Available</p>
                      <p class="text-base font-bold" style="color: var(--bull)">₹ {{ zerodha.commodity.available }}</p>
                    </div>
                    <div class="text-right">
                      <p class="text-[10px]" style="color: var(--dim)">Used</p>
                      <p class="text-sm font-semibold" style="color: var(--bear)">₹ {{ zerodha.commodity.used }}</p>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </template>
          <!-- ── end Zerodha section ─────────────────────────────────────── -->

          <!-- Action row -->
          <div class="flex gap-2 mt-4">
            <button @click="saveExchange(ex)" :disabled="ex.saving"
              class="rounded-md px-4 py-2 text-xs font-semibold"
              style="background: var(--accent); color: #fff">
              {{ ex.saving ? 'Saving…' : 'Save' }}
            </button>
            <button @click="testExchange(ex)" :disabled="ex.testing"
              class="rounded-md px-4 py-2 text-xs font-semibold"
              style="background: var(--surface); border: 1px solid var(--border); color: var(--muted)">
              {{ ex.testing ? 'Testing…' : 'Test Connection' }}
            </button>
          </div>
        </div>
      </div>

      <!-- ══════ ENGINE TAB ══════ -->
      <div v-else-if="activeTab === 'engine'">
        <div class="space-y-2">
          <div v-for="eng in engines" :key="eng.key" class="rounded-xl overflow-hidden"
            style="background: var(--card); border: 1px solid var(--border)">
            <div class="flex items-center gap-3 p-4 cursor-pointer"
              @click="eng.params.length ? (expandedEngine = expandedEngine === eng.key ? null : eng.key) : null">
              <div class="flex-1">
                <h3 class="font-medium text-sm" style="color: var(--text)">{{ eng.name }}</h3>
              </div>
              <button @click.stop="eng.enabled = !eng.enabled"
                class="relative w-10 h-5 rounded-full transition-colors"
                :style="eng.enabled ? 'background: var(--bull)' : 'background: var(--border)'">
                <span class="absolute top-0.5 w-4 h-4 rounded-full bg-white transition-transform"
                  :style="eng.enabled ? 'left: 22px' : 'left: 2px'"></span>
              </button>
              <span v-if="eng.params.length" class="text-[10px]" style="color: var(--dim)">
                {{ expandedEngine === eng.key ? '▲' : '▼' }}
              </span>
            </div>
            <div v-if="expandedEngine === eng.key && eng.params.length"
              class="px-4 pb-4 pt-0" style="border-top: 1px solid var(--border)">
              <div class="grid gap-3 md:grid-cols-2 mt-3">
                <div v-for="p in eng.params" :key="p.key">
                  <label class="block text-[10px] mb-1 uppercase tracking-wider" style="color: var(--dim)">{{ p.label }}</label>
                  <input v-model="engineValues[p.key]" :type="p.type" :placeholder="String(p.default)"
                    class="w-full rounded-md px-3 py-2 text-sm"
                    style="background: var(--surface); border: 1px solid var(--border); color: var(--text)" />
                </div>
              </div>
            </div>
          </div>
        </div>
        <button @click="saveEngines" :disabled="engineSaving"
          class="mt-4 rounded-md px-4 py-2 text-xs font-semibold"
          style="background: var(--accent); color: #fff">
          {{ engineSaving ? 'Saving…' : 'Save Engine Settings' }}
        </button>
      </div>

      <!-- ══════ SYSTEM TAB ══════ -->
      <div v-else-if="activeTab === 'system'" class="space-y-6">
        <div class="rounded-xl p-5" style="background: var(--card); border: 1px solid var(--border)">
          <h3 class="text-sm font-semibold mb-4" style="color: var(--text)">Data Pipeline</h3>
          <div class="grid gap-4 md:grid-cols-3">
            <div>
              <label class="block text-[10px] mb-1 uppercase tracking-wider" style="color: var(--dim)">Fetch Interval (seconds)</label>
              <input v-model="systemValues.fetch_interval" type="number" min="10" max="300"
                class="w-full rounded-md px-3 py-2 text-sm"
                style="background: var(--surface); border: 1px solid var(--border); color: var(--text)" />
            </div>
            <div>
              <label class="block text-[10px] mb-1 uppercase tracking-wider" style="color: var(--dim)">Historical Depth (months)</label>
              <input v-model="systemValues.historical_depth" type="number" min="1" max="12"
                class="w-full rounded-md px-3 py-2 text-sm"
                style="background: var(--surface); border: 1px solid var(--border); color: var(--text)" />
            </div>
            <div>
              <label class="block text-[10px] mb-1 uppercase tracking-wider" style="color: var(--dim)">Horizon Workers</label>
              <input v-model="systemValues.horizon_workers" type="number" min="1" max="10"
                class="w-full rounded-md px-3 py-2 text-sm"
                style="background: var(--surface); border: 1px solid var(--border); color: var(--text)" />
            </div>
          </div>
          <div class="mt-4">
            <label class="block text-[10px] mb-1 uppercase tracking-wider" style="color: var(--dim)">Telegram Webhook URL</label>
            <input v-model="systemValues.telegram_webhook" type="url" placeholder="https://api.telegram.org/bot…"
              class="w-full rounded-md px-3 py-2 text-sm"
              style="background: var(--surface); border: 1px solid var(--border); color: var(--text)" />
          </div>
          <button @click="saveSystem" :disabled="systemSaving"
            class="mt-4 rounded-md px-4 py-2 text-xs font-semibold"
            style="background: var(--accent); color: #fff">
            {{ systemSaving ? 'Saving…' : 'Save System Settings' }}
          </button>
        </div>

        <!-- Symbol Manager -->
        <div class="rounded-xl p-5" style="background: var(--card); border: 1px solid var(--border)">
          <SymbolManager />
        </div>
      </div>

      <!-- ══════ BACKUP TAB ══════ -->
      <div v-else-if="activeTab === 'backup'" class="space-y-4">
        <!-- Local backup -->
        <div class="rounded-xl p-5" style="background: var(--card); border: 1px solid var(--border)">
          <div class="flex items-center justify-between mb-4">
            <h3 class="text-sm font-semibold" style="color: var(--text)">Local Backup</h3>
            <button @click="backupValues.local_enabled = !backupValues.local_enabled"
              class="relative w-10 h-5 rounded-full transition-colors"
              :style="backupValues.local_enabled ? 'background: var(--bull)' : 'background: var(--border)'">
              <span class="absolute top-0.5 w-4 h-4 rounded-full bg-white transition-transform"
                :style="backupValues.local_enabled ? 'left: 22px' : 'left: 2px'"></span>
            </button>
          </div>
          <div>
            <label class="block text-[10px] mb-1 uppercase tracking-wider" style="color: var(--dim)">Retention (days)</label>
            <input v-model="backupValues.local_retention_days" type="number" min="1" max="90"
              class="w-48 rounded-md px-3 py-2 text-sm"
              style="background: var(--surface); border: 1px solid var(--border); color: var(--text)" />
          </div>
        </div>

        <!-- Cloudflare R2 -->
        <div class="rounded-xl p-5" style="background: var(--card); border: 1px solid var(--border)">
          <h3 class="text-sm font-semibold mb-4" style="color: var(--text)">Cloudflare R2 Storage</h3>
          <div class="grid gap-3 md:grid-cols-2">
            <div>
              <label class="block text-[10px] mb-1 uppercase tracking-wider" style="color: var(--dim)">Account ID</label>
              <input v-model="backupValues.r2_account_id" type="text" placeholder="Account ID"
                class="w-full rounded-md px-3 py-2 text-sm"
                style="background: var(--surface); border: 1px solid var(--border); color: var(--text)" />
            </div>
            <div>
              <label class="block text-[10px] mb-1 uppercase tracking-wider" style="color: var(--dim)">Bucket Name</label>
              <input v-model="backupValues.r2_bucket" type="text" placeholder="wavetrader-backups"
                class="w-full rounded-md px-3 py-2 text-sm"
                style="background: var(--surface); border: 1px solid var(--border); color: var(--text)" />
            </div>
            <div>
              <label class="block text-[10px] mb-1 uppercase tracking-wider" style="color: var(--dim)">Access Key</label>
              <input v-model="backupValues.r2_access_key" type="password" placeholder="••••••••"
                class="w-full rounded-md px-3 py-2 text-sm"
                style="background: var(--surface); border: 1px solid var(--border); color: var(--text)" />
            </div>
            <div>
              <label class="block text-[10px] mb-1 uppercase tracking-wider" style="color: var(--dim)">Secret Key</label>
              <input v-model="backupValues.r2_secret_key" type="password" placeholder="••••••••"
                class="w-full rounded-md px-3 py-2 text-sm"
                style="background: var(--surface); border: 1px solid var(--border); color: var(--text)" />
            </div>
          </div>

          <!-- Backup scope -->
          <div class="mt-4">
            <label class="block text-[10px] mb-2 uppercase tracking-wider" style="color: var(--dim)">Backup Scope</label>
            <div class="flex gap-4 flex-wrap">
              <label v-for="scope in ['candles', 'waves', 'settings', 'trades']" :key="scope"
                class="flex items-center gap-2 text-xs cursor-pointer" style="color: var(--muted)">
                <input type="checkbox" v-model="backupValues[`scope_${scope}`]"
                  class="rounded" style="accent-color: var(--accent)" />
                {{ scope.charAt(0).toUpperCase() + scope.slice(1) }}
              </label>
            </div>
          </div>
        </div>

        <button @click="saveBackup" :disabled="backupSaving"
          class="rounded-md px-4 py-2 text-xs font-semibold"
          style="background: var(--accent); color: #fff">
          {{ backupSaving ? 'Saving…' : 'Save Backup Settings' }}
        </button>
      </div>

    </div>
  </div>
</template>
