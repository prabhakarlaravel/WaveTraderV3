import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { useTradeStore } from '../../stores/useTradeStore'
import axios from 'axios'

vi.mock('axios')

describe('useTradeStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('has correct default state', () => {
    const store = useTradeStore()
    expect(store.trades).toEqual([])
    expect(store.openTrades).toEqual([])
    expect(store.closedTrades).toEqual([])
    expect(store.totalPnl).toBe(0)
    expect(store.winRate).toBe(0)
  })

  it('computes open and closed trades', () => {
    const store = useTradeStore()
    store.trades = [
      { id: 1, status: 'open', pnl: null },
      { id: 2, status: 'closed', pnl: '50.00' },
      { id: 3, status: 'closed', pnl: '-20.00' },
      { id: 4, status: 'open', pnl: null },
    ]

    expect(store.openTrades.length).toBe(2)
    expect(store.closedTrades.length).toBe(2)
  })

  it('calculates total P&L from closed trades', () => {
    const store = useTradeStore()
    store.trades = [
      { id: 1, status: 'closed', pnl: '100.50' },
      { id: 2, status: 'closed', pnl: '-30.25' },
      { id: 3, status: 'open', pnl: null },
    ]

    expect(store.totalPnl).toBeCloseTo(70.25)
  })

  it('calculates win rate', () => {
    const store = useTradeStore()
    store.trades = [
      { id: 1, status: 'closed', pnl: '100' },
      { id: 2, status: 'closed', pnl: '50' },
      { id: 3, status: 'closed', pnl: '-30' },
      { id: 4, status: 'closed', pnl: '-10' },
    ]

    expect(store.winRate).toBe(50) // 2 wins out of 4
  })

  it('calculates equity curve', () => {
    const store = useTradeStore()
    store.trades = [
      { id: 1, status: 'closed', pnl: '100' },
      { id: 2, status: 'closed', pnl: '-50' },
      { id: 3, status: 'closed', pnl: '200' },
    ]

    const curve = store.equityCurve
    expect(curve).toEqual([10100, 10050, 10250])
  })

  it('opens a trade via API', async () => {
    const mockTrade = { id: 5, type: 'long', entry_price: '65000', status: 'open' }
    axios.post.mockResolvedValueOnce({ data: mockTrade })

    const store = useTradeStore()
    const result = await store.openTrade({ symbol_id: 1, type: 'long', entry_price: 65000, quantity: 1 })

    expect(result).toEqual(mockTrade)
    expect(store.trades[0]).toEqual(mockTrade)
  })

  it('closes a trade and updates in list', async () => {
    const closedTrade = { id: 1, type: 'long', entry_price: '65000', exit_price: '66000', status: 'closed', pnl: '100' }
    axios.put.mockResolvedValueOnce({ data: closedTrade })

    const store = useTradeStore()
    store.trades = [{ id: 1, type: 'long', entry_price: '65000', status: 'open', pnl: null }]

    await store.closeTrade(1, 66000)

    expect(store.trades[0].status).toBe('closed')
    expect(store.trades[0].pnl).toBe('100')
  })
})
