import { computed, effectScope, ref, watch } from 'vue';
import { useCharacterStore } from '../stores/character';
import { useGameTick } from './gameTick';

// Matches the server's Character::REGEN_TICK_SECONDS — regenPerTick/manaRegenPerTick/energyRegenPerTick
// are amounts per this many seconds, not per single tick.
const RATE_TICK_SECONDS = 5;
// Batched server save/check cadence — persists the client-projected values, doesn't drive the display.
const REGEN_SYNC_TICKS = 5;

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
        hpSeeded = true;
        hpBaseline.value = val;
        hpBaselineAt.value = Date.now();
        hpRatePerSecond.value = (characterStore.regenPerTick ?? 0) / RATE_TICK_SECONDS;
      }, { immediate: true });

      watch(() => characterStore.character?.mana, (val) => {
        if (val == null) return;
        manaSeeded = true;
        manaBaseline.value = val;
        manaBaselineAt.value = Date.now();
        // Mana regen is paused for the whole of an active manual battle (see Character::applyPassiveRegen
        // / CharacterController::saveTick) — only skill/item costs should move it mid-fight, not the
        // passive rate, or the bar visibly climbs back up right after a skill drains it.
        manaRatePerSecond.value = activeBattleId.value != null ? 0 : (characterStore.manaRegenPerTick ?? 0) / RATE_TICK_SECONDS;
      }, { immediate: true });

      watch(() => characterStore.character?.energy, (val) => {
        if (val == null) return;
        energySeeded = true;
        energyBaseline.value = val;
        energyBaselineAt.value = Date.now();
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

      // Entering/leaving a manual battle flips whether mana regen applies at all — rebase from wherever
      // the projection currently sits (same pattern as the rate-change watches above) so the transition
      // doesn't jump, then switch the rate to match the new state.
      watch(activeBattleId, (battleId) => {
        if (!manaSeeded) return;
        manaBaseline.value = projectRegen(manaBaseline.value, manaBaselineAt.value, manaRatePerSecond.value, mpMax.value, clockNow.value);
        manaBaselineAt.value = Date.now();
        manaRatePerSecond.value = battleId != null ? 0 : (characterStore.manaRegenPerTick ?? 0) / RATE_TICK_SECONDS;
      });

      // The tick heartbeat never stops for combat/navigation — energy regens mid-battle too, and this is
      // the one periodic "game save" push, running for as long as any page anywhere is using the shared
      // game tick, regardless of which one is currently mounted. It sends exactly what's on screen right
      // now (see CharacterController::saveTick) rather than letting the server silently recompute and
      // possibly disagree with it.
      watch(tickCount, (count) => {
        clockNow.value = Date.now();
        if (count % REGEN_SYNC_TICKS === 0) {
          characterStore.syncRegen({
            hp: Math.round(displayHp.value),
            mana: Math.round(localMana.value),
            energy: Math.round(localEnergy.value),
            battle_id: activeBattleId.value,
          });
        }
      });
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
