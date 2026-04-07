import { ref, watch, computed } from 'vue'

/**
 * Signal Alert System — detects when BUY CALL / BUY PUT changes
 * and triggers visual + audio alerts to grab user attention.
 *
 * Usage:
 *   const { alertState, dismissAlert } = useSignalAlert(confluenceRef)
 *
 * alertState = {
 *   active: boolean,      // true when an alert is showing
 *   signal: string,       // 'BUY CALL' | 'BUY PUT' | 'WAIT'
 *   prevSignal: string,   // what it changed from
 *   confidence: number,   // 0-100
 *   reason: string,       // plain english
 *   type: 'call' | 'put' | 'wait',
 *   timestamp: number,    // Date.now() when alert fired
 * }
 */
export function useSignalAlert(confluenceRef, options = {}) {
  const {
    soundEnabled = true,
    autoDismissMs = 12000,      // auto-dismiss after 12s
    cooldownMs = 15000,         // don't re-alert same signal within 15s
    onlyActionable = true,      // only alert on BUY CALL / BUY PUT (not WAIT)
  } = options

  const alertState = ref({
    active: false,
    signal: null,
    prevSignal: null,
    confidence: 0,
    reason: '',
    type: null,
    timestamp: 0,
  })

  // Track the glow state for the signal card (separate from toast)
  const signalGlow = ref(false)

  let prevCallPut = null
  let lastAlertTime = 0
  let dismissTimer = null
  let glowTimer = null

  // --- Sound ---
  let audioCtx = null
  function playAlertSound(type) {
    if (!soundEnabled) return
    try {
      if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)()
      const osc = audioCtx.createOscillator()
      const gain = audioCtx.createGain()
      osc.connect(gain)
      gain.connect(audioCtx.destination)

      if (type === 'call') {
        // Rising tone — bullish
        osc.type = 'sine'
        osc.frequency.setValueAtTime(600, audioCtx.currentTime)
        osc.frequency.linearRampToValueAtTime(900, audioCtx.currentTime + 0.15)
        gain.gain.setValueAtTime(0.15, audioCtx.currentTime)
        gain.gain.linearRampToValueAtTime(0, audioCtx.currentTime + 0.4)
        osc.start(audioCtx.currentTime)
        osc.stop(audioCtx.currentTime + 0.4)

        // Second chirp
        setTimeout(() => {
          const osc2 = audioCtx.createOscillator()
          const gain2 = audioCtx.createGain()
          osc2.connect(gain2)
          gain2.connect(audioCtx.destination)
          osc2.type = 'sine'
          osc2.frequency.setValueAtTime(900, audioCtx.currentTime)
          osc2.frequency.linearRampToValueAtTime(1100, audioCtx.currentTime + 0.12)
          gain2.gain.setValueAtTime(0.12, audioCtx.currentTime)
          gain2.gain.linearRampToValueAtTime(0, audioCtx.currentTime + 0.3)
          osc2.start(audioCtx.currentTime)
          osc2.stop(audioCtx.currentTime + 0.3)
        }, 200)
      } else if (type === 'put') {
        // Falling tone — bearish
        osc.type = 'sine'
        osc.frequency.setValueAtTime(800, audioCtx.currentTime)
        osc.frequency.linearRampToValueAtTime(500, audioCtx.currentTime + 0.15)
        gain.gain.setValueAtTime(0.15, audioCtx.currentTime)
        gain.gain.linearRampToValueAtTime(0, audioCtx.currentTime + 0.4)
        osc.start(audioCtx.currentTime)
        osc.stop(audioCtx.currentTime + 0.4)

        setTimeout(() => {
          const osc2 = audioCtx.createOscillator()
          const gain2 = audioCtx.createGain()
          osc2.connect(gain2)
          gain2.connect(audioCtx.destination)
          osc2.type = 'sine'
          osc2.frequency.setValueAtTime(500, audioCtx.currentTime)
          osc2.frequency.linearRampToValueAtTime(350, audioCtx.currentTime + 0.12)
          gain2.gain.setValueAtTime(0.12, audioCtx.currentTime)
          gain2.gain.linearRampToValueAtTime(0, audioCtx.currentTime + 0.3)
          osc2.start(audioCtx.currentTime)
          osc2.stop(audioCtx.currentTime + 0.3)
        }, 200)
      }
    } catch { /* audio not available */ }
  }

  // --- Alert trigger ---
  function triggerAlert(newSignal, prevSignal, confidence, reason) {
    const now = Date.now()
    if (now - lastAlertTime < cooldownMs) return
    lastAlertTime = now

    const type = newSignal === 'BUY CALL' ? 'call' : newSignal === 'BUY PUT' ? 'put' : 'wait'

    alertState.value = {
      active: true,
      signal: newSignal,
      prevSignal: prevSignal,
      confidence: confidence || 0,
      reason: reason || '',
      type,
      timestamp: now,
    }

    // Glow the signal card
    signalGlow.value = true
    clearTimeout(glowTimer)
    glowTimer = setTimeout(() => { signalGlow.value = false }, 6000)

    // Play sound
    playAlertSound(type)

    // Auto-dismiss toast
    clearTimeout(dismissTimer)
    dismissTimer = setTimeout(() => {
      dismissAlert()
    }, autoDismissMs)
  }

  function dismissAlert() {
    alertState.value = { ...alertState.value, active: false }
    clearTimeout(dismissTimer)
  }

  // --- Watch confluence for changes ---
  watch(confluenceRef, (newConf) => {
    if (!newConf) return
    const newCallPut = newConf.callPut || 'WAIT'

    // First load — just record, don't alert
    if (prevCallPut === null) {
      prevCallPut = newCallPut
      return
    }

    // No change
    if (newCallPut === prevCallPut) return

    // Signal changed!
    const prev = prevCallPut
    prevCallPut = newCallPut

    // If onlyActionable, skip WAIT transitions
    if (onlyActionable && newCallPut === 'WAIT') return

    triggerAlert(newCallPut, prev, newConf.adjustedPct, newConf.userReason)
  }, { deep: true })

  return {
    alertState,
    signalGlow,
    dismissAlert,
    triggerAlert, // manual trigger for testing
  }
}
