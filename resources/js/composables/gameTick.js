import { onUnmounted, ref } from 'vue';

// One shared 1-second tick, driving every countdown/progress display and periodic background
// sync in the game instead of each page running its own separate setInterval. Components read
// `tickCount` (or use `everyTicks()` below) rather than starting their own timer.
const TICK_MS = 1000;

const tickCount = ref(0);
let intervalId = null;
let subscribers = 0;

function start() {
  if (intervalId) return;
  intervalId = setInterval(() => {
    tickCount.value += 1;
  }, TICK_MS);
}

function stop() {
  clearInterval(intervalId);
  intervalId = null;
  tickCount.value = 0;
}

/** Call from any component's setup(). Starts the shared tick on first use and stops it once the
 * last subscriber unmounts — components never manage their own interval or cleanup. */
export function useGameTick() {
  subscribers += 1;
  start();

  onUnmounted(() => {
    subscribers -= 1;
    if (subscribers <= 0) stop();
  });

  return { tickCount };
}

/** Runs `callback` once every `n` ticks (e.g. `everyTicks(tickCount.value, 5, flushQueue)`). */
export function everyTicks(count, n, callback) {
  if (count > 0 && count % n === 0) callback();
}
