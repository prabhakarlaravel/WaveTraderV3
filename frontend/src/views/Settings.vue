<script setup>
import { ref, onMounted, reactive, computed } from 'vue'
import { useRoute } from 'vue-router'
import { useSettingsStore } from '../stores/useSettingsStore'
import SymbolManager from '../components/settings/SymbolManager.vue'
import axios from 'axios'

const store     = useSettingsStore()
const route     = useRoute()
const activeTab = ref('exchange')

const tabs = [
  { id: 'exchange', label: 'Exchanges',  icon: '⇋', desc: 'API connections' },
  { id: 'engine',   label: 'Engines',    icon: '⚙', desc: 'Analysis modules' },
  { id: 'system',   label: 'System',     icon: '◎', desc: 'Pipeline & symbols' },
  { id: 'backup',   label: 'Backup',     icon: '⛁', desc: 'Storage & recovery' },
]

// ── Exchange state ──────────────────────────────────────────────────────────
const exchanges = reactive([
  {
    id: 'binance', name: 'Binance', desc: 'Crypto Spot & Futures', market: 'Crypto',
    accent: '#F0B90B', logo: '₿',
    fields: [
      { key: 'binance_api_key',    label: 'API Key',    type: 'password', encrypted: true },
      { key: 'binance_api_secret', label: 'API Secret', type: 'password', encrypted: true },
    ],
    saving: false, testing: false, status: null,
  },
  {
    id: 'zerodha', name: 'Zerodha', desc: 'NSE · BSE · MCX — KiteConnect v3', market: 'India',
    accent: '#387ED1', logo: 'Z',
    fields: [
      { key: 'zerodha_api_key',    label: 'API Key',    type: 'password', encrypted: true },
      { key: 'zerodha_api_secret', label: 'API Secret', type: 'password', encrypted: true },
    ],
    saving: false, testing: false, status: null,
  },
  {
    id: 'oanda', name: 'OANDA', desc: 'Forex — 50+ currency pairs', market: 'Forex',
    accent: '#2FB87E', logo: 'Ø',
    fields: [
      { key: 'oanda_account_id',   label: 'Account ID',   type: 'text',     encrypted: false },
      { key: 'oanda_bearer_token', label: 'Bearer Token',  type: 'password', encrypted: true  },
      { key: 'oanda_mode',         label: 'Mode',          type: 'select',   options: ['practice', 'live'], encrypted: false },
    ],
    saving: false, testing: false, status: null,
  },
])
const exchangeValues = reactive({})
const showPw = reactive({}) // per-field show/hide password

// ── Zerodha-specific state ──────────────────────────────────────────────────
const zerodha = reactive({
  tokenLoading: false, tokenExchanging: false, balanceLoading: false,
  hasToken: false, expired: false, userName: '',
  equity: null, commodity: null, tokenMsg: '', tokenMsgType: 'success',
})

// ── Engine state ────────────────────────────────────────────────────────────
const engineMeta = {
  elliott_wave:     { icon: '〰', color: '#818cf8', desc: 'Wave labeling · Fibonacci targets · HTF derivation' },
  market_structure: { icon: '⧫', color: '#38bdf8', desc: 'BOS / CHOCH · Swing detection · Trend alignment' },
  order_block:      { icon: '▬', color: '#f472b6', desc: 'Bullish / Bearish OBs · Mitigation tracking' },
  fvg:              { icon: '▤', color: '#22d3ee', desc: '3-candle imbalance · Fill tracking · Inverse FVG' },
  smc:              { icon: '◈', color: '#a78bfa', desc: 'Premium / Discount · Liquidity pools · OTE zone' },
  vwap:             { icon: '≋', color: '#fbbf24', desc: 'Multi-session VWAP · Sigma bands · Anchored' },
  price_action:     { icon: '⌇', color: '#f87171', desc: 'Candlestick patterns · S/R · Supply / Demand' },
}
const engines = reactive([
  { key: 'elliott_wave',     name: 'Elliott Wave',     enabled: true, params: [] },
  { key: 'market_structure', name: 'Market Structure',  enabled: true, params: [
      { key: 'ms_lookback', label: 'Swing Lookback', type: 'number', default: 5 },
  ]},
  { key: 'order_block', name: 'Order Block', enabled: true, params: [
      { key: 'ob_atr_period',   label: 'ATR Period',         type: 'number', default: 14  },
      { key: 'ob_impulse_mult', label: 'Impulse Multiplier', type: 'number', default: 1.5 },
  ]},
  { key: 'fvg',          name: 'FVG',          enabled: true, params: [] },
  { key: 'smc',          name: 'SMC',          enabled: true, params: [] },
  { key: 'vwap',         name: 'VWAP',         enabled: true, params: [] },
  { key: 'price_action', name: 'Price Action', enabled: true, params: [] },
])
const engineValues   = reactive({})
const expandedEngine = ref(null)
const engineSaving   = ref(false)

// ── System state ────────────────────────────────────────────────────────────
const systemValues = reactive({
  fetch_interval: '30', historical_depth: '3', telegram_webhook: '', horizon_workers: '3',
})
const systemSaving = ref(false)

// ── Backup state ────────────────────────────────────────────────────────────
const backupValues = reactive({
  local_enabled: false, local_retention_days: '7',
  r2_account_id: '', r2_access_key: '', r2_secret_key: '', r2_bucket: '',
  scope_candles: true, scope_waves: true, scope_settings: true, scope_trades: true,
})
const backupSaving = ref(false)

const showManualTokenInput = ref(false)
const manualRequestToken   = ref('')

// ────────────────────────────────────────────────────────────────────────────
onMounted(async () => {
  await store.fetchAll()
  loadValues()
  checkZerodhaRedirect()
  fetchZerodhaBalance()
  autoCheckConnections()
})

// Auto-check connection status for exchanges that have credentials saved
async function autoCheckConnections() {
  for (const ex of exchanges) {
    const hasCredentials = ex.fields.some(f => {
      const val = exchangeValues[f.key]
      return val && val.length > 0 && val !== f.label
    })
    if (hasCredentials) {
      // Test in background — don't block UI
      store.testConnection(ex.id).then(result => {
        ex.status = result
      }).catch(() => {
        // Silent fail — stays "Offline"
      })
    }
  }
}

function loadValues() {
  for (const ex of exchanges) {
    for (const f of ex.fields) {
      exchangeValues[f.key] = store.get(f.key, f.type === 'select' ? (f.options?.[0] || '') : '')
    }
  }
  for (const eng of engines) {
    eng.enabled = store.get(`engine_${eng.key}_enabled`, 'true') !== 'false'
    for (const p of eng.params) engineValues[p.key] = store.get(p.key, String(p.default))
  }
  systemValues.fetch_interval   = store.get('fetch_interval',   '30')
  systemValues.historical_depth = store.get('historical_depth', '3')
  systemValues.telegram_webhook = store.get('telegram_webhook', '')
  systemValues.horizon_workers  = store.get('horizon_workers',  '3')
  backupValues.local_enabled        = store.get('backup_local_enabled', 'false') === 'true'
  backupValues.local_retention_days = store.get('backup_local_retention_days', '7')
  backupValues.r2_account_id  = store.get('r2_account_id', '')
  backupValues.r2_access_key  = store.get('r2_access_key', '')
  backupValues.r2_secret_key  = store.get('r2_secret_key', '')
  backupValues.r2_bucket      = store.get('r2_bucket', '')
}

// ── Exchange save / test ─────────────────────────────────────────────────
async function saveExchange(ex) {
  ex.saving = true
  try {
    const settings = ex.fields.map(f => ({
      key: f.key, value: exchangeValues[f.key] || '', group: 'exchange', encrypted: f.encrypted,
    }))
    await axios.put('/api/v1/settings', { settings })
    store.showToast(`${ex.name} settings saved`, 'success')
    await store.fetchAll(); loadValues()
  } catch (e) {
    store.showToast(e.response?.data?.message || 'Failed to save', 'error')
  } finally { ex.saving = false }
}

async function testExchange(ex) {
  ex.testing = true; ex.status = null
  ex.status = await store.testConnection(ex.id)
  ex.testing = false
}

// ── Zerodha token flow ──────────────────────────────────────────────────
async function openZerodhaLogin() {
  zerodha.tokenLoading = true; zerodha.tokenMsg = ''
  try {
    const { data } = await axios.get('/api/v1/settings/zerodha/login-url')
    if (data.success) { window.location.href = data.url }
    else { zerodha.tokenMsg = data.message; zerodha.tokenMsgType = 'error' }
  } catch (e) {
    zerodha.tokenMsg = e.response?.data?.message || 'Failed to get login URL'
    zerodha.tokenMsgType = 'error'
  } finally { zerodha.tokenLoading = false }
}

async function exchangeToken(requestToken) {
  if (!requestToken) return
  zerodha.tokenExchanging = true; zerodha.tokenMsg = ''
  try {
    const { data } = await axios.post('/api/v1/settings/zerodha/exchange-token', { request_token: requestToken })
    zerodha.tokenMsg = data.message; zerodha.tokenMsgType = data.success ? 'success' : 'error'
    if (data.success) {
      zerodha.userName = data.user_name || ''
      showManualTokenInput.value = false; manualRequestToken.value = ''
      await fetchZerodhaBalance()
    }
  } catch (e) {
    zerodha.tokenMsg = e.response?.data?.message || 'Token exchange failed'; zerodha.tokenMsgType = 'error'
  } finally { zerodha.tokenExchanging = false }
}

async function fetchZerodhaBalance() {
  zerodha.balanceLoading = true
  try {
    const { data } = await axios.get('/api/v1/settings/zerodha/balance')
    zerodha.hasToken = data.has_token ?? false; zerodha.expired = data.expired ?? false
    zerodha.equity = data.equity ?? null; zerodha.commodity = data.commodity ?? null
    // Set Zerodha exchange card status based on balance result
    const zrEx = exchanges.find(e => e.id === 'zerodha')
    if (zrEx) {
      if (data.success) {
        zrEx.status = { success: true, message: 'Connected' }
      } else if (data.expired) {
        zrEx.status = { success: false, message: 'Token expired' }
        zerodha.tokenMsg = 'Session expired — regenerate token.'; zerodha.tokenMsgType = 'error'
      }
    }
  } catch { zerodha.hasToken = false }
  finally { zerodha.balanceLoading = false }
}

function checkZerodhaRedirect() {
  const params = new URLSearchParams(window.location.search)
  const zerodhaStatus = params.get('zerodha_status'), message = params.get('message')
  const userName = params.get('user_name'), rt = params.get('request_token'), status = params.get('status')
  if (zerodhaStatus || rt) window.history.replaceState({}, '', window.location.pathname)
  if (zerodhaStatus === 'success') {
    activeTab.value = 'exchange'; zerodha.userName = userName || ''
    zerodha.tokenMsg = `Token generated${userName ? ' for ' + userName : ''}. Session active.`
    zerodha.tokenMsgType = 'success'; fetchZerodhaBalance()
  } else if (zerodhaStatus === 'error') {
    activeTab.value = 'exchange'; zerodha.tokenMsg = message || 'Token generation failed'; zerodha.tokenMsgType = 'error'
  } else if (rt && status === 'success') { activeTab.value = 'exchange'; exchangeToken(rt) }
}

const zerodhaFundsUrl = 'https://zerodha.com/portfolio/add-funds'

// ── Engine / System / Backup save ─────────────────────────────────────────
async function saveEngines() {
  engineSaving.value = true
  try {
    const s = []; for (const eng of engines) {
      s.push({ key: `engine_${eng.key}_enabled`, value: String(eng.enabled), group: 'engine', encrypted: false })
      for (const p of eng.params) s.push({ key: p.key, value: engineValues[p.key] || String(p.default), group: 'engine', encrypted: false })
    }
    await axios.put('/api/v1/settings', { settings: s }); store.showToast('Engine settings saved', 'success'); await store.fetchAll()
  } catch (e) { store.showToast(e.response?.data?.message || 'Failed', 'error') }
  finally { engineSaving.value = false }
}

async function saveSystem() {
  systemSaving.value = true
  try {
    await axios.put('/api/v1/settings', { settings: [
      { key: 'fetch_interval', value: systemValues.fetch_interval, group: 'system' },
      { key: 'historical_depth', value: systemValues.historical_depth, group: 'system' },
      { key: 'telegram_webhook', value: systemValues.telegram_webhook, group: 'system' },
      { key: 'horizon_workers', value: systemValues.horizon_workers, group: 'system' },
    ]}); store.showToast('System settings saved', 'success'); await store.fetchAll()
  } catch (e) { store.showToast(e.response?.data?.message || 'Failed', 'error') }
  finally { systemSaving.value = false }
}

async function saveBackup() {
  backupSaving.value = true
  try {
    await axios.put('/api/v1/settings', { settings: [
      { key: 'backup_local_enabled', value: String(backupValues.local_enabled), group: 'backup' },
      { key: 'backup_local_retention_days', value: backupValues.local_retention_days, group: 'backup' },
      { key: 'r2_account_id', value: backupValues.r2_account_id, group: 'backup', encrypted: false },
      { key: 'r2_access_key', value: backupValues.r2_access_key, group: 'backup', encrypted: true },
      { key: 'r2_secret_key', value: backupValues.r2_secret_key, group: 'backup', encrypted: true },
      { key: 'r2_bucket', value: backupValues.r2_bucket, group: 'backup', encrypted: false },
    ]}); store.showToast('Backup settings saved', 'success'); await store.fetchAll()
  } catch (e) { store.showToast(e.response?.data?.message || 'Failed', 'error') }
  finally { backupSaving.value = false }
}

function togglePw(key) { showPw[key] = !showPw[key] }
</script>

<template>
  <div class="settings-root">

    <!-- ══════════════════════ TOAST ══════════════════════ -->
    <Teleport to="body">
      <transition name="toast">
        <div v-if="store.toast" class="toast-bar"
          :class="store.toast.type === 'success' ? 'toast-ok' : 'toast-err'">
          <span class="toast-dot" :class="store.toast.type === 'success' ? 'dot-ok' : 'dot-err'" />
          {{ store.toast.message }}
        </div>
      </transition>
    </Teleport>

    <!-- ══════════════════════ SIDEBAR ══════════════════════ -->
    <aside class="sidebar">
      <div class="sidebar-hdr">
        <div class="sidebar-title">Settings</div>
        <div class="sidebar-sub">Platform Configuration</div>
      </div>
      <nav class="sidebar-nav">
        <button v-for="t in tabs" :key="t.id" @click="activeTab = t.id"
          class="nav-btn" :class="{ active: activeTab === t.id }">
          <span class="nav-icon">{{ t.icon }}</span>
          <span class="nav-text">
            <span class="nav-label">{{ t.label }}</span>
            <span class="nav-desc">{{ t.desc }}</span>
          </span>
          <span v-if="activeTab === t.id" class="nav-arrow">›</span>
        </button>
      </nav>
      <div class="sidebar-footer">
        WaveTrader v3
      </div>
    </aside>

    <!-- ══════════════════════ MAIN CONTENT ══════════════════════ -->
    <main class="settings-main">

      <!-- ───────── EXCHANGE TAB ───────── -->
      <template v-if="activeTab === 'exchange'">
        <div class="page-hdr">
          <h2 class="page-title">Exchange Connections</h2>
          <p class="page-desc">Configure API credentials for each market data source. All secrets are AES-256 encrypted at rest.</p>
        </div>

        <div class="ex-grid">
          <div v-for="ex in exchanges" :key="ex.id" class="ex-card">
            <!-- accent bar -->
            <div class="ex-accent" :style="{ background: ex.accent }" />

            <!-- header -->
            <div class="ex-head">
              <div class="ex-logo" :style="{ background: ex.accent + '18', color: ex.accent, borderColor: ex.accent + '30' }">
                {{ ex.logo }}
              </div>
              <div class="ex-info">
                <div class="ex-name">{{ ex.name }}</div>
                <div class="ex-desc-text">{{ ex.desc }}</div>
              </div>
              <div class="ex-badge-area">
                <span class="ex-market">{{ ex.market }}</span>
                <span v-if="ex.status?.success" class="ex-status st-ok"><span class="pulse-dot dot-green" /> Connected</span>
                <span v-else-if="ex.status && !ex.status.success" class="ex-status st-err">Failed</span>
                <span v-else class="ex-status st-off">Offline</span>
              </div>
            </div>

            <!-- fields -->
            <div class="ex-fields">
              <div v-for="f in ex.fields" :key="f.key" class="field-group">
                <label class="field-label">{{ f.label }}</label>
                <div class="field-wrap">
                  <select v-if="f.type === 'select'" v-model="exchangeValues[f.key]" class="field-input">
                    <option v-for="opt in f.options" :key="opt" :value="opt">{{ opt }}</option>
                  </select>
                  <template v-else>
                    <input v-model="exchangeValues[f.key]"
                      :type="f.type === 'password' && !showPw[f.key] ? 'password' : 'text'"
                      :placeholder="f.label" class="field-input" />
                    <button v-if="f.type === 'password'" @click="togglePw(f.key)" class="pw-toggle">
                      {{ showPw[f.key] ? '◉' : '○' }}
                    </button>
                  </template>
                </div>
              </div>
            </div>

            <!-- ── Zerodha Token Section ── -->
            <template v-if="ex.id === 'zerodha'">
              <div class="zr-section">
                <div class="zr-row">
                  <span class="zr-label">Session Token</span>
                  <span v-if="zerodha.hasToken && !zerodha.expired" class="zr-badge zr-active">
                    <span class="pulse-dot dot-green" /> Active{{ zerodha.userName ? ' · ' + zerodha.userName : '' }}
                  </span>
                  <span v-else-if="zerodha.expired" class="zr-badge zr-expired">Expired</span>
                  <span v-else class="zr-badge zr-none">No Token</span>
                </div>

                <div v-if="zerodha.tokenMsg" class="zr-msg"
                  :class="zerodha.tokenMsgType === 'success' ? 'msg-ok' : zerodha.tokenMsgType === 'error' ? 'msg-err' : 'msg-info'">
                  {{ zerodha.tokenMsg }}
                </div>

                <div class="zr-actions">
                  <button @click="openZerodhaLogin" :disabled="zerodha.tokenLoading" class="btn btn-primary btn-sm">
                    {{ zerodha.hasToken && !zerodha.expired ? '↻ Refresh Token' : '⚡ Generate Token' }}
                  </button>
                  <button v-if="zerodha.hasToken && !zerodha.expired" @click="fetchZerodhaBalance"
                    :disabled="zerodha.balanceLoading" class="btn btn-ghost btn-sm">
                    {{ zerodha.balanceLoading ? '...' : '↻ Refresh Balance' }}
                  </button>
                  <a v-if="zerodha.hasToken && !zerodha.expired"
                    :href="zerodhaFundsUrl" target="_blank" rel="noopener" class="btn btn-funds btn-sm">
                    ₹ Add Funds
                  </a>
                </div>

                <!-- Manual fallback -->
                <div v-if="showManualTokenInput" class="zr-manual">
                  <input v-model="manualRequestToken" type="text" placeholder="Paste request_token…" class="field-input" />
                  <button @click="exchangeToken(manualRequestToken)"
                    :disabled="zerodha.tokenExchanging || !manualRequestToken" class="btn btn-primary btn-sm">Verify</button>
                </div>

                <!-- Balance -->
                <div v-if="zerodha.hasToken && !zerodha.expired && (zerodha.equity || zerodha.commodity)" class="zr-balance">
                  <div v-if="zerodha.equity" class="bal-card">
                    <div class="bal-head">
                      <span class="bal-title">Equity</span>
                    </div>
                    <div class="bal-row">
                      <div><span class="bal-sub">Available</span><span class="bal-val bal-green">₹{{ zerodha.equity.available }}</span></div>
                      <div class="text-right"><span class="bal-sub">Used</span><span class="bal-val bal-red">₹{{ zerodha.equity.used }}</span></div>
                    </div>
                  </div>
                  <div v-if="zerodha.commodity" class="bal-card">
                    <div class="bal-head">
                      <span class="bal-title">Commodity</span>
                    </div>
                    <div class="bal-row">
                      <div><span class="bal-sub">Available</span><span class="bal-val bal-green">₹{{ zerodha.commodity.available }}</span></div>
                      <div class="text-right"><span class="bal-sub">Used</span><span class="bal-val bal-red">₹{{ zerodha.commodity.used }}</span></div>
                    </div>
                  </div>
                </div>
              </div>
            </template>

            <!-- action row -->
            <div class="ex-actions">
              <button @click="saveExchange(ex)" :disabled="ex.saving" class="btn btn-save">
                <span v-if="ex.saving" class="spin">↻</span>
                {{ ex.saving ? 'Saving' : 'Save Credentials' }}
              </button>
              <button @click="testExchange(ex)" :disabled="ex.testing" class="btn btn-ghost">
                {{ ex.testing ? 'Testing…' : 'Test Connection' }}
              </button>
            </div>
          </div>
        </div>
      </template>

      <!-- ───────── ENGINES TAB ───────── -->
      <template v-if="activeTab === 'engine'">
        <div class="page-hdr">
          <h2 class="page-title">Analysis Engines</h2>
          <p class="page-desc">Toggle engines on/off and tune their parameters. Changes apply to the next engine run cycle.</p>
        </div>

        <div class="eng-grid">
          <div v-for="eng in engines" :key="eng.key" class="eng-card"
            :class="{ 'eng-off': !eng.enabled }">
            <div class="eng-accent" :style="{ background: eng.enabled ? engineMeta[eng.key]?.color : '#333' }" />
            <div class="eng-head" @click="eng.params.length ? (expandedEngine = expandedEngine === eng.key ? null : eng.key) : null">
              <span class="eng-icon" :style="{ color: engineMeta[eng.key]?.color }">{{ engineMeta[eng.key]?.icon }}</span>
              <div class="eng-info">
                <div class="eng-name">{{ eng.name }}</div>
                <div class="eng-desc">{{ engineMeta[eng.key]?.desc }}</div>
              </div>
              <button @click.stop="eng.enabled = !eng.enabled" class="toggle-btn" :class="{ 'toggle-on': eng.enabled }">
                <span class="toggle-thumb" />
              </button>
            </div>
            <div v-if="expandedEngine === eng.key && eng.params.length" class="eng-params">
              <div v-for="p in eng.params" :key="p.key" class="field-group">
                <label class="field-label">{{ p.label }}</label>
                <input v-model="engineValues[p.key]" :type="p.type" :placeholder="String(p.default)" class="field-input" />
              </div>
            </div>
          </div>
        </div>
        <div class="save-row">
          <button @click="saveEngines" :disabled="engineSaving" class="btn btn-save">
            {{ engineSaving ? 'Saving…' : 'Save All Engine Settings' }}
          </button>
        </div>
      </template>

      <!-- ───────── SYSTEM TAB ───────── -->
      <template v-if="activeTab === 'system'">
        <div class="page-hdr">
          <h2 class="page-title">System Configuration</h2>
          <p class="page-desc">Data pipeline settings, worker configuration, and tracked symbols.</p>
        </div>

        <div class="sys-card">
          <h3 class="section-title">Data Pipeline</h3>
          <div class="sys-grid">
            <div class="field-group">
              <label class="field-label">Fetch Interval <span class="field-unit">seconds</span></label>
              <input v-model="systemValues.fetch_interval" type="number" min="10" max="300" class="field-input" />
            </div>
            <div class="field-group">
              <label class="field-label">Historical Depth <span class="field-unit">months</span></label>
              <input v-model="systemValues.historical_depth" type="number" min="1" max="12" class="field-input" />
            </div>
            <div class="field-group">
              <label class="field-label">Horizon Workers <span class="field-unit">processes</span></label>
              <input v-model="systemValues.horizon_workers" type="number" min="1" max="10" class="field-input" />
            </div>
          </div>
          <div class="field-group mt-4">
            <label class="field-label">Telegram Webhook URL</label>
            <input v-model="systemValues.telegram_webhook" type="url" placeholder="https://api.telegram.org/bot…" class="field-input" />
          </div>
          <div class="save-row">
            <button @click="saveSystem" :disabled="systemSaving" class="btn btn-save">
              {{ systemSaving ? 'Saving…' : 'Save System Settings' }}
            </button>
          </div>
        </div>

        <div class="sys-card">
          <h3 class="section-title">Symbol Management</h3>
          <SymbolManager />
        </div>
      </template>

      <!-- ───────── BACKUP TAB ───────── -->
      <template v-if="activeTab === 'backup'">
        <div class="page-hdr">
          <h2 class="page-title">Backup & Recovery</h2>
          <p class="page-desc">Configure local and cloud backup for candles, waves, trades, and settings.</p>
        </div>

        <div class="bk-grid">
          <!-- Local -->
          <div class="sys-card">
            <div class="bk-head">
              <div>
                <h3 class="section-title" style="margin:0">Local Backup</h3>
                <p class="bk-sub">Stored on the server filesystem</p>
              </div>
              <button @click="backupValues.local_enabled = !backupValues.local_enabled"
                class="toggle-btn" :class="{ 'toggle-on': backupValues.local_enabled }">
                <span class="toggle-thumb" />
              </button>
            </div>
            <div class="field-group mt-3">
              <label class="field-label">Retention Period <span class="field-unit">days</span></label>
              <input v-model="backupValues.local_retention_days" type="number" min="1" max="90"
                class="field-input" style="max-width: 140px" />
            </div>
          </div>

          <!-- R2 -->
          <div class="sys-card">
            <h3 class="section-title">Cloudflare R2 <span class="bk-tag">S3-compatible</span></h3>
            <div class="sys-grid cols-2">
              <div class="field-group">
                <label class="field-label">Account ID</label>
                <input v-model="backupValues.r2_account_id" type="text" placeholder="Account ID" class="field-input" />
              </div>
              <div class="field-group">
                <label class="field-label">Bucket Name</label>
                <input v-model="backupValues.r2_bucket" type="text" placeholder="wavetrader-backups" class="field-input" />
              </div>
              <div class="field-group">
                <label class="field-label">Access Key</label>
                <div class="field-wrap">
                  <input v-model="backupValues.r2_access_key"
                    :type="showPw.r2ak ? 'text' : 'password'" class="field-input" placeholder="••••••" />
                  <button @click="togglePw('r2ak')" class="pw-toggle">{{ showPw.r2ak ? '◉' : '○' }}</button>
                </div>
              </div>
              <div class="field-group">
                <label class="field-label">Secret Key</label>
                <div class="field-wrap">
                  <input v-model="backupValues.r2_secret_key"
                    :type="showPw.r2sk ? 'text' : 'password'" class="field-input" placeholder="••••••" />
                  <button @click="togglePw('r2sk')" class="pw-toggle">{{ showPw.r2sk ? '◉' : '○' }}</button>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Scope -->
        <div class="sys-card">
          <h3 class="section-title">Backup Scope</h3>
          <div class="scope-row">
            <label v-for="scope in ['candles','waves','settings','trades']" :key="scope" class="scope-chip"
              :class="{ 'scope-on': backupValues['scope_'+scope] }">
              <input type="checkbox" v-model="backupValues['scope_'+scope]" class="sr-only" />
              <span class="scope-check">{{ backupValues['scope_'+scope] ? '✓' : '' }}</span>
              {{ scope.charAt(0).toUpperCase() + scope.slice(1) }}
            </label>
          </div>
        </div>

        <div class="save-row">
          <button @click="saveBackup" :disabled="backupSaving" class="btn btn-save">
            {{ backupSaving ? 'Saving…' : 'Save Backup Settings' }}
          </button>
        </div>
      </template>

    </main>
  </div>
</template>

<style scoped>
/* ── LAYOUT ─────────────────────────────────────────── */
.settings-root {
  display: flex;
  height: calc(100vh - 52px);
  background: #0a0b0f;
  overflow: hidden;
}
.sidebar {
  width: 240px;
  min-width: 240px;
  display: flex;
  flex-direction: column;
  background: #0d0e14;
  border-right: 1px solid rgba(255,255,255,0.06);
}
.sidebar-hdr {
  padding: 24px 20px 16px;
  border-bottom: 1px solid rgba(255,255,255,0.06);
}
.sidebar-title { font-size: 18px; font-weight: 700; color: #e2e8f0; letter-spacing: -0.3px; }
.sidebar-sub { font-size: 11px; color: #475569; margin-top: 2px; }
.sidebar-nav { flex: 1; padding: 12px 8px; display: flex; flex-direction: column; gap: 2px; }
.nav-btn {
  display: flex; align-items: center; gap: 10px;
  padding: 10px 12px; border-radius: 8px; border: none;
  background: transparent; cursor: pointer; text-align: left;
  transition: all 0.15s;
}
.nav-btn:hover { background: rgba(255,255,255,0.04); }
.nav-btn.active { background: rgba(99,102,241,0.1); }
.nav-icon {
  width: 32px; height: 32px; display: flex; align-items: center; justify-content: center;
  border-radius: 8px; background: rgba(255,255,255,0.04);
  font-size: 15px; color: #64748b;
}
.nav-btn.active .nav-icon { background: rgba(99,102,241,0.15); color: #818cf8; }
.nav-text { display: flex; flex-direction: column; flex: 1; min-width: 0; }
.nav-label { font-size: 13px; font-weight: 600; color: #94a3b8; }
.nav-btn.active .nav-label { color: #e2e8f0; }
.nav-desc { font-size: 10px; color: #475569; }
.nav-arrow { font-size: 16px; color: #818cf8; font-weight: 700; }
.sidebar-footer { padding: 16px 20px; font-size: 10px; color: #334155; border-top: 1px solid rgba(255,255,255,0.04); }
.settings-main { flex: 1; overflow-y: auto; padding: 28px 32px; }

/* ── PAGE HEADER ────────────────────────────────────── */
.page-hdr { margin-bottom: 24px; }
.page-title { font-size: 20px; font-weight: 700; color: #e2e8f0; letter-spacing: -0.3px; margin: 0; }
.page-desc { font-size: 12px; color: #64748b; margin-top: 4px; }

/* ── EXCHANGE CARDS ─────────────────────────────────── */
.ex-grid { display: flex; flex-direction: column; gap: 16px; }
.ex-card {
  position: relative; border-radius: 12px; overflow: hidden;
  background: #111318; border: 1px solid rgba(255,255,255,0.06);
  padding: 0; transition: border-color 0.2s;
}
.ex-card:hover { border-color: rgba(255,255,255,0.1); }
.ex-accent { height: 3px; width: 100%; }
.ex-head { display: flex; align-items: center; gap: 14px; padding: 18px 20px 14px; }
.ex-logo {
  width: 40px; height: 40px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 18px; font-weight: 800; border: 1px solid;
  flex-shrink: 0;
}
.ex-info { flex: 1; min-width: 0; }
.ex-name { font-size: 15px; font-weight: 700; color: #e2e8f0; }
.ex-desc-text { font-size: 11px; color: #64748b; margin-top: 1px; }
.ex-badge-area { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; }
.ex-market {
  font-size: 9px; font-weight: 700; letter-spacing: 0.5px; text-transform: uppercase;
  color: #475569; background: rgba(255,255,255,0.04); padding: 2px 8px; border-radius: 4px;
}
.ex-status { font-size: 11px; font-weight: 600; display: flex; align-items: center; gap: 5px; }
.st-ok { color: #34d399; }
.st-err { color: #f87171; }
.st-off { color: #475569; }
.ex-fields { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 12px; padding: 0 20px; }
.ex-actions { display: flex; gap: 8px; padding: 16px 20px 18px; }

/* ── ZERODHA ────────────────────────────────────────── */
.zr-section { margin: 12px 20px 0; padding-top: 14px; border-top: 1px solid rgba(255,255,255,0.06); }
.zr-row { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
.zr-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; color: #475569; }
.zr-badge { font-size: 11px; font-weight: 600; padding: 3px 10px; border-radius: 6px; display: flex; align-items: center; gap: 5px; }
.zr-active { background: rgba(52,211,153,0.1); color: #34d399; }
.zr-expired { background: rgba(248,113,113,0.1); color: #f87171; }
.zr-none { background: rgba(255,255,255,0.04); color: #475569; }
.zr-msg { font-size: 11px; padding: 8px 12px; border-radius: 6px; margin-bottom: 12px; border: 1px solid; }
.msg-ok { background: rgba(52,211,153,0.06); border-color: rgba(52,211,153,0.2); color: #34d399; }
.msg-err { background: rgba(248,113,113,0.06); border-color: rgba(248,113,113,0.2); color: #f87171; }
.msg-info { background: rgba(129,140,248,0.06); border-color: rgba(129,140,248,0.2); color: #818cf8; }
.zr-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
.zr-manual { display: flex; gap: 8px; margin-bottom: 12px; }
.zr-balance { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 4px; }
.bal-card { background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.06); border-radius: 8px; padding: 12px; }
.bal-head { margin-bottom: 8px; }
.bal-title { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #475569; }
.bal-row { display: flex; justify-content: space-between; align-items: flex-end; }
.bal-sub { display: block; font-size: 9px; color: #475569; margin-bottom: 2px; }
.bal-val { display: block; font-size: 16px; font-weight: 800; letter-spacing: -0.3px; }
.bal-green { color: #34d399; }
.bal-red { color: #f87171; }
.text-right { text-align: right; }

/* ── ENGINE CARDS ────────────────────────────────────── */
.eng-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 12px; }
.eng-card {
  border-radius: 10px; overflow: hidden;
  background: #111318; border: 1px solid rgba(255,255,255,0.06);
  transition: opacity 0.2s;
}
.eng-off { opacity: 0.5; }
.eng-accent { height: 2px; }
.eng-head { display: flex; align-items: center; gap: 10px; padding: 14px 16px; cursor: pointer; }
.eng-icon { font-size: 18px; flex-shrink: 0; width: 28px; text-align: center; }
.eng-info { flex: 1; min-width: 0; }
.eng-name { font-size: 13px; font-weight: 700; color: #e2e8f0; }
.eng-desc { font-size: 10px; color: #64748b; margin-top: 1px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.eng-params { padding: 0 16px 14px; border-top: 1px solid rgba(255,255,255,0.04); display: grid; grid-template-columns: 1fr 1fr; gap: 10px; padding-top: 12px; }

/* ── SYSTEM / BACKUP CARDS ──────────────────────────── */
.sys-card {
  background: #111318; border: 1px solid rgba(255,255,255,0.06);
  border-radius: 12px; padding: 20px; margin-bottom: 16px;
}
.section-title { font-size: 14px; font-weight: 700; color: #e2e8f0; margin: 0 0 16px; }
.sys-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
.sys-grid.cols-2 { grid-template-columns: 1fr 1fr; }
.bk-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.bk-head { display: flex; align-items: center; justify-content: space-between; }
.bk-sub { font-size: 11px; color: #475569; margin-top: 2px; }
.bk-tag { font-size: 9px; font-weight: 600; color: #475569; background: rgba(255,255,255,0.04); padding: 2px 6px; border-radius: 3px; vertical-align: middle; margin-left: 6px; }

/* ── SCOPE CHIPS ────────────────────────────────────── */
.scope-row { display: flex; gap: 10px; flex-wrap: wrap; }
.scope-chip {
  display: flex; align-items: center; gap: 8px; cursor: pointer;
  padding: 8px 16px; border-radius: 8px; font-size: 12px; font-weight: 600;
  background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.06);
  color: #64748b; transition: all 0.15s;
}
.scope-chip:hover { border-color: rgba(255,255,255,0.12); }
.scope-on { background: rgba(99,102,241,0.08); border-color: rgba(99,102,241,0.25); color: #818cf8; }
.scope-check { width: 16px; height: 16px; border-radius: 4px; display: flex; align-items: center; justify-content: center;
  font-size: 10px; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1); }
.scope-on .scope-check { background: #6366f1; border-color: #6366f1; color: #fff; }
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0,0,0,0); }

/* ── FORM FIELDS ────────────────────────────────────── */
.field-group { display: flex; flex-direction: column; gap: 4px; }
.field-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #475569; }
.field-unit { font-weight: 500; color: #334155; text-transform: lowercase; letter-spacing: 0; }
.field-wrap { position: relative; display: flex; align-items: center; }
.field-input {
  width: 100%; padding: 9px 12px; border-radius: 8px; font-size: 13px;
  background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08);
  color: #e2e8f0; outline: none; transition: border-color 0.15s;
  font-family: 'SF Mono', 'Menlo', 'Consolas', monospace;
}
.field-input:focus { border-color: #6366f1; box-shadow: 0 0 0 2px rgba(99,102,241,0.15); }
.field-input::placeholder { color: #334155; font-family: inherit; }
.pw-toggle {
  position: absolute; right: 10px; background: none; border: none;
  color: #475569; cursor: pointer; font-size: 14px; padding: 4px;
}
.pw-toggle:hover { color: #818cf8; }

/* ── BUTTONS ────────────────────────────────────────── */
.btn {
  display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600;
  border-radius: 8px; border: none; cursor: pointer; transition: all 0.15s; text-decoration: none;
  padding: 10px 18px;
}
.btn-sm { padding: 7px 14px; font-size: 11px; }
.btn-save {
  background: linear-gradient(135deg, #6366f1, #4f46e5); color: #fff;
  box-shadow: 0 1px 3px rgba(99,102,241,0.25);
}
.btn-save:hover { box-shadow: 0 2px 8px rgba(99,102,241,0.35); transform: translateY(-1px); }
.btn-save:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
.btn-primary { background: linear-gradient(135deg, #6366f1, #4f46e5); color: #fff; }
.btn-ghost {
  background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08); color: #94a3b8;
}
.btn-ghost:hover { background: rgba(255,255,255,0.06); border-color: rgba(255,255,255,0.12); }
.btn-funds { background: rgba(52,211,153,0.1); color: #34d399; border: 1px solid rgba(52,211,153,0.2); }
.btn-funds:hover { background: rgba(52,211,153,0.15); }
.save-row { margin-top: 16px; display: flex; gap: 10px; }

/* ── TOGGLE ─────────────────────────────────────────── */
.toggle-btn {
  position: relative; width: 40px; height: 22px; border-radius: 11px; border: none;
  background: rgba(255,255,255,0.08); cursor: pointer; transition: background 0.2s; flex-shrink: 0;
}
.toggle-on { background: #6366f1; }
.toggle-thumb {
  position: absolute; top: 3px; left: 3px; width: 16px; height: 16px; border-radius: 50%;
  background: #fff; transition: transform 0.2s; box-shadow: 0 1px 3px rgba(0,0,0,0.3);
}
.toggle-on .toggle-thumb { transform: translateX(18px); }

/* ── PULSE ──────────────────────────────────────────── */
.pulse-dot { width: 7px; height: 7px; border-radius: 50%; display: inline-block; flex-shrink: 0; }
.dot-green { background: #34d399; box-shadow: 0 0 6px rgba(52,211,153,0.5); animation: pulse 2s infinite; }
@keyframes pulse { 0%, 100% { box-shadow: 0 0 4px rgba(52,211,153,0.4); } 50% { box-shadow: 0 0 10px rgba(52,211,153,0.7); } }

/* ── TOAST ──────────────────────────────────────────── */
.toast-bar {
  position: fixed; top: 16px; right: 16px; z-index: 999; display: flex; align-items: center; gap: 8px;
  padding: 12px 18px; border-radius: 10px; font-size: 12px; font-weight: 600;
  box-shadow: 0 8px 24px rgba(0,0,0,0.4); border: 1px solid;
}
.toast-ok { background: #0b1a14; border-color: rgba(52,211,153,0.25); color: #34d399; }
.toast-err { background: #1a0b0b; border-color: rgba(248,113,113,0.25); color: #f87171; }
.toast-dot { width: 6px; height: 6px; border-radius: 50%; }
.dot-ok { background: #34d399; }
.dot-err { background: #f87171; }
.toast-enter-active, .toast-leave-active { transition: all 0.3s ease; }
.toast-enter-from, .toast-leave-to { opacity: 0; transform: translateX(40px); }

/* ── SPIN ───────────────────────────────────────────── */
.spin { display: inline-block; animation: spin 1s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

/* ── RESPONSIVE ─────────────────────────────────────── */
@media (max-width: 768px) {
  .settings-root { flex-direction: column; height: auto; }
  .sidebar { width: 100%; min-width: auto; flex-direction: row; border-right: none; border-bottom: 1px solid rgba(255,255,255,0.06); }
  .sidebar-hdr { display: none; }
  .sidebar-nav { flex-direction: row; padding: 8px; overflow-x: auto; }
  .nav-desc, .nav-arrow, .sidebar-footer { display: none; }
  .settings-main { padding: 16px; }
  .ex-fields, .sys-grid, .bk-grid, .eng-grid { grid-template-columns: 1fr; }
  .sys-grid.cols-2 { grid-template-columns: 1fr; }
  .zr-balance { grid-template-columns: 1fr; }
}
.mt-3 { margin-top: 12px; }
.mt-4 { margin-top: 16px; }
</style>
