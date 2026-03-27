import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import axios from 'axios'

export const useAuthStore = defineStore('auth', () => {
  const user = ref(null)
  const token = ref(localStorage.getItem('token') || null)

  const isAuthenticated = computed(() => !!token.value)

  if (token.value) {
    axios.defaults.headers.common['Authorization'] = `Bearer ${token.value}`
  }

  async function login(email, password) {
    const { data } = await axios.post('/api/v1/login', { email, password })
    user.value = data.user
    token.value = data.token
    localStorage.setItem('token', data.token)
    axios.defaults.headers.common['Authorization'] = `Bearer ${data.token}`
    return data
  }

  async function fetchUser() {
    try {
      const { data } = await axios.get('/api/v1/me')
      user.value = data
    } catch {
      logout()
    }
  }

  async function logout() {
    try {
      await axios.post('/api/v1/logout')
    } catch {
      // ignore
    }
    user.value = null
    token.value = null
    localStorage.removeItem('token')
    delete axios.defaults.headers.common['Authorization']
  }

  return { user, token, isAuthenticated, login, fetchUser, logout }
})
