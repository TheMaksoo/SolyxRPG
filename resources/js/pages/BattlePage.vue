<script setup>
import { ref, onMounted, computed, watch } from 'vue';
import api from '../api/client';
import { useCharacterStore } from '../stores/character';
import { useAuthStore } from '../stores/auth';
import { useAutoBattleStore } from '../stores/autoBattle';
import { useGameTick } from '../composables/gameTick';
import { useRegen } from '../composables/regen';
import AdBanner from '../components/AdBanner.vue';
import WorldChat from '../components/WorldChat.vue';
import Skeleton from '../components/Skeleton.vue';

const { tickCount } = useGameTick();
const AUTO_BATTLE_POLL_TICKS = 20;

const characterStore = useCharacterStore();
const auth = useAuthStore();
// The topbar's Auto-Battle pill (see stores/autoBattle.js + GameLayout.vue) polls independently, but
// buying/refreshing here shouldn't make it wait up to 20s to notice — nudge it to refresh right away.
const autoBattleStore = useAutoBattleStore();
const battle = ref(null);
const result = ref(null);
const loading = ref(true);
const error = ref('');
const notice = ref('');
const dungeonRun = ref(null);
const battleLogExpanded = ref(false);
const rewardBreakdownExpanded = ref(false);

const autoBattle = ref({ active: false, seconds_remaining: 0, costs: {}, gems: 0 });
const autoBattleMessage = ref('');
const autoBattleSummary = ref(null);

// Counts completed rounds in the current fight — reset on a fresh encounter, +1 per action taken.
// Battle itself doesn't persist a round number (log_json is lines, not rounds), so this is tracked
// client-side against the same events that already drive everything else.
const roundCount = ref(0);

function formatDuration(totalSeconds) {
  const m = Math.floor(totalSeconds / 60);
  const s = totalSeconds % 60;
  return `${m}:${String(s).padStart(2, '0')}`;
}

async function loadAutoBattle() {
  const { data } = await api.get('/auto-battle');
  autoBattle.value = data;
  if (data.summary) {
    autoBattleSummary.value = data.summary;
    characterStore.fetch();
  }
  // Feeds the topbar's Auto-Battle pill (see stores/autoBattle.js) with data this page already fetched —
  // that store never hits /auto-battle itself, since reading it also ticks fights forward server-side.
  autoBattleStore.setExpiresAt(data.active ? data.expires_at : null);
}

async function buyAutoBattleCash(sku) {
  try {
    const { data } = await api.post('/store/checkout', { sku });
    window.location.href = data.checkout_url;
  } catch (e) {
    autoBattleMessage.value = e.response?.data?.message || 'Checkout unavailable.';
  }
}

async function buyAutoBattle(minutes) {
  try {
    const { data } = await api.post('/auto-battle/purchase', { minutes });
    autoBattle.value = { ...autoBattle.value, active: true, seconds_remaining: data.seconds_remaining, gems: data.gems };
    autoBattleMessage.value = `Training started — ${minutes} minutes added.`;
    characterStore.fetch();
    autoBattleStore.setExpiresAt(data.expires_at);
  } catch (e) {
    autoBattleMessage.value = e.response?.data?.message || 'Could not start training.';
  }
}

const monster = computed(() => battle.value?.monster ?? null);
const hpPct = (hp, max) => (max > 0 ? Math.max(0, Math.min(100, Math.round((hp / max) * 100))) : 0);

// Shared HP/MP/energy regen projection (composables/regen.js) — same singleton state Dashboard reads,
// so the two pages never disagree and switching between them never resets or re-seeds the projection.
// displayHp already prefers the live in-combat projection once setBattleHp has been called below, so
// Dashboard's HP tile shows that exact same number while you're mid-fight instead of sitting frozen on
// the stale pre-battle value. hpMax/mpMax also come from here rather than being recomputed locally, so
// there's exactly one cached "last known effective max" instead of two that could drift apart.
const { displayHp, localMana, setBattleHp, clearBattleHp, hpBarPct, mpBarPct, hpMax: playerHpMax, mpMax: playerMpMax } = useRegen();

// Battle tracks its own character_hp snapshot, separate from Character::hp, because HP regen keeps
// ticking mid-fight too (see CombatService::regenInBattle — same real-elapsed-time × regenPerTick()
// formula as out-of-combat regen, just applied to the battle's own counter instead of the character
// record). Feed it into the shared composable rather than projecting it locally, so every page reading
// displayHp (not just this one) sees the live in-combat value.
watch(() => (battle.value?.status === 'active' ? battle.value.character_hp : null), (hp) => {
  if (hp == null) {
    clearBattleHp();
  } else {
    setBattleHp(battle.value.id, hp);
  }
}, { immediate: true });

const displayCharacterHp = computed(() => Math.round(displayHp.value));
const displayCharacterMana = computed(() => Math.round(localMana.value));
const isRegeneratingHp = computed(() => displayCharacterHp.value < playerHpMax.value);
const isRegeneratingMana = computed(() => displayCharacterMana.value < playerMpMax.value);

const currentZoneName = computed(() => characterStore.character?.zone?.name ?? 'the wilds');

// Re-check durability when character inventory changes
watch(() => characterStore.character?.inventory, () => {
  if (battle.value) {
    const hasLowDurability = checkLowDurabilityGear();
    // Clear notice if no low durability and current notice is a durability warning
    if (!hasLowDurability && notice.value && (notice.value.includes('BROKEN') || notice.value.includes('Low durability'))) {
      notice.value = '';
    }
  }
}, { deep: true });

// "Adds" fighting alongside the primary monster in a multi-enemy boss encounter — empty for a normal 1v1
// fight, so none of this UI appears unless a dungeon boss actually brought friends.
const extraMonsters = computed(() => battle.value?.battle_monsters ?? []);
const hasAdds = computed(() => extraMonsters.value.length > 0);

// null = primary boss (the default target for attack / single-target skills).
const selectedTargetId = ref(null);

function selectTarget(id) {
  selectedTargetId.value = selectedTargetId.value === id ? null : id;
}

// If the selected add died (or a new battle/stage started), fall back to the primary boss rather than
// keep sending a dead/foreign target id.
watch(extraMonsters, (rows) => {
  if (selectedTargetId.value && !rows.some((m) => m.id === selectedTargetId.value && m.hp > 0)) {
    selectedTargetId.value = null;
  }
});

// Every unlocked ACTIVE skill (mp_cost > 0) gets its own action button — passives (mp_cost 0, e.g. Power
// Strike) are always-on stat boosts folded into effectiveStats(), not something you "cast" in battle.
const activeSkillRows = computed(() => (characterStore.character?.skills || []).filter((row) => (row.skill?.mp_cost ?? 0) > 0));

// Rounds remaining per skill id, scoped to the current battle (Battle::skill_cooldowns_json) — turn-based,
// not a wall-clock timer, so it only ever changes when the server sends back an updated `battle` after an
// action (no local ticking needed, unlike the old seconds-based version).
const skillCooldowns = computed(() => battle.value?.skill_cooldowns_json ?? {});
// Every usable-in-battle consumable (heal, mana, or the elixir ATK buff) with stock — not just the
// single auto-picked "best" potion, so you can choose e.g. a mana potion over a health potion, or save
// your Phoenix Elixir for later. Using one is a free action (see CombatService::act()'s $type==='item'
// branch) — it doesn't end your turn.
const usableConsumables = computed(() => {
  const inv = characterStore.character?.inventory ?? [];
  return inv.filter((i) => {
    const stats = i.item?.stat_json ?? {};
    return i.item?.type === 'consumable' && i.qty > 0 && (
      stats.heal_hp_pct ||
      stats.heal_mp_pct ||
      stats.heal_hp_flat ||
      stats.heal_mp_flat ||
      stats.atk_pct_buff
    );
  });
});

// The battle actions grid used to render one button per usable potion, which crowded the grid fast on
// a phone once a player was carrying 4-5 different consumables. One "Consumables" button opens a picker
// grid instead — using an item from it is still the same free action (act('item', ...) doesn't end your
// turn, see CombatService::act()'s $type==='item' branch).
const consumableGridOpen = ref(false);

function useConsumable(row) {
  consumableGridOpen.value = false;
  act('item', { item_id: row.item_id });
}

function consumableLabel(row) {
  const stats = row.item?.stat_json ?? {};
  const parts = [];
  if (stats.heal_hp_flat) parts.push(`+${stats.heal_hp_flat} HP`);
  if (stats.heal_mp_flat) parts.push(`+${stats.heal_mp_flat} MP`);
  if (stats.heal_hp_pct) parts.push(`+${stats.heal_hp_pct}% HP`);
  if (stats.heal_mp_pct) parts.push(`+${stats.heal_mp_pct}% MP`);
  if (stats.atk_pct_buff) parts.push(`+${stats.atk_pct_buff}% ATK`);
  return `${row.item.glyph || '🧪'} ${row.item.name} (${parts.join(', ')})`;
}

const LOG_COLORS = [
  { match: /critical/i, color: '#eab308' },
  { match: /regenerates \d+ hp/i, color: '#4ade80' },
  { match: /defeated|fled/i, color: '#4ade80' },
  { match: /dodge|undying/i, color: '#5cc7f5' },
  { match: /hits you|were defeated|uses .+ for \d+/i, color: '#ff6a4d' },
];
function logColor(line) {
  return LOG_COLORS.find((l) => l.match.test(line))?.color ?? 'rgba(255,255,255,.7)';
}

// Condensed view (opt-in via Settings > Preferences): rather than every per-round line, show only the
// outcome/reward line (per CombatService::simulate()'s log format — "Defeated ... +Ng +Nxp", "You were
// defeated and revived...", or "You fled the battle...") plus a round-count summary — the full log
// (fullBattleLog) stays available underneath either way.
const fullBattleLog = computed(() => battle.value?.log_json || []);
const compactBattleLog = computed(() => {
  const log = battle.value?.log_json || [];
  const keyLines = log.filter((line) => /^Defeated |^You were defeated|^You fled the battle/i.test(line));
  return [...keyLines, `${log.length} round${log.length === 1 ? '' : 's'} total`];
});
const displayedBattleLog = computed(() =>
  auth.user?.preferences?.compact_battle_log ? compactBattleLog.value : fullBattleLog.value
);

const GRADE_META = {
  common: { label: 'Common', color: '#cbd5e1' },
  elite: { label: 'Elite', color: '#5cc7f5' },
  champion: { label: 'Champion', color: '#a78bfa' },
  legendary: { label: 'Legendary', color: '#eab308' },
};
const gradeMeta = computed(() => GRADE_META[battle.value?.grade] ?? GRADE_META.common);
const monsterHpMax = computed(() => battle.value?.monster_hp_max ?? monster.value?.hp ?? 0);

/** Monster-identity rank badge (fixed per monster) — distinct from the per-encounter grade roll above. */
const RANK_META = {
  boss: { label: '👑 Boss', color: '#f97316' },
  elite: { label: '⭐ Elite', color: '#22d3ee' },
};
const rankMeta = computed(() => {
  if (monster.value?.is_boss) return RANK_META.boss;
  if (monster.value?.is_elite) return RANK_META.elite;
  return null;
});

const resultIcon = computed(() => (result.value?.outcome === 'won' ? '🏆' : '💀'));
const resultTitle = computed(() => (result.value?.outcome === 'won' ? 'Victory!' : 'Defeated'));
const resultColor = computed(() => (result.value?.outcome === 'won' ? '#4ade80' : '#ff6a4d'));

const playerAtk = computed(() => characterStore.stats?.eff_atk ?? 0);
const playerDef = computed(() => characterStore.stats?.eff_def ?? 0);

const phaseLabel = computed(() => {
  if (!battle.value) return 'Idle';
  if (battle.value.status !== 'active') return result.value?.outcome === 'won' ? 'Victory' : 'Defeated';
  return 'In combat';
});
const phaseDotColor = computed(() => {
  if (battle.value?.status === 'active') return '#eab308';
  if (result.value?.outcome === 'won') return '#4ade80';
  if (result.value) return '#e8482f';
  return 'rgba(255,255,255,.25)';
});

// Same primary button throughout (see template) — only its label/icon change with context, so
// starting the next fight never means hunting for a differently-labeled control.
const primaryLabel = computed(() => {
  if (battle.value?.status === 'active') return '⚔ Attack';
  if (result.value?.outcome === 'won') return '↺ Fight again';
  if (result.value?.outcome === 'lost') return '♥ Continue';
  return '🚶 Walk';
});

/** Blue/violet/green tone per skill effect — matches the reward/consumable color language elsewhere
 * (heal = green, AOE = violet, plain damage = blue) instead of every skill button looking identical. */
function skillTone(skill) {
  if (skill.effect_json?.heal_hp_pct || skill.effect_json?.heal_hp_flat) return 'green';
  if (skill.effect_json?.aoe) return 'violet';
  return 'blue';
}

/** Real quick links to jump to after a fight resolves — not fictional "same foe" replays, just the
 * places a player actually goes right after combat. Shown alongside the full-width Walk button. */
const postBattleTiles = computed(() => {
  const tiles = [
    { to: '/dashboard', glyph: '⌂', name: 'Dashboard', meta: 'Overview', tone: 'neutral' },
    { to: '/inventory', glyph: '▦', name: 'Inventory', meta: 'Gear & loot', tone: 'violet' },
    { to: '/quests', glyph: '✎', name: 'Quests', meta: 'Track progress', tone: 'green' },
    { to: '/shop', glyph: '◉', name: 'Shop', meta: 'Buy & sell', tone: 'neutral' },
  ];
  if (result.value?.leveled_up) {
    tiles.unshift({ to: '/skills', glyph: '🌟', name: 'Skill Tree', meta: 'Spend points', tone: 'red' });
  }
  return tiles;
});

async function resumeOrBrowse() {
  loading.value = true;
  try {
    // Ensure character data with inventory is loaded
    if (!characterStore.character?.inventory) {
      await characterStore.fetch();
    }

    const { data } = await api.get('/battle/active');
    if (data.battle) {
      battle.value = data.battle;
      dungeonRun.value = data.dungeon_run;
      // No exact round count is persisted server-side (log_json is lines, not rounds) — this is the
      // closest available approximation for a fight resumed mid-way rather than started fresh here.
      roundCount.value = data.battle.log_json?.length ?? 0;

      // Check for low durability gear (returns true if warning was set)
      const hasLowDurability = checkLowDurabilityGear();

      // Only show dungeon/battle notice if no durability warning
      if (!hasLowDurability) {
        notice.value = dungeonRun.value
          ? `Resumed your dungeon run — stage ${dungeonRun.value.stage} / ${dungeonRun.value.total_stages}.`
          : 'Resumed your battle in progress.';
      }
    }
  } finally {
    loading.value = false;
  }
}

function checkLowDurabilityGear() {
  if (!characterStore.character?.inventory) {
    return false;
  }

  const equippedGear = characterStore.character.inventory.filter(inv => inv.equipped && inv.durability_max);

  const brokenGear = [];
  const lowDurabilityGear = [];

  equippedGear.forEach(inv => {
    const pct = (inv.durability / inv.durability_max) * 100;

    if (pct === 0) {
      brokenGear.push(inv);
    } else if (pct <= 20) {
      lowDurabilityGear.push(inv);
    }
  });

  if (brokenGear.length > 0 || lowDurabilityGear.length > 0) {
    let warning = '⚠️ ';

    if (brokenGear.length > 0) {
      const brokenNames = brokenGear.map(inv => inv.item.name).join(', ');
      warning += `BROKEN: ${brokenNames}`;
    }

    if (lowDurabilityGear.length > 0) {
      if (brokenGear.length > 0) warning += ' | ';
      const lowNames = lowDurabilityGear.map(inv => inv.item.name).join(', ');
      warning += `Low durability: ${lowNames}`;
    }

    warning += '. Visit Inventory to repair.';

    notice.value = warning;
    return true;
  }
  return false;
}

async function walk() {
  error.value = '';
  loading.value = true;
  try {
    // Ensure character data with inventory is loaded
    if (!characterStore.character?.inventory) {
      await characterStore.fetch();
    }

    const { data } = await api.post('/battle/walk');
    battle.value = data.battle;
    result.value = null;
    dungeonRun.value = null;
    roundCount.value = 0;

    // Check for low durability gear (returns true if warning was set)
    const hasLowDurability = checkLowDurabilityGear();

    // Only show grade notice if no durability warning
    if (!hasLowDurability) {
      notice.value = data.grade?.label && data.grade.label !== 'Common' ? `You encounter a ${data.grade.label} foe!` : '';
    }
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not find an enemy.';
  } finally {
    loading.value = false;
  }
}

async function act(type, extra = {}) {
  if (!battle.value || loading.value) return;
  loading.value = true;
  error.value = '';
  try {
    const { data } = await api.post(`/battle/${battle.value.id}/action`, { type, ...extra });
    const updatedCharacter = data.result?.character ?? data.character;
    const updatedStats = data.result?.stats ?? data.stats;
    if (updatedCharacter) {
      characterStore.character = updatedCharacter;
    }
    if (updatedStats) {
      characterStore.stats = updatedStats;
    }

    // Items are a free action (see CombatService::act()'s $type==='item' branch) — they don't end
    // the turn, so they shouldn't advance the round counter either.
    if (type !== 'item') roundCount.value += 1;

    if (data.dungeon_run?.next_battle) {
      dungeonRun.value = data.dungeon_run;
      battle.value = data.dungeon_run.next_battle;
      result.value = null;
      roundCount.value = 0;
      notice.value = `Stage cleared! Entering stage ${data.dungeon_run.stage} / ${data.dungeon_run.total_stages}.`;
    } else {
      battle.value = data.battle;
      result.value = data.result;
      if (data.dungeon_run) {
        dungeonRun.value = data.dungeon_run;
      }
    }

    // Fetch fresh character data to ensure dashboard XP and all stats update
    if (data.result && (data.result.outcome === 'won' || data.result.outcome === 'lost')) {
      characterStore.fetch();
    }
  } catch (e) {
    error.value = e.response?.data?.message || 'Action failed.';
    if (e.response?.status === 403 || e.response?.status === 404) {
      battle.value = null;
      result.value = null;
      dungeonRun.value = null;
    }
  } finally {
    loading.value = false;
  }
}

async function flee() {
  if (!battle.value || loading.value) return;
  loading.value = true;
  error.value = '';
  try {
    const { data } = await api.post(`/battle/${battle.value.id}/action`, { type: 'flee' });
    const hpLost = data.result?.hp_lost ?? 0;
    notice.value = hpLost > 0 ? `You fled the battle, losing ${hpLost} HP on the way out.` : 'You fled the battle.';
    if (data.character) characterStore.character = data.character;
    if (data.stats) characterStore.stats = data.stats;
    battle.value = null;
    result.value = null;
    dungeonRun.value = null;
    roundCount.value = 0;
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not flee.';
  } finally {
    loading.value = false;
  }
}

// Note: the one periodic "game save" push (HP/MP/energy, including the battle's own HP counter via
// activeBattleId — see setBattleHp above) is entirely driven by useRegen() itself (composables/regen.js).
// This tick watcher only covers what's specific to this page: auto-battle countdown/polling.
watch(tickCount, (count) => {
  if (autoBattle.value.seconds_remaining > 0) autoBattle.value.seconds_remaining -= 1;
  if (count % AUTO_BATTLE_POLL_TICKS === 0) loadAutoBattle();
});

onMounted(() => {
  resumeOrBrowse();
  // Always refreshed (not just when missing) — this is also where regenPerTick/manaRegenPerTick come
  // from, and a stale value from whatever page was visited last is exactly the kind of thing that would
  // make the regen animation silently never fire.
  characterStore.fetch();

  loadAutoBattle();
});
</script>

<template>
  <div>
    <div class="battle-header">
      <div class="battle-header__icon">⚔</div>
      <h1 class="ox battle-title">Battle</h1>
      <span class="battle-header__zone">{{ currentZoneName }}</span>
    </div>

    <div
      v-if="notice && (notice.includes('BROKEN') || notice.includes('Low durability'))"
      class="battle-alert-bar"
      :class="{ 'battle-alert-bar--danger': notice.includes('BROKEN') }"
    >
      <span class="battle-alert-bar__icon">⚠</span>
      <span class="battle-alert-bar__text">{{ notice.replace('⚠️ ', '') }}</span>
      <router-link to="/inventory" class="battle-alert-bar__cta">Repair →</router-link>
    </div>
    <p v-else-if="notice" class="battle-notice">{{ notice }}</p>
    <p v-if="error" class="battle-error">{{ error }}</p>

    <div class="battle-top-row">
      <div v-if="autoBattleSummary" class="claim-summary">
        <div class="claim-summary__header">
          <span class="ox claim-summary__title">🎉 While you were away</span>
          <button class="claim-summary__close" @click="autoBattleSummary = null">✕</button>
        </div>
        <div class="claim-summary__rows">
          <span class="claim-summary__chip">⚔ {{ autoBattleSummary.fights }} fought</span>
          <span class="claim-summary__chip">🏆 {{ autoBattleSummary.wins }} won</span>
          <span v-if="autoBattleSummary.losses" class="claim-summary__chip">💀 {{ autoBattleSummary.losses }} lost</span>
          <span v-if="autoBattleSummary.fled" class="claim-summary__chip">🏃 {{ autoBattleSummary.fled }} fled</span>
          <span class="claim-summary__chip">🪙 +{{ autoBattleSummary.gold }} gold</span>
          <span class="claim-summary__chip">✦ +{{ autoBattleSummary.xp }} xp</span>
          <span v-if="autoBattleSummary.gems" class="claim-summary__chip">💎 +{{ autoBattleSummary.gems }} gems</span>
        </div>
      </div>

      <div class="auto-battle-card">
        <p v-if="autoBattleMessage" class="auto-battle-card__summary">{{ autoBattleMessage }}</p>
        <div v-if="autoBattle.active" class="auto-battle-card__status">
          <span class="auto-battle-card__label">🤖 Training active</span>
          <span class="auto-battle-card__timer">{{ formatDuration(autoBattle.seconds_remaining) }} remaining</span>
        </div>
        <div class="auto-battle-card__buy">
          <span class="auto-battle-card__label">
            {{ autoBattle.active ? '🤖 Buy more time' : '🤖 Training — fights for you, fully risk-free' }}
          </span>
          <div class="auto-battle-card__options">
            <button
              v-for="minutes in [15, 30, 60]"
              :key="minutes"
              class="auto-battle-card__option"
              :disabled="(characterStore.character?.account_gems ?? 0) < (autoBattle.costs[minutes] ?? 0)"
              @click="buyAutoBattle(minutes)"
            >
              {{ minutes }}m · 💎{{ autoBattle.costs[minutes] ?? '—' }}
            </button>
            <button class="auto-battle-card__option auto-battle-card__option--cash" @click="buyAutoBattleCash('auto_battle_60')">
              1h · €0.99
            </button>
            <button class="auto-battle-card__option auto-battle-card__option--cash" @click="buyAutoBattleCash('auto_battle_480')">
              8h · €0.99
            </button>
          </div>
        </div>
      </div>
    </div>

    <div class="battle-layout">
    <div class="battle-main">
    <div v-if="loading && !battle" class="battle-skeleton">
      <Skeleton height="120px" />
      <Skeleton height="220px" />
      <Skeleton height="60px" />
    </div>

    <!-- Fight view — one persistent screen for idle (no enemy yet), active combat, and a just-defeated
    enemy with rewards. Nothing here swaps to a different layout or overlay; only what's inside each
    panel changes. -->
    <div v-else class="fight-view">
      <div class="fight-view__main" :class="{ 'is-loading': loading }">
        <div class="fight-view__header">
          <div class="fight-view__status">
            <span class="fight-view__dot" :style="{ background: phaseDotColor }"></span>
            <span class="fight-view__phase" :style="{ color: phaseDotColor }">{{ phaseLabel }}</span>
            <span v-if="battle" class="fight-view__sep">·</span>
            <span v-if="battle" class="fight-view__round">Round {{ roundCount }}</span>
            <span v-if="dungeonRun" class="fight-view__sep">·</span>
            <span v-if="dungeonRun" class="fight-view__stage">Stage {{ dungeonRun.stage }} / {{ dungeonRun.total_stages }}</span>
          </div>
          <button
            class="flee-btn"
            :class="{ 'flee-btn--hidden': battle?.status !== 'active' }"
            @click="flee"
            :disabled="loading || battle?.status !== 'active'"
          >
            Flee ↩
          </button>
        </div>

        <div
          class="monster-panel"
          :class="{
            'monster-panel--targetable': hasAdds && battle?.status === 'active',
            'monster-panel--selected': hasAdds && battle?.status === 'active' && !selectedTargetId,
            'monster-panel--result': result,
          }"
          @click="hasAdds && battle?.status === 'active' && selectTarget(null)"
        >
          <!-- Same panel, same slot: once the fight resolves, the victory/defeat screen replaces the
          monster display in place rather than appearing as extra content further down the page — the
          rest of the layout (player panel, action grid, log) never shifts. -->
          <template v-if="result">
            <div class="reward-banner__head">
              <span class="reward-banner__icon">{{ resultIcon }}</span>
              <span class="ox reward-banner__title" :style="{ color: resultColor }">{{ resultTitle }}</span>
            </div>

            <div v-if="result.outcome === 'won'" class="reward-chips">
              <div class="reward-chip--gold">+{{ result.gold }} Gold</div>
              <div class="reward-chip--xp">+{{ result.xp }} XP</div>
              <div v-if="result.gems" class="reward-chip--gems">+{{ result.gems }} Gems</div>
              <div v-if="result.leveled_up" class="reward-chip--level">
                Level up! +{{ result.leveled_up * 3 }} attr · +{{ result.leveled_up }} skill pts
              </div>
              <div v-if="dungeonRun?.completed" class="reward-chip--gold">
                Dungeon cleared!<span v-if="dungeonRun.bonus?.gold"> +{{ dungeonRun.bonus.gold }}g</span><span v-if="dungeonRun.bonus?.gems"> +{{ dungeonRun.bonus.gems }} gems</span>
              </div>
            </div>

            <div v-else class="reward-chips">
              <div class="reward-banner__subtitle">Revived at 40% HP.</div>
              <div v-if="result.gold_lost" class="reward-chip--loss">-{{ result.gold_lost }} Gold</div>
              <div v-if="result.xp_lost" class="reward-chip--loss">-{{ result.xp_lost }} XP</div>
              <div v-if="result.levels_lost" class="reward-chip--loss reward-chip--loss-level">
                Lost level{{ result.levels_lost > 1 ? 's' : '' }}! -{{ result.levels_lost * 3 }} attr · -{{ result.levels_lost }} skill pts
              </div>
            </div>

            <!-- Reward breakdown (collapsible) -->
            <div v-if="result.outcome === 'won' && result.breakdown" class="result-reward-breakdown">
              <button class="result-reward-breakdown__toggle" @click="rewardBreakdownExpanded = !rewardBreakdownExpanded">
                {{ rewardBreakdownExpanded ? '▼' : '▶' }} Reward Breakdown
              </button>
              <div v-if="rewardBreakdownExpanded" class="reward-breakdown">
                <div class="reward-breakdown__section">
                  <div class="reward-breakdown__label">🪙 Gold Breakdown</div>
                  <div class="reward-breakdown__line">Base: {{ result.breakdown.gold.base }}g</div>
                  <div v-if="result.breakdown.gold.grade_mult > 1" class="reward-breakdown__line">Grade: ×{{ result.breakdown.gold.grade_mult }}</div>
                  <div v-if="result.breakdown.gold.luck_pct" class="reward-breakdown__line reward-breakdown__line--luck">Luck: +{{ result.breakdown.gold.luck_pct }}%</div>
                  <div v-if="result.breakdown.gold.vip_pct" class="reward-breakdown__line">Rank: +{{ result.breakdown.gold.vip_pct }}%</div>
                  <div v-if="result.breakdown.gold.guild_pct" class="reward-breakdown__line">Guild: +{{ result.breakdown.gold.guild_pct }}%</div>
                </div>
                <div class="reward-breakdown__section">
                  <div class="reward-breakdown__label">✦ XP Breakdown</div>
                  <div class="reward-breakdown__line">Base: {{ result.breakdown.xp.base }} xp</div>
                  <div v-if="result.breakdown.xp.grade_mult > 1" class="reward-breakdown__line">Grade: ×{{ result.breakdown.xp.grade_mult }}</div>
                  <div v-if="result.breakdown.xp.luck_pct" class="reward-breakdown__line reward-breakdown__line--luck">Luck: +{{ result.breakdown.xp.luck_pct }}%</div>
                  <div v-if="result.breakdown.xp.vip_pct" class="reward-breakdown__line">Rank: +{{ result.breakdown.xp.vip_pct }}%</div>
                  <div v-if="result.breakdown.xp.guild_pct" class="reward-breakdown__line">Guild: +{{ result.breakdown.xp.guild_pct }}%</div>
                  <div v-if="result.breakdown.xp.pet_pct" class="reward-breakdown__line">Pet: +{{ result.breakdown.xp.pet_pct }}%</div>
                </div>
                <div v-if="result.breakdown.gems" class="reward-breakdown__section">
                  <div class="reward-breakdown__label">💎 Gems Breakdown</div>
                  <div class="reward-breakdown__line">Base: {{ result.breakdown.gems.base }} gems</div>
                  <div v-if="result.breakdown.gems.grade_mult > 1" class="reward-breakdown__line">Grade: ×{{ result.breakdown.gems.grade_mult }}</div>
                  <div v-if="result.breakdown.gems.luck_pct" class="reward-breakdown__line reward-breakdown__line--luck">Luck: +{{ result.breakdown.gems.luck_pct }}%</div>
                </div>
              </div>
            </div>

          </template>

          <template v-else-if="monster">
            <div class="monster-art-stage" :class="{ 'monster-art-stage--active': battle.status === 'active' }">
              <div class="monster-art-ring" :style="{ borderColor: gradeMeta.color }"></div>
              <div class="monster-art">{{ monster.glyph }}</div>
            </div>
            <div class="ox monster-name">
              {{ monster.name }}
              <span v-if="rankMeta" class="monster-name__grade" :style="{ color: rankMeta.color, borderColor: rankMeta.color }">{{ rankMeta.label }}</span>
              <span v-if="battle.grade !== 'common'" class="monster-name__grade" :style="{ color: gradeMeta.color, borderColor: gradeMeta.color }">{{ gradeMeta.label }}</span>
              <span class="monster-name__level">Lv.{{ monster.min_level }}</span>
            </div>
            <div class="stat-block stat-block--monster">
              <div class="stat-label-row stat-label-row--tight">
                <span class="hp">HP</span><span>{{ battle.monster_hp }} / {{ monsterHpMax }}</span>
              </div>
              <div class="stat-bar-track">
                <div class="stat-bar-fill--hp" :style="{ width: hpPct(battle.monster_hp, monsterHpMax) + '%' }"></div>
              </div>
            </div>
          </template>

          <template v-else>
            <div class="monster-art-stage monster-art-stage--empty">
              <div class="monster-art">❔</div>
            </div>
            <div class="ox monster-name">No enemy nearby</div>
            <p class="monster-panel__hint">Take a walk to find a fight in {{ currentZoneName }}.</p>
          </template>
        </div>

        <div v-if="hasAdds" class="adds-panel">
          <div
            v-for="add in extraMonsters"
            :key="add.id"
            class="add-card"
            :class="{ 'add-card--dead': add.hp <= 0, 'add-card--selected': selectedTargetId === add.id }"
            @click="battle?.status === 'active' && add.hp > 0 && selectTarget(add.id)"
          >
            <div class="add-card__name">{{ add.hp > 0 ? add.monster?.name : `${add.monster?.name} (defeated)` }}</div>
            <div class="stat-bar-track add-card__bar">
              <div class="stat-bar-fill--hp" :style="{ width: hpPct(add.hp, add.hp_max) + '%' }"></div>
            </div>
            <div class="add-card__hp">{{ add.hp }} / {{ add.hp_max }}</div>
          </div>
        </div>

        <div class="player-panel">
          <div class="player-panel__header">
            <div class="player-panel__identity">
              <span class="ox player-panel__name">{{ characterStore.character?.name }}</span>
              <span class="player-panel__meta">{{ characterStore.character?.base_class }} · Lv.{{ characterStore.character?.level }}</span>
            </div>
            <div class="player-panel__combat-stats">
              <span><span class="ox player-panel__stat-value player-panel__stat-value--atk">{{ playerAtk }}</span> ATK</span>
              <span><span class="ox player-panel__stat-value player-panel__stat-value--def">{{ playerDef }}</span> DEF</span>
            </div>
          </div>
          <div class="player-stats-row">
            <div class="stat-block stat-block--player">
              <div class="stat-label-row">
                <span class="hp">HP</span><span>{{ displayCharacterHp }} / {{ playerHpMax }}</span>
              </div>
              <div class="stat-bar-track">
                <div
                  class="stat-bar-fill--hp"
                  :class="{ 'stat-bar-fill--regenerating': isRegeneratingHp }"
                  :style="{ width: hpBarPct + '%' }"
                ></div>
              </div>
            </div>
            <div class="stat-block stat-block--last">
              <div class="stat-label-row">
                <span class="mp">MP</span><span>{{ displayCharacterMana }} / {{ playerMpMax }}</span>
              </div>
              <div class="stat-bar-track">
                <div
                  class="stat-bar-fill--mp"
                  :class="{ 'stat-bar-fill--regenerating': isRegeneratingMana }"
                  :style="{ width: mpBarPct + '%' }"
                ></div>
              </div>
            </div>
          </div>
        </div>

        <div v-if="hasAdds && battle?.status === 'active'" class="target-hint">
          Targeting: <strong>{{ selectedTargetId ? extraMonsters.find((m) => m.id === selectedTargetId)?.monster?.name : monster?.name }}</strong>
          <span v-if="activeSkillRows.some((r) => r.skill.effect_json?.aoe)"> — AOE skills hit everyone regardless</span>
        </div>

        <!-- Same full-width primary button always — Attack mid-fight, and Walk/Fight again/Continue
        once there's no live fight to act in, so starting the next encounter never requires jumping to
        a different part of the screen or a differently-sized button. -->
        <button
          v-if="battle?.status === 'active'"
          class="btn-attack"
          @click="act('attack', { target_monster_id: selectedTargetId })"
          :disabled="loading"
        >
          {{ primaryLabel }}
        </button>
        <button v-else class="btn-attack" @click="walk" :disabled="loading">{{ primaryLabel }}</button>

        <!-- Skills while actually fighting — full-width rows, color-coded by effect (blue = damage,
        violet = AOE, green = heal/support), with Consumables as its own centered button below rather
        than crowded into the same grid. -->
        <div v-if="battle?.status === 'active'" class="skill-list">
          <button
            v-for="row in activeSkillRows"
            :key="row.skill.id"
            class="skill-row"
            :class="`skill-row--${skillTone(row.skill)}`"
            @click="act('skill', { skill_id: row.skill.id, target_monster_id: selectedTargetId })"
            :disabled="loading || characterStore.character?.mana < row.skill.mp_cost || (skillCooldowns[row.skill.id] ?? 0) > 0"
          >
            <span class="skill-row__glyph">{{ row.skill.glyph }}</span>
            <span class="skill-row__body">
              <span class="skill-row__name">{{ row.skill.name }}</span>
              <!-- Exact, rank-scaled numbers combat actually uses (CharacterSkill::effect_description) —
              e.g. "1.9x ATK damage on use" or "Restores 20% max HP on use" — not just the raw MP cost. -->
              <span v-if="row.effect_description" class="skill-row__info">{{ row.effect_description }}</span>
            </span>
            <template v-if="(skillCooldowns[row.skill.id] ?? 0) > 0">
              <span class="skill-row__meta">{{ skillCooldowns[row.skill.id] }} round{{ skillCooldowns[row.skill.id] > 1 ? 's' : '' }}</span>
            </template>
            <template v-else>
              <span class="skill-row__meta">{{ row.skill.mp_cost }} MP{{ row.skill.effect_json?.aoe ? ' · AOE' : '' }}</span>
            </template>
          </button>
        </div>
        <div v-if="battle?.status === 'active'" class="consumables-row">
          <button
            class="btn-consumables"
            @click="consumableGridOpen = true"
            :disabled="loading || !usableConsumables.length"
          >
            🧪 Consumables{{ usableConsumables.length ? ` (${usableConsumables.length})` : '' }}
          </button>
          <p v-if="!usableConsumables.length" class="no-consumables-hint">No usable potions or elixirs.</p>
        </div>

        <!-- Quick links once the fight's resolved (or before the first one) — real destinations, not
        a re-fight of "the same foe" which the game doesn't actually track as a distinct action. -->
        <div v-else class="action-tiles">
          <router-link
            v-for="t in postBattleTiles"
            :key="t.to"
            :to="t.to"
            class="action-tile"
            :class="`action-tile--${t.tone}`"
          >
            <div class="action-tile__glyph">{{ t.glyph }}</div>
            <div class="action-tile__name">{{ t.name }}</div>
            <div class="action-tile__meta">{{ t.meta }}</div>
          </router-link>
        </div>

        <div v-if="consumableGridOpen" class="consumable-modal-overlay" @click.self="consumableGridOpen = false">
          <div class="consumable-modal">
            <div class="consumable-modal__head">
              <span class="ox consumable-modal__title">Use a consumable</span>
              <button class="consumable-modal__close" @click="consumableGridOpen = false">✕</button>
            </div>
            <p class="consumable-modal__hint">Doesn't use your turn — pick freely, then act.</p>
            <div class="consumable-grid">
              <button
                v-for="row in usableConsumables"
                :key="row.item_id"
                class="consumable-card"
                @click="useConsumable(row)"
                :disabled="loading"
              >
                <span class="consumable-card__glyph">{{ row.item.glyph || '🧪' }}</span>
                <span class="ox consumable-card__name">{{ row.item.name }}</span>
                <span class="consumable-card__effect">{{ consumableLabel(row).split('(')[1]?.replace(')', '') }}</span>
                <span class="consumable-card__qty">×{{ row.qty }}</span>
              </button>
            </div>
          </div>
        </div>

        <div v-if="battle?.log_json?.length" v-twemoji class="battle-log">
          <div
            v-for="(line, i) in displayedBattleLog"
            :key="i"
            class="battle-log__line"
            :style="{ color: logColor(line) }"
          >
            {{ line }}
          </div>
        </div>
      </div>

      <AdBanner variant="sidebar" />
    </div>
    <!-- End fight view -->

    </div>
    <!-- End battle-main -->

    <WorldChat />
    </div>
  </div>
</template>

<style lang="scss" src="./BattlePage.scss" scoped></style>
