import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { useAuthStore } from '../../stores/useAuthStore'
import axios from 'axios'

vi.mock('axios')

describe('useAuthStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    localStorage.clear()
    delete axios.defaults.headers.common['Authorization']
  })

  it('starts unauthenticated', () => {
    const store = useAuthStore()
    expect(store.isAuthenticated).toBe(false)
    expect(store.user).toBeNull()
    expect(store.token).toBeNull()
  })

  it('logs in and stores token', async () => {
    axios.post.mockResolvedValue({
      data: { user: { id: 1, name: 'Test', email: 'test@test.com' }, token: 'abc123' },
    })

    const store = useAuthStore()
    await store.login('test@test.com', 'password')

    expect(store.isAuthenticated).toBe(true)
    expect(store.user.name).toBe('Test')
    expect(store.token).toBe('abc123')
    expect(localStorage.getItem('token')).toBe('abc123')
    expect(axios.defaults.headers.common['Authorization']).toBe('Bearer abc123')
  })

  it('logs out and clears token', async () => {
    axios.post.mockResolvedValue({ data: {} })
    const store = useAuthStore()

    // Login first
    axios.post.mockResolvedValueOnce({
      data: { user: { id: 1, name: 'Test' }, token: 'abc123' },
    })
    await store.login('test@test.com', 'password')
    expect(store.isAuthenticated).toBe(true)

    // Logout
    axios.post.mockResolvedValueOnce({ data: { message: 'Logged out' } })
    await store.logout()

    expect(store.isAuthenticated).toBe(false)
    expect(store.user).toBeNull()
    expect(store.token).toBeNull()
    expect(localStorage.getItem('token')).toBeNull()
  })

  it('restores token from localStorage', () => {
    localStorage.setItem('token', 'saved-token')
    const store = useAuthStore()
    expect(store.token).toBe('saved-token')
    expect(store.isAuthenticated).toBe(true)
  })
})
