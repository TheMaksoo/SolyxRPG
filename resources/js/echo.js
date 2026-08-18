import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import api from './api/client';

window.Pusher = Pusher;

let echo = null;

function createEcho() {
  return new Echo({
    broadcaster: 'pusher',
    key: import.meta.env.VITE_PUSHER_APP_KEY,
    cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER,
    forceTLS: true,
    // Reuses the same axios instance every other API call uses (cookies + CSRF already configured),
    // instead of Echo's own default XHR-based auth flow — keeps this endpoint authenticated exactly
    // like every other Sanctum-protected route rather than maintaining a second auth mechanism.
    authorizer: (channel) => ({
      authorize: (socketId, callback) => {
        api
          .post('broadcasting/auth', { socket_id: socketId, channel_name: channel.name })
          .then((response) => callback(false, response.data))
          .catch((error) => callback(true, error));
      },
    }),
  });
}

/** Lazily creates the shared connection on first use — call after login (GameLayout's onMounted),
 * not from app.js, so the public landing/login pages never open a socket. */
export function useEcho() {
  if (!echo) echo = createEcho();
  return echo;
}

/** Call from the auth store's logout() so a stale connection carrying the previous user's private
 * channel subscriptions doesn't linger into the next login on a shared device. */
export function disconnectEcho() {
  echo?.disconnect();
  echo = null;
}
