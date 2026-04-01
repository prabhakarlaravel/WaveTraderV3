<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { RouterView, useRouter, useRoute } from 'vue-router'
import { useAuthStore } from './stores/useAuthStore'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

// Live clock — ticks every second
const nowTick = ref(Date.now())
let clockInterval = null

onMounted(() => {
  clockInterval = setInterval(() => { nowTick.value = Date.now() }, 1000)
})
onUnmounted(() => {
  if (clockInterval) clearInterval(clockInterval)
})

const liveClock = computed(() => {
  const _tick = nowTick.value
  const now = new Date()
  const ist = new Date(now.getTime() + (5.5 * 60 * 60 * 1000) + (now.getTimezoneOffset() * 60 * 1000))
  const h = String(ist.getHours()).padStart(2, '0')
  const m = String(ist.getMinutes()).padStart(2, '0')
  const s = String(ist.getSeconds()).padStart(2, '0')
  return `${h}:${m}:${s}`
})

async function handleLogout() {
  await auth.logout()
  router.push('/login')
}

const navLinks = [
  { path: '/chart', label: 'Chart' },
  { path: '/tv', label: 'TV' },
  { path: '/backtest', label: 'Backtest' },
  { path: '/wave-health', label: 'Wave Health' },
  { path: '/gaps', label: 'Gaps' },
  { path: '/settings', label: 'Settings' },
]
</script>

<template>
  <div class="app-root">
    <!-- Nav bar (only when authenticated) -->
    <nav v-if="auth.isAuthenticated" class="app-nav">
      <div class="nav-inner">
        <!-- Logo -->
        <div class="logo-group">
          <div class="logo-icon">W</div>
          <span class="logo-text">WaveTrader</span>
          <span class="logo-version">V3</span>
        </div>

        <!-- Nav links -->
        <div class="nav-links">
          <RouterLink
            v-for="link in navLinks"
            :key="link.path"
            :to="link.path"
            :class="['nav-link', { active: route.path === link.path }]"
          >
            {{ link.label }}
          </RouterLink>
        </div>

        <div class="nav-spacer"></div>

        <!-- Live Clock (IST) -->
        <div class="nav-clock">
          <span class="nav-clock-time">{{ liveClock }}</span>
          <span class="nav-clock-zone">IST</span>
        </div>

        <!-- User + Logout -->
        <div class="nav-user">
          <span class="user-name">{{ auth.user?.name || auth.user?.email }}</span>
          <button @click="handleLogout" class="btn-logout">Logout</button>
        </div>
      </div>
    </nav>

    <!-- Main content -->
    <main :class="auth.isAuthenticated ? 'main-content' : ''">
      <RouterView />
    </main>
  </div>
</template>

<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600;700&display=swap');

:root {
  --bg: #06090f;
  --card: #0c1221;
  --card-alt: #101a2e;
  --surface: #080d18;
  --border: #162040;
  --border-hi: #1e3060;
  --text: #dfe6f2;
  --muted: #7b8ba8;
  --dim: #4a5978;
  --bull: #00dc82;
  --bull-fade: rgba(0,220,130,0.06);
  --bull-line: rgba(0,220,130,0.35);
  --bear: #ff3b5c;
  --bear-fade: rgba(255,59,92,0.06);
  --bear-line: rgba(255,59,92,0.35);
  --wave: #8b5cf6;
  --wave-bg: rgba(139,92,246,0.10);
  --ob: #f59e0b;
  --ob-bg: rgba(245,158,11,0.08);
  --fvg: #06b6d4;
  --fvg-bg: rgba(6,182,212,0.07);
  --vwap: #ec4899;
  --bos: #10b981;
  --choch: #f97316;
  --accent: #3b82f6;
  --accent-bg: rgba(59,130,246,0.1);
  --mono: 'JetBrains Mono', 'Fira Code', monospace;
  --sans: 'DM Sans', 'Segoe UI', sans-serif;
}

* { box-sizing: border-box; margin: 0; }
body { background: var(--bg); color: var(--text); font-family: var(--sans); }
button { transition: all .1s; }
::-webkit-scrollbar { width: 4px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--border); border-radius: 4px; }

.app-root { min-height: 100vh; display: flex; flex-direction: column; background: var(--bg); }

.app-nav {
  border-bottom: 1px solid var(--border);
  background: var(--surface);
}
.nav-inner {
  display: flex;
  align-items: center;
  padding: 8px 12px;
  gap: 8px;
}
.logo-group {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-right: 8px;
}
.logo-icon {
  width: 26px; height: 26px; border-radius: 6px;
  background: linear-gradient(135deg, #8b5cf6, #6366f1);
  display: flex; align-items: center; justify-content: center;
  font-size: 13px; font-weight: 800; color: #fff;
  font-family: var(--mono);
}
.logo-text { font-size: 13px; font-weight: 700; color: var(--text); }
.logo-version {
  font-size: 9px; color: var(--dim); font-family: var(--mono);
  background: var(--card); padding: 1px 5px; border-radius: 3px;
}
.nav-links {
  display: flex; gap: 2px;
  background: var(--card); border-radius: 7px; padding: 2px;
  border: 1px solid var(--border);
}
.nav-link {
  background: transparent; color: var(--dim);
  border: 1px solid transparent; border-radius: 5px;
  padding: 3px 10px; font-size: 11px; font-weight: 600;
  text-decoration: none; cursor: pointer; transition: all .1s;
}
.nav-link:hover { color: var(--text); }
.nav-link.active, .nav-link.router-link-active {
  background: var(--card-alt); color: var(--text);
  border-color: var(--border-hi);
}
.nav-spacer { flex: 1; }
.nav-user { display: flex; align-items: center; gap: 8px; }
.user-name { font-size: 11px; color: var(--muted); font-family: var(--mono); }
.btn-logout {
  background: var(--card); color: var(--dim); border: 1px solid var(--border);
  border-radius: 5px; padding: 3px 10px; font-size: 10px; font-weight: 600;
  cursor: pointer; font-family: var(--sans);
}
.btn-logout:hover { color: var(--text); background: var(--card-alt); }

.nav-clock {
  display: flex; align-items: baseline; gap: 4px;
  padding: 0 10px;
  font-family: var(--mono);
  border-right: 1px solid var(--border);
  margin-right: 2px;
}
.nav-clock-time {
  font-size: 14px; font-weight: 700; color: #e2e8f0; letter-spacing: 1px;
}
.nav-clock-zone {
  font-size: 8px; font-weight: 600; color: #64748b; letter-spacing: 0.5px;
}

.main-content { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
</style>
