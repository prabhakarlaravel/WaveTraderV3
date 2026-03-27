import { createRouter, createWebHistory } from 'vue-router'

const routes = [
  {
    path: '/login',
    name: 'Login',
    component: () => import('../views/Login.vue'),
    meta: { guest: true },
  },
  {
    path: '/',
    redirect: '/chart',
  },
  {
    path: '/chart',
    name: 'LiveChart',
    component: () => import('../views/LiveChart.vue'),
    meta: { auth: true },
  },
  {
    path: '/tv',
    name: 'TvChart',
    component: () => import('../views/TvChartView.vue'),
    meta: { auth: true },
  },
  {
    path: '/backtest',
    name: 'Backtest',
    component: () => import('../views/Backtest.vue'),
    meta: { auth: true },
  },
  {
    path: '/wave-health',
    name: 'WaveHealth',
    component: () => import('../views/WaveHealth.vue'),
    meta: { auth: true },
  },
  {
    path: '/gaps',
    name: 'DataGaps',
    component: () => import('../views/DataGaps.vue'),
    meta: { auth: true },
  },
  {
    path: '/settings',
    name: 'Settings',
    component: () => import('../views/Settings.vue'),
    meta: { auth: true },
  },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

router.beforeEach((to) => {
  const token = localStorage.getItem('token')

  if (to.meta.auth && !token) {
    return { name: 'Login' }
  }

  if (to.meta.guest && token) {
    return { path: '/chart' }
  }
})

export default router
