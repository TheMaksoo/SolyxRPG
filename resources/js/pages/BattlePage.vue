<script setup>
import { ref, onMounted, onUnmounted, computed, watch } from 'vue';
import api from '../api/client';
import { useCharacterStore } from '../stores/character';
import AdBanner from '../components/AdBanner.vue';
import WorldChat from '../components/WorldChat.vue';

const characterStore = useCharacterStore();
const battle = ref(null);
const result = ref(null);
const loading = ref(true);
const error = ref('');
const notice = ref('');
const dungeonRun = ref(null);

const autoBattle = ref({ active: false, seconds_remaining: 0, costs: {}, gems: 0 });
const autoBattleMessage = ref('');
const autoBattleSummary = ref(null);
let autoBattleTimer = null;
let autoBattlePollTimer = null;

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
}

async function buyAutoBattleCash() {
  try {
    const { data } = await api.post('/store/checkout', { sku: 'auto_battle_60' });
    window.location.href = data.checkout_url;
  } catch (e) {
    autoBattleMessage.value = e.response?.data?.message || 'Checkout unavailable.';
  }
}

async function buyAutoBattle(minutes) {
  try {
    const { data } = await api.post('/auto-battle/purchase', { minutes });
    autoBattle.value = { ...autoBattle.value, active: true, paused: false, seconds_remaining: data.seconds_remaining, gems: data.gems };
    autoBattleMessage.value = `Auto-Attack started — ${minutes} minutes added.`;
    characterStore.fetch();
  } catch (e) {
    autoBattleMessage.value = e.response?.data?.message || 'Could not start auto-attack.';
  }
}

const monster = computed(() => battle.value?.monster ?? null);
const playerHpMax = computed(() => characterStore.stats?.eff_hp_max ?? battle.value?.character_hp ?? 1);
const playerMpMax = computed(() => characterStore.character?.mana_max ?? 1);
const hpPct = (hp, max) => (max > 0 ? Math.max(0, Math.min(100, Math.round((hp / max) * 100))) : 0);

const currentZoneName = computed(() => characterStore.character?.zone?.name ?? 'the wilds');

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

// Per-skill ticking cooldown copies keyed by skill id — the server value is a snapshot from the last
// fetch/action, this keeps each button's countdown live between requests. Carries across battles because
// it's re-synced from each skill row's own cooldown_remaining, stamped on the character's skill row rather
// than on any one Battle.
const skillCooldowns = ref({});
let skillCooldownTimer = null;
watch(activeSkillRows, (rows) => {
  const next = {};
  for (const row of rows) next[row.skill.id] = row.cooldown_remaining ?? 0;
  skillCooldowns.value = next;
}, { immediate: true });
const potion = computed(() => {
  const inv = characterStore.character?.inventory ?? [];
  return inv.find((i) => i.item?.type === 'consumable' && i.item?.stat_json?.heal_hp_pct && i.qty > 0) ?? null;
});

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

async function resumeOrBrowse() {
  loading.value = true;
  try {
    const { data } = await api.get('/battle/active');
    if (data.battle) {
      battle.value = data.battle;
      dungeonRun.value = data.dungeon_run;
      notice.value = dungeonRun.value
        ? `Resumed your dungeon run — stage ${dungeonRun.value.stage} / ${dungeonRun.value.total_stages}.`
        : 'Resumed your battle in progress.';
    }
  } finally {
    loading.value = false;
  }
}

async function walk() {
  error.value = '';
  loading.value = true;
  try {
    const { data } = await api.post('/battle/walk');
    battle.value = data.battle;
    result.value = null;
    dungeonRun.value = null;
    notice.value = data.grade?.label && data.grade.label !== 'Common' ? `You encounter a ${data.grade.label} foe!` : '';
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

    if (data.dungeon_run?.next_battle) {
      dungeonRun.value = data.dungeon_run;
      battle.value = data.dungeon_run.next_battle;
      result.value = null;
      notice.value = `Stage cleared! Entering stage ${data.dungeon_run.stage} / ${data.dungeon_run.total_stages}.`;
    } else {
      battle.value = data.battle;
      result.value = data.result;
      if (data.dungeon_run) {
        dungeonRun.value = data.dungeon_run;
      }
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
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not flee.';
  } finally {
    loading.value = false;
  }
}

onMounted(() => {
  resumeOrBrowse();
  if (!characterStore.stats) characterStore.fetch();

  loadAutoBattle();
  autoBattleTimer = setInterval(() => {
    if (autoBattle.value.paused) return;
    if (autoBattle.value.seconds_remaining > 0) autoBattle.value.seconds_remaining -= 1;
  }, 1000);
  autoBattlePollTimer = setInterval(loadAutoBattle, 20000);
  skillCooldownTimer = setInterval(() => {
    for (const key in skillCooldowns.value) {
      if (skillCooldowns.value[key] > 0) skillCooldowns.value[key] -= 1;
    }
  }, 1000);
});

onUnmounted(() => {
  clearInterval(autoBattleTimer);
  clearInterval(autoBattlePollTimer);
  clearInterval(skillCooldownTimer);
});
</script>

<template>
  <div>
    <div class="battle-header">
      <div class="battle-header__icon">⚔</div>
      <h1 class="ox battle-title">Battle</h1>
    </div>

    <p v-if="notice" class="battle-notice">{{ notice }}</p>
    <p v-if="error" class="battle-error">{{ error }}</p>

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
      <div v-if="autoBattle.active" class="auto-battle-card__status" :class="{ 'auto-battle-card__status--paused': autoBattle.paused }">
        <span class="auto-battle-card__label">{{ autoBattle.paused ? '⏸ Auto-Attack paused' : '🤖 Auto-Attack active' }}</span>
        <span class="auto-battle-card__timer">
          {{ formatDuration(autoBattle.seconds_remaining) }} remaining
          <template v-if="autoBattle.paused"> — resumes when you leave this battle</template>
        </span>
      </div>
      <div class="auto-battle-card__buy">
        <span class="auto-battle-card__label">
          {{ autoBattle.active ? '🤖 Buy more time' : '🤖 Auto-Attack — fights for you (attacks above 50% HP, heals at 30%)' }}
        </span>
        <div class="auto-battle-card__options">
          <button
            v-for="minutes in [15, 30, 60]"
            :key="minutes"
            class="auto-battle-card__option"
            :disabled="(characterStore.character?.gems ?? 0) < (autoBattle.costs[minutes] ?? 0)"
            @click="buyAutoBattle(minutes)"
          >
            {{ minutes }}m · 💎{{ autoBattle.costs[minutes] ?? '—' }}
          </button>
          <button class="auto-battle-card__option auto-battle-card__option--cash" @click="buyAutoBattleCash">
            60m · $1.00
          </button>
        </div>
      </div>
    </div>

    <div class="battle-layout">
    <div class="battle-main">
    <div v-if="loading && !battle" class="battle-loading">Loading…</div>

    <!-- Walk into a fight -->
    <div v-else-if="!battle" class="battle-start">
      <div class="battle-start__art">🚶</div>
      <p class="battle-start__intro">
        You're in <strong>{{ currentZoneName }}</strong>. Take a walk to find a fight.
      </p>
      <button class="btn-walk btn-walk--large" @click="walk" :disabled="loading">🚶 Walk</button>
    </div>

    <!-- Fight view -->
    <div v-else-if="battle.status === 'active'" class="fight-view">
      <div class="fight-view__main" :class="{ 'is-loading': loading }">
        <div class="fight-view__header">
          <div class="fight-view__zone">
            {{ currentZoneName }}
            <span v-if="dungeonRun" class="fight-view__stage">· Stage {{ dungeonRun.stage }} / {{ dungeonRun.total_stages }}</span>
          </div>
          <button class="flee-btn" @click="flee" :disabled="loading">Flee ↩</button>
        </div>

        <div
          class="monster-panel"
          :class="{ 'monster-panel--targetable': hasAdds, 'monster-panel--selected': hasAdds && !selectedTargetId }"
          @click="hasAdds && selectTarget(null)"
        >
          <div class="monster-art">{{ monster?.glyph }}</div>
          <div class="ox monster-name">
            {{ monster?.name }}
            <span v-if="rankMeta" class="monster-name__grade" :style="{ color: rankMeta.color, borderColor: rankMeta.color }">{{ rankMeta.label }}</span>
            <span v-if="battle.grade !== 'common'" class="monster-name__grade" :style="{ color: gradeMeta.color, borderColor: gradeMeta.color }">{{ gradeMeta.label }}</span>
          </div>
          <div class="stat-block">
            <div class="stat-label-row">
              <span class="hp">HP</span><span>{{ battle.monster_hp }} / {{ monsterHpMax }}</span>
            </div>
            <div class="stat-bar-track">
              <div class="stat-bar-fill--hp" :style="{ width: hpPct(battle.monster_hp, monsterHpMax) + '%' }"></div>
            </div>
          </div>
        </div>

        <div v-if="hasAdds" class="adds-panel">
          <div
            v-for="add in extraMonsters"
            :key="add.id"
            class="add-card"
            :class="{ 'add-card--dead': add.hp <= 0, 'add-card--selected': selectedTargetId === add.id }"
            @click="add.hp > 0 && selectTarget(add.id)"
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
            <div class="ox player-panel__name">{{ characterStore.character?.name }}</div>
            <div class="player-panel__meta">
              {{ characterStore.character?.base_class }} · Lv.{{ characterStore.character?.level }}
            </div>
          </div>
          <div class="stat-block stat-block--player">
            <div class="stat-label-row">
              <span class="hp">HP</span><span>{{ battle.character_hp }} / {{ playerHpMax }}</span>
            </div>
            <div class="stat-bar-track">
              <div class="stat-bar-fill--hp" :style="{ width: hpPct(battle.character_hp, playerHpMax) + '%' }"></div>
            </div>
          </div>
          <div class="stat-block stat-block--last">
            <div class="stat-label-row">
              <span class="mp">MP</span><span>{{ characterStore.character?.mana }} / {{ playerMpMax }}</span>
            </div>
            <div class="stat-bar-track">
              <div class="stat-bar-fill--mp" :style="{ width: hpPct(characterStore.character?.mana ?? 0, playerMpMax) + '%' }"></div>
            </div>
          </div>
        </div>

        <div v-if="hasAdds" class="target-hint">
          Targeting: <strong>{{ selectedTargetId ? extraMonsters.find((m) => m.id === selectedTargetId)?.monster?.name : monster?.name }}</strong>
          <span v-if="activeSkillRows.some((r) => r.skill.effect_json?.aoe)"> — AOE skills hit everyone regardless</span>
        </div>

        <div class="actions-grid">
          <button class="btn-attack" @click="act('attack', { target_monster_id: selectedTargetId })" :disabled="loading">⚔ Attack</button>
          <button
            v-for="row in activeSkillRows"
            :key="row.skill.id"
            class="btn-skill"
            @click="act('skill', { skill_id: row.skill.id, target_monster_id: selectedTargetId })"
            :disabled="loading || characterStore.character?.mana < row.skill.mp_cost || (skillCooldowns[row.skill.id] ?? 0) > 0"
          >
            <template v-if="(skillCooldowns[row.skill.id] ?? 0) > 0">{{ row.skill.glyph }} {{ row.skill.name }} — {{ skillCooldowns[row.skill.id] }}s</template>
            <template v-else>
              {{ row.skill.glyph }} {{ row.skill.name }} ({{ row.skill.mp_cost }} MP){{ row.skill.effect_json?.aoe ? ' · AOE' : '' }}{{ row.skill.effect_json?.heal_hp_pct ? ' · Heal' : '' }}
            </template>
          </button>
          <button
            class="btn-item"
            @click="act('item', { item_id: potion.item_id })"
            :disabled="loading || !potion"
          >
            🧪 Potion ({{ potion?.qty ?? 0 }})
          </button>
        </div>

        <div class="battle-log">
          <div
            v-for="(line, i) in [...(battle.log_json || [])].reverse()"
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

    <!-- Result view -->
    <div v-else class="result-view">
      <div class="result-icon">{{ resultIcon }}</div>
      <div class="ox result-title" :style="{ color: resultColor }">{{ resultTitle }}</div>
      <div v-if="result?.outcome === 'won'" class="result-subtitle">
        You defeated <span v-if="battle?.grade !== 'common'" :style="{ color: gradeMeta.color }">{{ gradeMeta.label }}</span> {{ monster?.name }}.
      </div>
      <div v-else class="result-subtitle">Revived at 40% HP.</div>

      <div v-if="result?.outcome === 'won'" class="reward-chips">
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

      <div v-if="result?.outcome === 'lost' && (result.gold_lost || result.xp_lost)" class="reward-chips">
        <div v-if="result.gold_lost" class="reward-chip--loss">-{{ result.gold_lost }} Gold</div>
        <div v-if="result.xp_lost" class="reward-chip--loss">-{{ result.xp_lost }} XP</div>
        <div v-if="result.levels_lost" class="reward-chip--loss reward-chip--loss-level">
          Lost level{{ result.levels_lost > 1 ? 's' : '' }}! -{{ result.levels_lost * 3 }} attr · -{{ result.levels_lost }} skill pts
        </div>
      </div>

      <div class="result-actions">
        <button class="btn-walk" @click="walk">🚶 Walk</button>
        <router-link v-if="result?.leveled_up" to="/skills" class="btn-skill-tree-link">🌟 Skill Tree</router-link>
        <router-link to="/dashboard" class="btn-dashboard-link">Dashboard</router-link>
      </div>
    </div>
    </div>

    <WorldChat />
    </div>
  </div>
</template>

<style lang="scss" src="./BattlePage.scss" scoped></style>
