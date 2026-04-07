<script setup>
import { computed } from 'vue'

const props = defineProps({
  alertState: { type: Object, required: true },
})

const emit = defineEmits(['dismiss'])

const isCall = computed(() => props.alertState.type === 'call')
const isPut = computed(() => props.alertState.type === 'put')

const transitionLabel = computed(() => {
  const prev = props.alertState.prevSignal
  const curr = props.alertState.signal
  if (!prev || prev === curr) return ''
  return `${prev}  →  ${curr}`
})

const confidenceLabel = computed(() => {
  const pct = props.alertState.confidence || 0
  if (pct >= 70) return 'STRONG'
  if (pct >= 55) return 'MODERATE'
  if (pct >= 40) return 'WEAK'
  return ''
})
</script>

<template>
  <Transition name="signal-alert">
    <div v-if="alertState.active" class="signal-alert-overlay" @click="emit('dismiss')">
      <div class="signal-alert-card" :class="{ 'alert-call': isCall, 'alert-put': isPut, 'alert-wait': !isCall && !isPut }">
        <!-- Glow ring animation -->
        <div class="alert-glow-ring"></div>
        <div class="alert-glow-ring delay"></div>

        <!-- Top: Signal changed badge -->
        <div class="alert-badge">
          <span class="alert-badge-dot"></span>
          SIGNAL CHANGED
        </div>

        <!-- Main signal -->
        <div class="alert-main">
          <span class="alert-emoji">{{ isCall ? '📈' : isPut ? '📉' : '⏸' }}</span>
          <div class="alert-signal">{{ alertState.signal }}</div>
          <div class="alert-conf">
            <span class="alert-conf-pct">{{ alertState.confidence }}%</span>
            <span class="alert-conf-label">{{ confidenceLabel }}</span>
          </div>
        </div>

        <!-- Transition: WAIT → BUY CALL -->
        <div v-if="transitionLabel" class="alert-transition">{{ transitionLabel }}</div>

        <!-- Reason -->
        <div v-if="alertState.reason" class="alert-reason">{{ alertState.reason }}</div>

        <!-- Dismiss hint -->
        <div class="alert-dismiss">Click anywhere to dismiss</div>
      </div>
    </div>
  </Transition>
</template>

<style scoped>
/* Overlay — covers full viewport with dim backdrop */
.signal-alert-overlay {
  position: fixed;
  inset: 0;
  z-index: 9999;
  display: flex;
  align-items: flex-start;
  justify-content: center;
  padding-top: 60px;
  background: rgba(0, 0, 0, 0.4);
  backdrop-filter: blur(2px);
  cursor: pointer;
  animation: overlayFadeIn 0.3s ease;
}

@keyframes overlayFadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

/* Card */
.signal-alert-card {
  position: relative;
  width: 380px;
  max-width: 90vw;
  padding: 24px 28px 18px;
  border-radius: 16px;
  border: 2px solid;
  text-align: center;
  overflow: hidden;
  animation: cardBounceIn 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
}

@keyframes cardBounceIn {
  from { opacity: 0; transform: scale(0.8) translateY(-30px); }
  to { opacity: 1; transform: scale(1) translateY(0); }
}

.alert-call {
  background: linear-gradient(160deg, #071a0f 0%, #0d2818 50%, #071a0f 100%);
  border-color: rgba(16, 185, 129, 0.5);
  box-shadow: 0 0 40px rgba(16, 185, 129, 0.2), 0 0 80px rgba(16, 185, 129, 0.1);
}

.alert-put {
  background: linear-gradient(160deg, #1a0707 0%, #2a0e0e 50%, #1a0707 100%);
  border-color: rgba(239, 68, 68, 0.5);
  box-shadow: 0 0 40px rgba(239, 68, 68, 0.2), 0 0 80px rgba(239, 68, 68, 0.1);
}

.alert-wait {
  background: linear-gradient(160deg, #0f0d1a 0%, #1a1730 50%, #0f0d1a 100%);
  border-color: rgba(99, 102, 241, 0.4);
  box-shadow: 0 0 40px rgba(99, 102, 241, 0.15);
}

/* Glow ring animation */
.alert-glow-ring {
  position: absolute;
  inset: -2px;
  border-radius: 16px;
  border: 2px solid transparent;
  animation: glowPulse 2s ease-in-out infinite;
  pointer-events: none;
}
.alert-glow-ring.delay {
  animation-delay: 1s;
}

.alert-call .alert-glow-ring {
  border-color: rgba(16, 185, 129, 0.4);
  box-shadow: 0 0 20px rgba(16, 185, 129, 0.15);
}
.alert-put .alert-glow-ring {
  border-color: rgba(239, 68, 68, 0.4);
  box-shadow: 0 0 20px rgba(239, 68, 68, 0.15);
}

@keyframes glowPulse {
  0%, 100% { opacity: 0.3; transform: scale(1); }
  50% { opacity: 1; transform: scale(1.01); }
}

/* Badge */
.alert-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 10px;
  font-weight: 800;
  letter-spacing: 2px;
  color: #fbbf24;
  text-transform: uppercase;
  margin-bottom: 16px;
  animation: badgePulse 1.5s ease-in-out infinite;
}

@keyframes badgePulse {
  0%, 100% { opacity: 0.8; }
  50% { opacity: 1; }
}

.alert-badge-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: #fbbf24;
  animation: dotBlink 1s ease-in-out infinite;
}

@keyframes dotBlink {
  0%, 100% { opacity: 1; box-shadow: 0 0 4px #fbbf24; }
  50% { opacity: 0.3; box-shadow: none; }
}

/* Main signal */
.alert-main {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 14px;
  margin-bottom: 12px;
}

.alert-emoji {
  font-size: 36px;
  animation: emojiPop 0.5s ease 0.2s both;
}

@keyframes emojiPop {
  from { transform: scale(0); }
  50% { transform: scale(1.3); }
  to { transform: scale(1); }
}

.alert-signal {
  font-size: 32px;
  font-weight: 900;
  letter-spacing: 1px;
  line-height: 1;
}

.alert-call .alert-signal { color: #10b981; }
.alert-put .alert-signal { color: #ef4444; }
.alert-wait .alert-signal { color: #818cf8; }

.alert-conf {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
}

.alert-conf-pct {
  font-size: 24px;
  font-weight: 800;
  line-height: 1;
}

.alert-call .alert-conf-pct { color: #10b981; }
.alert-put .alert-conf-pct { color: #ef4444; }

.alert-conf-label {
  font-size: 9px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 1px;
  margin-top: 2px;
}

.alert-call .alert-conf-label { color: #34d399; }
.alert-put .alert-conf-label { color: #f87171; }

/* Transition label */
.alert-transition {
  font-size: 11px;
  font-weight: 600;
  color: #9ca3af;
  margin-bottom: 10px;
  letter-spacing: 0.5px;
}

/* Reason */
.alert-reason {
  font-size: 12px;
  color: #6b7280;
  line-height: 1.5;
  margin-bottom: 12px;
  padding: 0 12px;
}

/* Dismiss hint */
.alert-dismiss {
  font-size: 9px;
  color: #4b5563;
  letter-spacing: 0.5px;
}

/* Vue transition */
.signal-alert-enter-active {
  transition: opacity 0.3s ease;
}
.signal-alert-leave-active {
  transition: opacity 0.4s ease;
}
.signal-alert-enter-from,
.signal-alert-leave-to {
  opacity: 0;
}
</style>
