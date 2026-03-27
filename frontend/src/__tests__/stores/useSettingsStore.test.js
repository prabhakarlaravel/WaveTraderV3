import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { useSettingsStore } from '../../stores/useSettingsStore'
import axios from 'axios'

vi.mock('axios')

describe('useSettingsStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('has correct default state', () => {
    const store = useSettingsStore()
    expect(store.settings).toEqual({})
    expect(store.symbols).toEqual([])
    expect(store.loading).toBe(false)
    expect(store.saving).toBe(false)
    expect(store.toast).toBeNull()
  })

  it('fetches all settings grouped', async () => {
    const mockSettings = {
      exchange: [{ key: 'binance_api_key', value: 'xxx', group: 'exchange' }],
      system: [{ key: 'fetch_interval', value: '30', group: 'system' }],
    }
    axios.get.mockResolvedValueOnce({ data: mockSettings })

    const store = useSettingsStore()
    await store.fetchAll()

    expect(store.settings).toEqual(mockSettings)
  })

  it('gets a setting value by key', async () => {
    const store = useSettingsStore()
    store.settings = {
      exchange: [{ key: 'binance_api_key', value: 'my-key', group: 'exchange' }],
    }

    expect(store.get('binance_api_key')).toBe('my-key')
    expect(store.get('nonexistent', 'default')).toBe('default')
  })

  it('saves settings and shows toast', async () => {
    axios.put.mockResolvedValueOnce({ data: { message: 'ok' } })
    axios.get.mockResolvedValueOnce({ data: {} })

    const store = useSettingsStore()
    await store.save([{ key: 'test', value: 'val', group: 'system' }])

    expect(store.toast).not.toBeNull()
    expect(store.toast.type).toBe('success')
  })

  it('tests exchange connection', async () => {
    axios.post.mockResolvedValueOnce({ data: { success: true, message: 'Connected' } })

    const store = useSettingsStore()
    const result = await store.testConnection('binance')

    expect(result.success).toBe(true)
  })

  it('fetches symbols', async () => {
    const mockSymbols = [{ id: 1, ticker: 'BTCUSDT' }]
    axios.get.mockResolvedValueOnce({ data: mockSymbols })

    const store = useSettingsStore()
    await store.fetchSymbols()

    expect(store.symbols).toEqual(mockSymbols)
  })

  it('adds a symbol', async () => {
    const newSymbol = { id: 2, ticker: 'ETHUSDT', exchange: 'binance' }
    axios.post.mockResolvedValueOnce({ data: newSymbol })

    const store = useSettingsStore()
    const result = await store.addSymbol({ exchange: 'binance', ticker: 'ETHUSDT', name: 'ETH' })

    expect(result.ticker).toBe('ETHUSDT')
    expect(store.symbols).toContainEqual(newSymbol)
  })

  it('deletes a symbol', async () => {
    axios.delete.mockResolvedValueOnce({})

    const store = useSettingsStore()
    store.symbols = [{ id: 1, ticker: 'BTCUSDT' }, { id: 2, ticker: 'ETHUSDT' }]

    await store.deleteSymbol(2)

    expect(store.symbols.length).toBe(1)
    expect(store.symbols[0].ticker).toBe('BTCUSDT')
  })
})
