import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

window.Pusher = Pusher

const echo = new Echo({
  broadcaster: 'reverb',
  key: import.meta.env.VITE_REVERB_APP_KEY || '7v2cl5nopcua7kcrucpp',
  wsHost: import.meta.env.VITE_REVERB_HOST || 'localhost',
  wsPort: import.meta.env.VITE_REVERB_PORT || 8085,
  wssPort: import.meta.env.VITE_REVERB_PORT || 8085,
  forceTLS: false,
  enabledTransports: ['ws', 'wss'],
  disableStats: true,
})

export default echo
