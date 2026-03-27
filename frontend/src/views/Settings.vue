<script setup>
import { ref, onMounted, reactive } from 'vue'
import { useSettingsStore } from '../stores/useSettingsStore'
import SymbolManager from '../components/settings/SymbolManager.vue'

const store = useSettingsStore()
const activeTab = ref('exchange')
const tabs = [
  { id: 'exchange', label: 'Exchange' },
  { id: 'engine', label: 'Engines' },
  { id: 'system', label: 'System' },
  { id: 'backup', label: 'Backup' },
]

// ── Exchange state ──
const exchanges = reactive([
  {
    id: 'binance', name: 'Binance', desc: 'Crypto — REST API v3',
    fields: [
      { key: 'binance_api_key', label: 'API Key', type: 'password', encrypted: true },
      { key: 'binance_api_secret', label: 'API Secret', type: 'password', encrypted: true },
    ],
    testing: false, status: null,
  },
  {
    id: 'zerodha', name: 'Zerodha', desc: 'NSE/BSE — KiteConnect v3',
    fields: [
      { key: 'zerodha_api_key', label: 'API Key', type: 'password', encrypted: true },
      { key: 'zerodha_api_secret', label: 'API Secret', type: 'password', encrypted: true },
      { key: 'zerodha_access_token', label: 'Access Token', type: 'password', encrypted: true },
    ],
    testing: false, status: null,
  },
  {
    id: 'oanda', name: 'OANDA', desc: 'Forex — REST v20',
    fields: [
      { key: 'oanda_account_id', label: 'Account ID', type: 'text', encrypted: false },
      { key: 'oanda_bearer_token', label: 'Bearer Token', type: 'password', encrypted: true },
      { key: 'oanda_mode', label: 'Mode', type: 'select', options: ['practice', 'live'], encrypted: false },
    ],
    testing: false, status: null,
  },
])
const exchangeValues = reactive({})

// ── Engine state ──
const engines = reactive([
  { key: 'elliott_wave', name: 'Elliott Wave', enabled: true, params: [] },
  { key: 'market_structure', name: 'Market Structure', enabled: true, params: [{ key: 'ms_lookback', label: 'Swing Lookback', type: 'number', default: 5 }] },
  { key: 'order_block', name: 'Order Block', enabled: true, params: [{ key: 'ob_atr_period', label: 'ATR Period', type: 'number', default: 14 }, { key: 'ob_impulse_mult', label: 'Impulse Multiplier', type: 'number', default: 1.5 }] },
  { key: 'fvg', name: 'FVG', enabled: true, params: [] },
  { key: 'smc', name: 'SMC', enabled: true, params: [] },
  { key: 'vwap', name: 'VWAP', enabled: true, params: [] },
  { key: 'price_action', name: 'Price Action', enabled: true, params: [] },
])
const engineValues = reactive({})
const expandedEngine = ref(null)

// ── System state ──
const systemValues = reactive({
  fetch_interval: '30',
  historical_depth: '3',
  telegram_webhook: '',
  horizon_workers: '3',
})

// ── Backup state ──
const backupValues = reactive({
  local_enabled: false,
  local_retention_days: '7',
  r2_account_id: '',
  r2_access_key: '',
  r2_secret_key: '',
  r2_bucket: '',
  scope_candles: true,
  scope_waves: true,
  scope_settings: true,
  scope_trades: true,
})

onMounted(async () => {
  await store.fetchAll()
  loadValues()
})

function loadValues() {
  // Load exchange values
  for (const ex of exchanges) {
    for (const f of ex.fields) {
      exchangeValues[f.key] = store.get(f.key, f.type === 'select' ? f.options?.[0] || '' : '')
    }
  }
  // Load engine toggles
  for (const eng of engines) {
    eng.enabled = store.get(`engine_${eng.key}_enabled`, 'true') !== 'false'
    for (const p of eng.params) {
      engineValues[p.key] = store.get(p.key, String(p.default))
    }
  }
  // Load system
  systemValues.fetch_interval = store.get('fetch_interval', '30')
  systemValues.historical_depth = store.get('historical_depth', '3')
  systemValues.telegram_webhook = store.get('telegram_webhook', '')
  systemValues.horizon_workers = store.get('horizon_workers', '3')
  // Load backup
  backupValues.local_enabled = store.get('backup_local_enabled', 'false') === 'true'
  backupValues.local_retention_days = store.get('backup_local_retention_days', '7')
  backupValues.r2_account_id = store.get('r2_account_id', '')
  backupValues.r2_access_key = store.get('r2_access_key', '')
  backupValues.r2_secret_key = store.get('r2_secret_key', '')
  backupValues.r2_bucket = store.get('r2_bucket', '')
}

async function saveExchange(ex) {
  const settings = ex.fields.map(f => ({
    key: f.key,
    value: exchangeValues[f.key] || '',
    group: 'exchange',
    encrypted: f.encrypted,
  }))
  await store.save(settings)
}

async function testExchange(ex) {
  ex.testing = true
  ex.status = null
  const result = await store.testConnection(ex.id)
  ex.status = result
  ex.testing = false
}

async function saveEngines() {
  const settings = []
  for (const eng of engines) {
    settings.push({ key: `engine_${eng.key}_enabled`, value: String(eng.enabled), group: 'engine', encrypted: false })
    for (const p of eng.params) {
      settings.push({ key: p.key, value: engineValues[p.key] || String(p.default), group: 'engine', encrypted: false })
    }
  }
  await store.save(settings)
}

async function saveSystem() {
  await store.save([
    { key: 'fetch_interval', value: systemValues.fetch_interval, group: 'system' },
    { key: 'historical_depth', value: systemValues.historical_depth, group: 'system' },
    { key: 'telegram_webhook', value: systemValues.telegram_webhook, group: 'system' },
    { key: 'horizon_workers', value: systemValues.horizon_workers, group: 'system' },
  ])
}

async function saveBackup() {
  await store.save([
    { key: 'backup_local_enabled', value: String(backupValues.local_enabled), group: 'backup' },
    { key: 'backup_local_retention_days', value: backupValues.local_retention_days, group: 'backup' },
    { key: 'r2_account_id', value: backupValues.r2_account_id, group: 'backup', encrypted: false },
    { key: 'r2_access_key', value: backupValues.r2_access_key, group: 'backup', encrypted: true },
    { key: 'r2_secret_key', value: backupValues.r2_secret_key, group: 'backup', encrypted: true },
    { key: 'r2_bucket', value: backupValues.r2_bucket, group: 'backup', encrypted: false },
  ])
}
</script>

<template>
  <div class="p-4 max-w-4xl mx-auto">
    <!-- Toast -->
    <Teleport to="body">
      <div v-if="store.toast" class="fixed top-4 right-4 z-50 rounded-lg px-4 py-3 text-sm font-semibold shadow-lg"
        :style="store.toast.type === 'success' ? 'background: rgba(0,220,130,0.15); border: 1px solid rgba(0,220,130,0.4); color: var(--bull)' : 'background: rgba(255,59,92,0.15); border: 1px solid rgba(255,59,92,0.4); color: var(--bear)'">
        {{ store.toast.message }}
      </div>
    </Teleport>

    <h1 class="text-2xl font-bold" style="color: var(--text)">Settings</h1>
    <p class="mt-1 text-sm" style="color: var(--muted)">Configure exchange connections, engine parameters, backups, and system preferences.</p>

    <!-- Tabs -->
    <div class="mt-6 flex gap-1" style="border-bottom: 1px solid var(--border)">
      <button v-for="tab in tabs" :key="tab.id" @click="activeTab = tab.id"
        class="px-4 py-2.5 text-sm font-medium transition"
        :style="activeTab === tab.id ? 'border-bottom: 2px solid var(--accent); color: var(--text)' : 'color: var(--dim)'">
        {{ tab.label }}
      </button>
    </div>

    <div class="mt-6">
      <!-- ══════ EXCHANGE TAB ══════ -->
      <div v-if="activeTab === 'exchange'" class="space-y-4">
        <div v-for="ex in exchanges" :key="ex.id" class="rounded-xl p-5"
          style="background: var(--card); border: 1px solid var(--border)">
          <div class="flex items-center justify-between mb-4">
            <div>
              <h3 class="font-semibold" style="color: var(--text)">{{ ex.name }}</h3>
              <p class="text-xs" style="color: var(--dim)">{{ ex.desc }}</p>
            </div>
            <span v-if="ex.status" class="rounded-full px-2.5 py-1 text-xs font-semibold"
              :style="ex.status.success ? 'background: rgba(0,220,130,0.1); color: var(--bull)' : 'background: rgba(255,59,92,0.1); color: var(--bear)'">
              {{ ex.status.success ? 'Connected' : 'Failed' }}
            </span>
            <span v-else class="rounded-full px-2.5 py-1 text-xs" style="background: var(--surface); color: var(--dim)">Not Connected</span>
          </div>

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

          <div class="flex gap-2 mt-4">
            <button @click="saveExchange(ex)" :disabled="store.saving"
              class="rounded-md px-4 py-2 text-xs font-semibold"
              style="background: var(--accent); color: #fff">
              {{ store.saving ? 'Saving...' : 'Save' }}
            </button>
            <button @click="testExchange(ex)" :disabled="ex.testing"
              class="rounded-md px-4 py-2 text-xs font-semibold"
              style="background: var(--surface); border: 1px solid var(--border); color: var(--muted)">
              {{ ex.testing ? 'Testing...' : 'Test Connection' }}
            </button>
          </div>
        </div>
      </div>

      <!-- ══════ ENGINE TAB ══════ -->
      <div v-else-if="activeTab === 'engine'">
        <div class="space-y-2">
          <div v-for="eng in engines" :key="eng.key" class="rounded-xl overflow-hidden"
            style="background: var(--card); border: 1px solid var(--border)">
            <div class="flex items-center gap-3 p-4 cursor-pointer" @click="eng.params.length ? expandedEngine = (expandedEngine === eng.key ? null : eng.key) : null">
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
        <button @click="saveEngines" :disabled="store.saving"
          class="mt-4 rounded-md px-4 py-2 text-xs font-semibold"
          style="background: var(--accent); color: #fff">
          {{ store.saving ? 'Saving...' : 'Save Engine Settings' }}
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
            <input v-model="systemValues.telegram_webhook" type="url" placeholder="https://api.telegram.org/bot..."
              class="w-full rounded-md px-3 py-2 text-sm"
              style="background: var(--surface); border: 1px solid var(--border); color: var(--text)" />
          </div>
          <button @click="saveSystem" :disabled="store.saving"
            class="mt-4 rounded-md px-4 py-2 text-xs font-semibold"
            style="background: var(--accent); color: #fff">
            {{ store.saving ? 'Saving...' : 'Save System Settings' }}
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

          <!-- Scope checkboxes -->
          <div class="mt-4">
            <label class="block text-[10px] mb-2 uppercase tracking-wider" style="color: var(--dim)">Backup Scope</label>
            <div class="flex gap-4">
              <label v-for="scope in ['candles', 'waves', 'settings', 'trades']" :key="scope"
                class="flex items-center gap-2 text-xs cursor-pointer" style="color: var(--muted)">
                <input type="checkbox" v-model="backupValues[`scope_${scope}`]"
                  class="rounded" style="accent-color: var(--accent)" />
                {{ scope.charAt(0).toUpperCase() + scope.slice(1) }}
              </label>
            </div>
          </div>
        </div>

        <button @click="saveBackup" :disabled="store.saving"
          class="rounded-md px-4 py-2 text-xs font-semibold"
          style="background: var(--accent); color: #fff">
          {{ store.saving ? 'Saving...' : 'Save Backup Settings' }}
        </button>
      </div>
    </div>
  </div>
</template>
