import { onUnmounted, ref } from 'vue';
import api from '../api/client';

// Single shared poll of the lightweight /status/check probe (just IDs/timestamps, see
// StatusController::check) — GameLayout's nav-badge check and WorldChat's new-message check used to
// each run their own independent setInterval against this same endpoint (30s and 5s respectively),
// which meant every player was generating two overlapping polling loops for the exact same data for as
// long as the app was open, regardless of whether anything was actually happening. One shared timer
// here, with both callers watching its result instead of fetching it themselves, means exactly one
// request every POLL_MS no matter how many things care about it.
const POLL_MS = 10000;

const lastMessageId = ref(0);
const badgesUpdatedAt = ref(0);

let intervalId = null;
let subscribers = 0;
let visibilityListenerAttached = false;

async function poll() {
  try {
    const { data } = await api.get('/status/check');
    lastMessageId.value = data.last_message_id ?? 0;
    badgesUpdatedAt.value = data.badges_updated_at ?? 0;
  } catch {
    // Transient network hiccup — keep the current values and try again next tick.
  }
}

function startInterval() {
  if (intervalId) return;
  intervalId = setInterval(poll, POLL_MS);
}

function stopInterval() {
  clearInterval(intervalId);
  intervalId = null;
}

// A backgrounded tab (switched away, minimized, another tab focused) has no UI to update, so there's
// nothing this poll needs to catch in real time — but it used to keep firing every POLL_MS regardless,
// for as long as the tab was merely open, which is a huge share of total traffic given how common it
// is to leave an idle game open in a background tab. Pausing while hidden and polling once immediately
// on return (rather than waiting out a full POLL_MS) keeps the "reasonably fresh on comeback" feel.
function handleVisibilityChange() {
  if (document.hidden) {
    stopInterval();
  } else if (subscribers > 0) {
    poll();
    startInterval();
  }
}

function start() {
  subscribers += 1;
  if (!visibilityListenerAttached) {
    visibilityListenerAttached = true;
    document.addEventListener('visibilitychange', handleVisibilityChange);
  }
  if (document.hidden) return;
  poll();
  startInterval();
}

function stop() {
  subscribers -= 1;
  if (subscribers <= 0) stopInterval();
}

/** Call from any component's setup(). Starts the shared poll on first use, stops it once the last
 * subscriber unmounts — same lifecycle pattern as composables/gameTick.js. */
export function useStatusPoll() {
  start();

  onUnmounted(() => {
    stop();
  });

  return { lastMessageId, badgesUpdatedAt };
}
