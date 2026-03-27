import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { useChartStore } from '../../stores/useChartStore'
import axios from 'axios'

vi.mock('axios')

describe('useChartStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('has correct default state', () => {
    const store = useChartStore()
    expect(store.symbols).toEqual([])
    expect(store.activeSymbolId).toBeNull()
    expect(store.activeTimeframe).toBe('1H')
    expect(store.candles).toEqual([])
    expect(store.loading).toBe(false)
  })

  it('fetches symbols and sets first as active', async () => {
    const mockSymbols = [
      { id: 1, ticker: 'BTCUSDT', exchange: 'binance' },
      { id: 2, ticker: 'ETHUSDT', exchange: 'binance' },
    ]
    axios.get.mockResolvedValueOnce({ data: mockSymbols })

    const store = useChartStore()
    await store.fetchSymbols()

    expect(store.symbols).toEqual(mockSymbols)
    expect(store.activeSymbolId).toBe(1)
  })

  it('fetches candles and overlays', async () => {
    const mockCandles = [
      { timestamp: '2026-03-27 10:00:00', open: '65000', high: '65500', low: '64800', close: '65200', volume: '100' },
      { timestamp: '2026-03-27 11:00:00', open: '65200', high: '65800', low: '65100', close: '65700', volume: '120' },
    ]
    axios.get
      .mockResolvedValueOnce({ data: mockCandles }) // candles
      .mockResolvedValueOnce({ data: { signals: [], orderBlocks: [], fvgs: [] } }) // overlays

    const store = useChartStore()
    store.activeSymbolId = 1
    await store.fetchCandles()

    expect(store.candles).toEqual(mockCandles)
    expect(store.loading).toBe(false)
  })

  it('formats candles for lightweight-charts', async () => {
    const store = useChartStore()
    store.candles = [
      { timestamp: '2026-03-27T10:00:00.000Z', open: '65000', high: '65500', low: '64800', close: '65200', volume: '100' },
    ]

    const formatted = store.formattedCandles
    expect(formatted.length).toBe(1)
    expect(formatted[0].open).toBe(65000)
    expect(formatted[0].high).toBe(65500)
    expect(formatted[0].low).toBe(64800)
    expect(formatted[0].close).toBe(65200)
    expect(typeof formatted[0].time).toBe('number')
  })

  it('formats volume with bull/bear colors', () => {
    const store = useChartStore()
    store.candles = [
      { timestamp: '2026-03-27T10:00:00.000Z', open: '65000', high: '65500', low: '64800', close: '65200', volume: '100' },
      { timestamp: '2026-03-27T11:00:00.000Z', open: '65200', high: '65300', low: '64900', close: '65000', volume: '80' },
    ]

    const volume = store.formattedVolume
    expect(volume[0].color).toContain('38, 166, 154') // green (bull)
    expect(volume[1].color).toContain('239, 83, 80') // red (bear)
  })

  it('changes timeframe and triggers fetch', async () => {
    axios.get.mockResolvedValue({ data: [] })

    const store = useChartStore()
    store.activeSymbolId = 1
    store.setTimeframe('4H')

    expect(store.activeTimeframe).toBe('4H')
  })

  it('changes symbol and triggers fetch', async () => {
    axios.get.mockResolvedValue({ data: [] })

    const store = useChartStore()
    store.setSymbol(2)

    expect(store.activeSymbolId).toBe(2)
  })
})
