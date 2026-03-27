<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/useAuthStore'

const auth = useAuthStore()
const router = useRouter()

const email = ref('admin@wavetrader.dev')
const password = ref('password')
const error = ref('')
const loading = ref(false)

async function handleLogin() {
  error.value = ''
  loading.value = true
  try {
    await auth.login(email.value, password.value)
    router.push('/chart')
  } catch (e) {
    error.value = e.response?.data?.message || 'Login failed'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="flex min-h-screen items-center justify-center bg-gray-950">
    <div class="w-full max-w-md rounded-2xl border border-gray-800 bg-gray-900 p-8 shadow-2xl">
      <!-- Logo -->
      <div class="mb-8 text-center">
        <h1 class="text-3xl font-bold text-white">
          <span class="text-blue-400">Wave</span>Trader
        </h1>
        <p class="mt-2 text-sm text-gray-500">Multi-market trading analytics platform</p>
      </div>

      <!-- Login Form -->
      <form @submit.prevent="handleLogin" class="space-y-5">
        <div>
          <label for="email" class="mb-1.5 block text-sm font-medium text-gray-400">Email</label>
          <input
            id="email"
            v-model="email"
            type="email"
            required
            class="w-full rounded-lg border border-gray-700 bg-gray-800 px-4 py-2.5 text-white placeholder-gray-500 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            placeholder="admin@wavetrader.dev"
          />
        </div>

        <div>
          <label for="password" class="mb-1.5 block text-sm font-medium text-gray-400">Password</label>
          <input
            id="password"
            v-model="password"
            type="password"
            required
            class="w-full rounded-lg border border-gray-700 bg-gray-800 px-4 py-2.5 text-white placeholder-gray-500 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            placeholder="••••••••"
          />
        </div>

        <!-- Error -->
        <p v-if="error" class="rounded-lg bg-red-900/30 px-4 py-2 text-sm text-red-400">
          {{ error }}
        </p>

        <!-- Submit -->
        <button
          type="submit"
          :disabled="loading"
          class="w-full rounded-lg bg-blue-600 px-4 py-2.5 font-semibold text-white transition hover:bg-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 focus:ring-offset-gray-900 disabled:opacity-50"
        >
          {{ loading ? 'Signing in...' : 'Sign in' }}
        </button>
      </form>

      <!-- Demo hint -->
      <div class="mt-6 rounded-lg border border-gray-800 bg-gray-800/50 p-4">
        <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Demo Credentials</p>
        <div class="mt-2 space-y-1 text-sm text-gray-400">
          <p>Email: <span class="font-mono text-gray-300">admin@wavetrader.dev</span></p>
          <p>Password: <span class="font-mono text-gray-300">password</span></p>
        </div>
      </div>
    </div>
  </div>
</template>
