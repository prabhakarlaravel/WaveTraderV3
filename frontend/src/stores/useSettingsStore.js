import { defineStore } from 'pinia'
import { ref } from 'vue'
import axios from 'axios'

export const useSettingsStore = defineStore('settings', () => {
  const settings = ref({})
  const symbols = ref([])
  const loading = ref(false)
  const saving = ref(false)
  const toast = ref(null)

  function get(key, defaultVal = '') {
    for (const group of Object.values(settings.value)) {
      if (Array.isArray(group)) {
        const found = group.find((s) => s.key === key)
        if (found) return found.value
      }
    }
    return defaultVal
  }

  async function fetchAll() {
    loading.value = true
    try {
      const { data } = await axios.get('/api/v1/settings')
      settings.value = data
    } finally {
      loading.value = false
    }
  }

  async function save(settingsArray) {
    saving.value = true
    try {
      await axios.put('/api/v1/settings', { settings: settingsArray })
      showToast('Settings saved successfully', 'success')
      await fetchAll()
    } catch (e) {
      showToast(e.response?.data?.message || 'Failed to save settings', 'error')
    } finally {
      saving.value = false
    }
  }

  async function testConnection(exchange) {
    try {
      const { data } = await axios.post('/api/v1/settings/test', { exchange })
      return data
    } catch (e) {
      return { success: false, message: e.response?.data?.message || 'Connection failed' }
    }
  }

  async function fetchSymbols() {
    const { data } = await axios.get('/api/v1/chart/symbols')
    symbols.value = data
  }

  async function addSymbol(payload) {
    const { data } = await axios.post('/api/v1/chart/symbols', payload)
    symbols.value.push(data)
    showToast(`${payload.ticker} added`, 'success')
    return data
  }

  async function updateSymbol(id, payload) {
    const { data } = await axios.patch(`/api/v1/chart/symbols/${id}`, payload)
    const idx = symbols.value.findIndex((s) => s.id === id)
    if (idx !== -1) symbols.value[idx] = data
    return data
  }

  async function deleteSymbol(id) {
    await axios.delete(`/api/v1/chart/symbols/${id}`)
    symbols.value = symbols.value.filter((s) => s.id !== id)
    showToast('Symbol removed', 'success')
  }

  function showToast(message, type = 'success') {
    toast.value = { message, type }
    setTimeout(() => { toast.value = null }, 3000)
  }

  return {
    settings, symbols, loading, saving, toast,
    get, fetchAll, save, testConnection,
    fetchSymbols, addSymbol, updateSymbol, deleteSymbol, showToast,
  }
})
