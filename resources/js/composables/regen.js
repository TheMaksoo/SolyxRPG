import { computed, effectScope, ref, watch } from 'vue';
import { useCharacterStore } from '../stores/character';
import { useGameTick } from './gameTick';

// Matches the server's Character::REGEN_TICK_SECONDS — regenPerTick/manaRegenPerTick/energyRegenPerTick
// are amounts per this many seconds, not per single tick.
const RATE_TICK_SECONDS = 5;

/** Given a known value at a known time plus a steady rate, what should the value be right now? Mirrors
 * the server's own regenResource() — real elapsed time × rate — evaluated client-side every tick instead
 * of once per server request. */
function projectRegen(baseline, baselineAt, ratePerSecond, max, now) {
  if (baseline >= max) return max;
  const elapsedSeconds = Math.max(0, (now - baselineAt) / 1000);
  return Math.min(max, baseline + elapsedSeconds * ratePerSecond);
}

// Module-level singleton state — same shape as gameTick.js's shared tickCount. HP/MP/energy regen is
// the same real quantity everywhere it's shown (Dashboard, Battle), so it's tracked once here rather
// than reset every time whichever page happens to be displaying it mounts/unmounts. Switching between
// pages that both call useRegen() never re-seeds or jumps the projection — only a fresh authoritative
// server value (a real heal, a level up, combat damage) ever moves the baseline.
const hpBaseline = ref(0);
const hpBaselineAt = ref(0);
const hpRatePerSecond = ref(0);

const manaBaseline = ref(0);
const manaBaselineAt = ref(0);
const manaRatePerSecond = ref(0);

const energyBaseline = ref(0);
const energyBaselineAt = ref(0);
const energyRatePerSecond = ref(0);

// battle's own HP counter (Battle::character_hp) — separate from Character::hp because it keeps ticking
// mid-fight too (see CombatService::regenInBattle). null whenever there's no active battle. Living here
// (not just inside BattlePage) is what lets Dashboard show the exact same number Battle shows while
// you're mid-fight, instead of Dashboard's own hp tile sitting frozen on the stale pre-battle value.
const battleHpBaseline = ref(null);
const battleHpBaselineAt = ref(0);
const activeBattleId = ref(null);

const clockNow = ref(Date.now());

let hpSeeded = false;
let manaSeeded = false;
let energySeeded = false;
let watchersStarted = false;

// What the server was last told, so the periodic sync below can skip the request (and the DB write
// it causes server-side) entirely when nothing has actually changed — e.g. a character sitting at
// full HP/mana/energy out of combat, which used to still fire a save-tick every cycle for as long as
// the tab was open. `undefined` (not null/0) on all four so the very first tick always syncs and
// establishes a baseline, matching the old always-sync behavior for that one call.
let lastSyncedHp;
let lastSyncedMana;
let lastSyncedEnergy;
let lastSyncedBattleId;

// Last known-good effective max, held onto rather than falling back to a differently-scaled number
// (the raw hp_max/mana_max/energy_max columns exclude attribute points and gear bonuses) whenever
// characterStore.stats momentarily lags behind something else (e.g. starting a new battle doesn't
// itself refresh stats) — falling back to a smaller/larger number for one render is exactly what
// produces a visible HP/MP jump that doesn't match what was just shown.
let lastKnownHpMax = 1;
let lastKnownMpMax = 1;
let lastKnownEnergyMax = 1;

/** Called by BattlePage whenever battle.character_hp changes during an active fight. The battle id
 * travels with it so the shared save-tick push (below) knows to persist this as the battle's own HP
 * counter rather than Character::hp. */
function setBattleHp(battleId, hp) {
  activeBattleId.value = battleId;
  battleHpBaseline.value = hp;
  battleHpBaselineAt.value = Date.now();
}
/** Called by BattlePage once there's no active battle (fight resolved, or none in progress) — falls
 * back to displayHp reading from the out-of-combat projection again. */
function clearBattleHp() {
  activeBattleId.value = null;
  battleHpBaseline.value = null;
}

/** Shared HP/MP/energy regen projection. Call from any page that displays these — the underlying state
 * is a singleton, so multiple simultaneous callers (or navigating back and forth between them) all read
 * the exact same continuously-projected numbers instead of each maintaining its own. */
export function useRegen() {
  const characterStore = useCharacterStore();
  const { tickCount } = useGameTick();

  const hpMax = computed(() => {
    if (characterStore.stats?.eff_hp_max) lastKnownHpMax = characterStore.stats.eff_hp_max;
    return lastKnownHpMax;
  });
  const mpMax = computed(() => {
    if (characterStore.stats?.eff_mp_max) lastKnownMpMax = characterStore.stats.eff_mp_max;
    return lastKnownMpMax;
  });
  const energyMax = computed(() => {
    if (characterStore.stats?.eff_energy_max) lastKnownEnergyMax = characterStore.stats.eff_energy_max;
    return lastKnownEnergyMax;
  });

  if (!watchersStarted) {
    watchersStarted = true;

    // Vue ties watch() to whichever component happens to be executing setup() at the time it's called —
    // without a detached scope, these singleton watchers would belong to whichever page first called
    // useRegen() and DIE the moment that page unmounts (Vue auto-stops a component's effects on unmount),
    // permanently silencing every baseline reseed/regen-sync for the rest of the session even though
    // watchersStarted stays true forever. effectScope(true) makes them independent of any one caller,
    // matching gameTick.js's own module-level singleton (which sidesteps the same trap with a plain
    // setInterval instead of a Vue watch).
    effectScope(true).run(() => {
      watch(() => characterStore.character?.hp, (val) => {
        if (val == null) return;
        // Only reseed the projection baseline when the incoming value differs meaningfully from what
        // we're already projecting — a difference of more than 1 avoids re-anchoring on every periodic
        // sync-tick echo (which sends Math.round of the projection back and may receive ±1 due to
        // server-side rounding) while still immediately anchoring on any real change (damage taken,
        // potion used, level-up HP refill, etc.).
        const projected = hpSeeded
          ? Math.round(projectRegen(hpBaseline.value, hpBaselineAt.value, hpRatePerSecond.value, lastKnownHpMax, Date.now()))
          : -Infinity;
        if (Math.abs(val - projected) > 1) {
          hpBaseline.value = val;
          hpBaselineAt.value = Date.now();
        }
        hpSeeded = true;
        hpRatePerSecond.value = (characterStore.regenPerTick ?? 0) / RATE_TICK_SECONDS;
      }, { immediate: true });

      watch(() => characterStore.character?.mana, (val) => {
        if (val == null) return;
        // Same echo-skip as the HP watcher above — prevents a visible mana-bar dip every sync cycle
        // when the server echoes back the rounded integer the client already sent.
        // Mana keeps regenerating at the same rate during an active manual battle too — mirrors
        // CombatService::regenInBattle(), which credits mana (same manaRegenPerTick() formula, same
        // 5s tick) every time a battle-related request comes in. Only the *passive* background regen
        // (Character::applyPassiveRegen/saveTick) skips mana during battle, specifically to avoid
        // double-crediting the same real elapsed time through both mechanisms — this projection is
        // simulating regenInBattle's rate, not the passive one, so it must not zero out here.
        const newRate = (characterStore.manaRegenPerTick ?? 0) / RATE_TICK_SECONDS;
        const projected = manaSeeded
          ? Math.round(projectRegen(manaBaseline.value, manaBaselineAt.value, manaRatePerSecond.value, lastKnownMpMax, Date.now()))
          : -Infinity;
        if (Math.abs(val - projected) > 1) {
          manaBaseline.value = val;
          manaBaselineAt.value = Date.now();
        }
        manaSeeded = true;
        manaRatePerSecond.value = newRate;
      }, { immediate: true });

      watch(() => characterStore.character?.energy, (val) => {
        if (val == null) return;
        // Same echo-skip as above.
        const projected = energySeeded
          ? Math.round(projectRegen(energyBaseline.value, energyBaselineAt.value, energyRatePerSecond.value, lastKnownEnergyMax, Date.now()))
          : -Infinity;
        if (Math.abs(val - projected) > 1) {
          energyBaseline.value = val;
          energyBaselineAt.value = Date.now();
        }
        energySeeded = true;
        energyRatePerSecond.value = (characterStore.energyRegenPerTick ?? 0) / RATE_TICK_SECONDS;
      }, { immediate: true });

      // A rate change (VIP buff, level up, attribute point spent) rebases from wherever the projection
      // currently sits, so the new rate takes effect immediately instead of waiting on the next hp/mana/
      // energy change to notice it.
      watch(() => characterStore.regenPerTick, (rate) => {
        if (!hpSeeded) return;
        hpBaseline.value = projectRegen(hpBaseline.value, hpBaselineAt.value, hpRatePerSecond.value, hpMax.value, clockNow.value);
        hpBaselineAt.value = Date.now();
        hpRatePerSecond.value = (rate ?? 0) / RATE_TICK_SECONDS;
      });
      watch(() => characterStore.manaRegenPerTick, (rate) => {
        if (!manaSeeded) return;
        manaBaseline.value = projectRegen(manaBaseline.value, manaBaselineAt.value, manaRatePerSecond.value, mpMax.value, clockNow.value);
        manaBaselineAt.value = Date.now();
        manaRatePerSecond.value = (rate ?? 0) / RATE_TICK_SECONDS;
      });
      watch(() => characterStore.energyRegenPerTick, (rate) => {
        if (!energySeeded) return;
        energyBaseline.value = projectRegen(energyBaseline.value, energyBaselineAt.value, energyRatePerSecond.value, energyMax.value, clockNow.value);
        energyBaselineAt.value = Date.now();
        energyRatePerSecond.value = (rate ?? 0) / RATE_TICK_SECONDS;
      });

      // Drives the continuous display projection only now — the periodic server sync this used to also
      // trigger every 15 ticks is gone (see trySync below); this just keeps clockNow current every second
      // so localHp/localMana/localEnergy/displayHp keep animating regardless of when the last sync fired.
      watch(tickCount, () => {
        clockNow.value = Date.now();
      });

      // Persists exactly what's on screen right now (see CharacterController::saveTick) — but only when
      // the player is actually leaving (switching tabs, minimizing, closing), not on a timer. Closing a
      // tab fires 'visibilitychange' (hidden) before 'pagehide' in every modern browser, so the hidden
      // check below already covers the close case; pagehide is kept as a cheap best-effort second chance
      // in case that ordering doesn't hold for some reason. Trades a periodic request for a real risk: a
      // crash or force-quit between syncs loses that stretch of regen until the next real action recomputes
      // it server-side (Character::applyPassiveRegen runs independently on every real action regardless of
      // this — this sync only keeps the *stored* number close to what was displayed, it was never the
      // source of gameplay truth).
      function trySync() {
        const hp = Math.round(displayHp.value);
        const mana = Math.round(localMana.value);
        const energy = Math.round(localEnergy.value);
        const battleId = activeBattleId.value;
        const dirty = hp !== lastSyncedHp || mana !== lastSyncedMana
          || energy !== lastSyncedEnergy || battleId !== lastSyncedBattleId;
        if (!dirty) return;
        lastSyncedHp = hp;
        lastSyncedMana = mana;
        lastSyncedEnergy = energy;
        lastSyncedBattleId = battleId;
        characterStore.syncRegen({ hp, mana, energy, battle_id: battleId });
      }

      document.addEventListener('visibilitychange', () => {
        if (document.hidden) trySync();
      });
      window.addEventListener('pagehide', trySync);
    });
  }

  const localHp = computed(() => projectRegen(hpBaseline.value, hpBaselineAt.value, hpRatePerSecond.value, hpMax.value, clockNow.value));
  const localMana = computed(() => projectRegen(manaBaseline.value, manaBaselineAt.value, manaRatePerSecond.value, mpMax.value, clockNow.value));
  const localEnergy = computed(() => projectRegen(energyBaseline.value, energyBaselineAt.value, energyRatePerSecond.value, energyMax.value, clockNow.value));

  const projectedBattleHp = computed(() => (
    battleHpBaseline.value == null
      ? null
      : projectRegen(battleHpBaseline.value, battleHpBaselineAt.value, hpRatePerSecond.value, hpMax.value, clockNow.value)
  ));

  /** The one HP number every page should show — prefers the live in-combat projection whenever
   * BattlePage has reported an active battle (via setBattleHp), otherwise the out-of-combat projection.
   * Both Dashboard and Battle read this exact same value, so they can never show two different numbers
   * for "your current HP" at the same moment. */
  const displayHp = computed(() => projectedBattleHp.value ?? localHp.value);

  const isRegeneratingHp = computed(() => Math.round(displayHp.value) < hpMax.value);
  const isRegeneratingMana = computed(() => Math.round(localMana.value) < mpMax.value);
  const isRegeneratingEnergy = computed(() => Math.round(localEnergy.value) < energyMax.value);

  const rawPct = (value, max) => (max > 0 ? Math.max(0, Math.min(100, (value / max) * 100)) : 0);
  const hpBarPct = computed(() => rawPct(displayHp.value, hpMax.value));
  const mpBarPct = computed(() => rawPct(localMana.value, mpMax.value));
  const energyBarPct = computed(() => rawPct(localEnergy.value, energyMax.value));

  return {
    hpMax, mpMax, energyMax,
    localHp, localMana, localEnergy, displayHp,
    setBattleHp, clearBattleHp,
    isRegeneratingHp, isRegeneratingMana, isRegeneratingEnergy,
    hpBarPct, mpBarPct, energyBarPct,
    clockNow,
    projectRegen,
    hpRatePerSecond,
  };
}
