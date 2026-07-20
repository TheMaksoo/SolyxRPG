<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useCharacterStore } from '../stores/character';
import api from '../api/client';

const router = useRouter();
const store = useCharacterStore();
const achievements = ref([]);
const cosmetics = ref([]);
const isTester = ref(false);
const tab = ref('overview');
const message = ref('');

const TABS = [
  { key: 'overview', label: 'Overview' },
  { key: 'achievements', label: 'Achievements' },
  { key: 'customize', label: 'Customize' },
];

async function loadAchievements() {
  const { data } = await api.get('/achievements');
  achievements.value = data.achievements;
  questsCompletedTarget.value = data.quests_completed;
}

// Achievements come in numeric tiers (e.g. battles_won: 1 → 50 → 300 → 1000 → 5000). Listing every
// tier up front is a wall of text and spoils the ceiling before you're anywhere near it — instead we
// show earned tiers plus just the next unearned one per family, and fold the rest into a "+N more"
// teaser so there's always something further out to work toward.
function achievementFamily(req) {
  return req?.kind === 'trade_skill_level' ? `trade_skill_level:${req.skill_key}` : req?.kind;
}

const achievementView = computed(() => {
  const groups = {};
  for (const row of achievements.value) {
    (groups[achievementFamily(row.achievement.requirement_json)] ??= []).push(row);
  }

  const visible = [];
  let hiddenCount = 0;
  for (const key in groups) {
    const rows = [...groups[key]].sort(
      (a, b) => (a.achievement.requirement_json?.target ?? 0) - (b.achievement.requirement_json?.target ?? 0)
    );
    let shownNextTier = false;
    for (const row of rows) {
      if (row.earned || !shownNextTier) {
        visible.push(row);
        if (!row.earned) shownNextTier = true;
      } else {
        hiddenCount++;
      }
    }
  }

  return { visible, hiddenCount };
});

async function loadCosmetics() {
  const { data } = await api.get('/cosmetics');
  cosmetics.value = data.cosmetics;
  isTester.value = data.is_tester;
}

// ---- Rising "quests completed" counter ----
const questsCompletedTarget = ref(0);
const questsCompletedDisplay = ref(0);
let rafId = null;

function animateCount(from, to, duration = 900) {
  if (rafId) cancelAnimationFrame(rafId);
  if (from === to) {
    questsCompletedDisplay.value = to;
    return;
  }
  const start = performance.now();
  const step = (now) => {
    const t = Math.min(1, (now - start) / duration);
    const eased = 1 - Math.pow(1 - t, 3);
    questsCompletedDisplay.value = Math.round(from + (to - from) * eased);
    if (t < 1) rafId = requestAnimationFrame(step);
  };
  rafId = requestAnimationFrame(step);
}

watch(questsCompletedTarget, (next, prev) => animateCount(prev ?? 0, next));

// ---- Cosmetics (titles / colors / banners) ----
const COSMETIC_TYPES = [
  { key: 'title', label: 'Titles' },
  { key: 'color', label: 'Name Colors' },
  { key: 'banner', label: 'Banners' },
  { key: 'icon', label: 'Profile Icons' },
];

// Quest/event-earned cosmetics stay a mystery until unlocked — there's no purchase decision to
// inform, so revealing the exact color/icon/title just spoils it. Gem-purchasable ones stay fully
// visible since you need to see what you're buying.
function cosmeticsOfType(type) {
  return cosmetics.value
    .filter((row) => row.cosmetic.type === type)
    .map((row) => ({ ...row, mystery: !row.owned && !!(row.quest || row.event) }));
}

async function unlock(row) {
  message.value = '';
  try {
    await api.post(`/cosmetics/${row.cosmetic.id}/unlock`);
    await Promise.all([loadCosmetics(), store.fetch()]);
  } catch (e) {
    const msg = e.response?.data?.message || 'Could not unlock.';
    if (msg === 'Not enough gems.') {
      message.value = `Not enough gems for "${row.cosmetic.name}" — heading to the Gem Store...`;
      setTimeout(() => router.push('/gem-store'), 1400);
    } else {
      message.value = msg;
    }
  }
}

async function equip(row) {
  message.value = '';
  try {
    await api.post(`/cosmetics/${row.cosmetic.id}/equip`);
    await Promise.all([loadCosmetics(), store.fetch()]);
  } catch (e) {
    message.value = e.response?.data?.message || 'Could not equip.';
  }
}

const ATTR_ROWS = [
  { key: 'damage', label: 'Damage' },
  { key: 'armor', label: 'Armor' },
  { key: 'hp_cap', label: 'HP Cap' },
  { key: 'mana_cap', label: 'Mana Cap' },
  { key: 'hp_regen', label: 'HP Regen' },
  { key: 'mana_regen', label: 'Mana Regen' },
  { key: 'crit', label: 'Crit Chance' },
  { key: 'crit_damage', label: 'Crit Damage' },
  { key: 'luck', label: 'Luck' },
  { key: 'dodge', label: 'Dodge' },
  { key: 'energy_cap', label: 'Energy Cap' },
  { key: 'energy_regen', label: 'Energy Regen' },
  { key: 'mining_speed', label: 'Mining Speed' },
  { key: 'chopping_speed', label: 'Chopping Speed' },
  { key: 'smelting_speed', label: 'Smelting Speed' },
  { key: 'crafting_speed', label: 'Crafting Speed' },
  { key: 'foraging_speed', label: 'Foraging Speed' },
];

const CORE_ROWS = [
  { label: 'Attack', base: 'base_atk', eff: 'eff_atk' },
  { label: 'Defense', base: 'base_def', eff: 'eff_def' },
  { label: 'HP', base: 'hp_max', eff: 'eff_hp_max' },
  { label: 'Mana', base: 'mana_max', eff: 'eff_mp_max' },
  { label: 'Energy', base: 'energy_max', eff: 'eff_energy_max' },
];

const battlesTotal = computed(() => (store.character?.battles_won ?? 0) + (store.character?.battles_lost ?? 0));
const winRate = computed(() => (battlesTotal.value > 0 ? Math.round(((store.character?.battles_won ?? 0) / battlesTotal.value) * 100) : 0));

// ---- Fun stats ----
const FUN_STAT_ROWS = [
  { key: 'times_mined', label: 'Ore Mined', glyph: '⛏' },
  { key: 'times_chopped', label: 'Trees Chopped', glyph: '🪓' },
  { key: 'times_smelted', label: 'Bars Smelted', glyph: '🔥' },
  { key: 'times_foraged', label: 'Herbs Foraged', glyph: '🌿' },
  { key: 'times_crafted', label: 'Items Crafted', glyph: '🔨' },
  { key: 'battles_lost', label: 'Times Died', glyph: '💀' },
  { key: 'bosses_slain', label: 'Bosses Slain', glyph: '☠' },
];

const GATHER_STAT_LABELS = {
  times_mined: 'Mining',
  times_chopped: 'Woodchopping',
  times_smelted: 'Smelting',
  times_foraged: 'Foraging',
  times_crafted: 'Crafting',
};

const favoriteSkill = computed(() => {
  const c = store.character;
  if (!c) return null;
  const entries = Object.keys(GATHER_STAT_LABELS).map((key) => [key, c[key] ?? 0]);
  const [topKey, topValue] = entries.reduce((best, cur) => (cur[1] > best[1] ? cur : best), entries[0]);
  return topValue > 0 ? GATHER_STAT_LABELS[topKey] : null;
});

const heroBackground = computed(() => store.character?.active_banner?.value || null);
const nameColor = computed(() => store.character?.active_color?.value || null);
const activeTitleText = computed(() => store.character?.active_title?.value || null);
const activeIconGlyph = computed(() => store.character?.active_icon?.value || null);

function activeBuff(pctKey, expiresKey) {
  const pct = store.character?.[pctKey];
  const expiresAt = store.character?.[expiresKey];
  if (!pct || !expiresAt) return null;
  const msLeft = new Date(expiresAt).getTime() - Date.now();
  if (msLeft <= 0) return null;
  return `+${pct}% for ${Math.max(1, Math.round(msLeft / 60000))}m`;
}

const hpRegenBuff = computed(() => activeBuff('hp_regen_buff_pct', 'hp_regen_buff_expires_at'));
const manaRegenBuff = computed(() => activeBuff('mana_regen_buff_pct', 'mana_regen_buff_expires_at'));

onMounted(() => {
  if (!store.character) store.fetch();
  loadAchievements();
  loadCosmetics();
});
</script>

<template>
  <div v-if="store.character">
    <div class="profile-tabs">
      <button
        v-for="t in TABS"
        :key="t.key"
        @click="tab = t.key"
        class="profile-tab-btn"
        :class="{ 'is-active': tab === t.key }"
      >
        {{ t.label }}
      </button>
    </div>

    <p v-if="message" class="profile-message">{{ message }}</p>

    <template v-if="tab === 'overview'">
      <div class="profile-hero" :style="heroBackground ? { background: heroBackground } : null">
        <div class="ox profile-hero__avatar" :class="{ 'profile-hero__avatar--icon': activeIconGlyph }">
          {{ activeIconGlyph || store.character.name.slice(0, 2).toUpperCase() }}
        </div>
        <div class="profile-hero__info">
          <div class="profile-hero__name-row">
            <div class="ox profile-hero__name" :style="nameColor ? { color: nameColor } : null">{{ store.character.name }}</div>
            <div v-if="activeTitleText" class="profile-hero__title-badge">{{ activeTitleText }}</div>
          </div>
          <div class="profile-hero__class">
            {{ store.character.spec_class || store.character.base_class }} · Level {{ store.character.level }}
          </div>
          <div class="profile-hero__meta">
            {{ store.character.battles_won }} won · {{ store.character.battles_lost ?? 0 }} lost ({{ winRate }}% win rate) · {{ store.character.bosses_slain }} bosses slain
          </div>
          <div v-if="store.stats" class="profile-hero__xp">
            <div class="profile-hero__xp-track">
              <div class="profile-hero__xp-fill" :style="{ width: Math.min(100, Math.round((store.character.xp / (store.stats.xp_max || 1)) * 100)) + '%' }"></div>
            </div>
            <div class="profile-hero__xp-label">{{ store.character.xp }} / {{ store.stats.xp_max }} XP</div>
          </div>
        </div>
        <div v-if="store.stats" class="profile-hero__stats">
          <div class="profile-stat">
            <div class="ox profile-stat__value profile-stat__value--power">{{ store.stats.power }}</div>
            <div class="profile-stat__label">Power</div>
          </div>
          <div class="profile-stat">
            <div class="ox profile-stat__value profile-stat__value--luck">{{ store.stats.luck ?? 0 }}</div>
            <div class="profile-stat__label">Luck</div>
          </div>
          <div class="profile-stat">
            <div class="ox profile-stat__value profile-stat__value--gold">{{ store.character.gold }}g</div>
            <div class="profile-stat__label">Gold</div>
          </div>
          <div class="profile-stat">
            <div class="ox profile-stat__value profile-stat__value--gems">{{ store.character.gems }}◆</div>
            <div class="profile-stat__label">Gems</div>
          </div>
        </div>
      </div>

      <div v-if="store.stats" class="stats-eyebrow">STATS — base value vs. total with gear, attributes &amp; buffs</div>
      <div v-if="store.stats" class="stats-panel">
        <table class="stats-table">
          <thead>
            <tr>
              <th></th>
              <th>Base</th>
              <th>Total</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in CORE_ROWS" :key="row.label">
              <td class="stats-table__label">{{ row.label }}</td>
              <td class="stats-table__base">{{ store.character[row.base] }}</td>
              <td class="stats-table__eff">{{ store.stats[row.eff] }}</td>
            </tr>
            <tr>
              <td class="stats-table__label">Crit Chance</td>
              <td class="stats-table__base">—</td>
              <td class="stats-table__eff">{{ store.stats.crit_chance }}%</td>
            </tr>
            <tr>
              <td class="stats-table__label">Crit Damage</td>
              <td class="stats-table__base">—</td>
              <td class="stats-table__eff">{{ store.stats.crit_damage_mult }}x</td>
            </tr>
            <tr>
              <td class="stats-table__label">Dodge Chance</td>
              <td class="stats-table__base">—</td>
              <td class="stats-table__eff">{{ store.stats.dodge_chance ?? 0 }}%</td>
            </tr>
            <tr>
              <td class="stats-table__label">Luck</td>
              <td class="stats-table__base">—</td>
              <td class="stats-table__eff">{{ store.stats.luck ?? 0 }}</td>
            </tr>
            <tr>
              <td class="stats-table__label">HP Regen</td>
              <td class="stats-table__base">—</td>
              <td class="stats-table__eff">
                +{{ store.regenPerTick }}/5s
                <span v-if="hpRegenBuff" class="stats-table__buff">({{ hpRegenBuff }})</span>
              </td>
            </tr>
            <tr>
              <td class="stats-table__label">Mana Regen</td>
              <td class="stats-table__base">—</td>
              <td class="stats-table__eff">
                +{{ store.manaRegenPerTick }}/5s
                <span v-if="manaRegenBuff" class="stats-table__buff">({{ manaRegenBuff }})</span>
              </td>
            </tr>
            <tr>
              <td class="stats-table__label">Energy Regen</td>
              <td class="stats-table__base">—</td>
              <td class="stats-table__eff">+{{ store.energyRegenPerTick }}/5s</td>
            </tr>
          </tbody>
        </table>

        <div class="attribute-points-eyebrow">ATTRIBUTE POINTS SPENT</div>
        <div class="attribute-points-grid">
          <div v-for="a in ATTR_ROWS" :key="a.key" class="attribute-points-chip">
            <span class="attribute-points-chip__label">{{ a.label }}</span>
            <span class="attribute-points-chip__value">{{ store.character.attributes_?.[a.key] ?? 0 }}</span>
          </div>
        </div>
      </div>

      <div class="fun-stats-eyebrow">FUN STATS</div>
      <div class="fun-stats-panel">
        <div v-if="favoriteSkill" class="fun-stats-favorite">
          <span class="fun-stats-favorite__label">Favorite Skill</span>
          <span class="fun-stats-favorite__value">{{ favoriteSkill }}</span>
        </div>
        <div class="fun-stats-grid">
          <div v-for="s in FUN_STAT_ROWS" :key="s.key" class="fun-stat-chip">
            <div class="fun-stat-chip__glyph">{{ s.glyph }}</div>
            <div class="fun-stat-chip__value">{{ store.character[s.key] ?? 0 }}</div>
            <div class="fun-stat-chip__label">{{ s.label }}</div>
          </div>
        </div>
      </div>
    </template>

    <template v-else-if="tab === 'achievements'">
      <div class="quests-completed-banner">
        <div class="quests-completed-banner__value">{{ questsCompletedDisplay }}</div>
        <div class="quests-completed-banner__label">Quests Completed</div>
      </div>

      <div class="achievements-eyebrow">ACHIEVEMENTS</div>
      <div class="achievements-grid">
        <div
          v-for="row in achievementView.visible"
          :key="row.achievement.id"
          class="achievement-card"
          :class="{ 'achievement-card--earned': row.earned }"
        >
          <div class="achievement-card__glyph">{{ row.achievement.glyph }}</div>
          <div class="achievement-card__name">{{ row.achievement.name }}</div>
          <div class="achievement-card__desc">{{ row.achievement.description }}</div>
        </div>
        <div v-if="achievementView.hiddenCount > 0" class="achievement-card achievement-card--more">
          <div class="achievement-card__glyph">❔</div>
          <div class="achievement-card__name">+{{ achievementView.hiddenCount }} more</div>
          <div class="achievement-card__desc">Higher tiers reveal themselves as you close in on them.</div>
        </div>
      </div>
    </template>

    <template v-else-if="tab === 'customize'">
      <p v-if="isTester" class="customize-tester-note">Tester account — every title, color and banner is unlocked; switch freely.</p>

      <div v-for="ct in COSMETIC_TYPES" :key="ct.key" class="customize-section">
        <div class="customize-eyebrow">{{ ct.label.toUpperCase() }}</div>
        <div class="customize-grid">
          <div
            v-for="row in cosmeticsOfType(ct.key)"
            :key="row.cosmetic.id"
            class="cosmetic-card"
            :class="{ 'cosmetic-card--active': row.active, 'cosmetic-card--mystery': row.mystery }"
          >
            <template v-if="row.mystery">
              <div class="cosmetic-card__icon-preview">❔</div>
              <div class="cosmetic-card__name">???</div>
              <div class="cosmetic-card__cost cosmetic-card__cost--quest">🔒 Quest: {{ row.quest || 'complete the tutorial' }}</div>
              <button class="cosmetic-card__btn" disabled>Complete quest to earn</button>
            </template>
            <template v-else>
              <div
                v-if="ct.key === 'color'"
                class="cosmetic-card__swatch"
                :style="{ background: row.cosmetic.value }"
              ></div>
              <div
                v-else-if="ct.key === 'banner'"
                class="cosmetic-card__swatch"
                :style="{ background: row.cosmetic.value }"
              ></div>
              <div v-else-if="ct.key === 'icon'" class="cosmetic-card__icon-preview">{{ row.cosmetic.value }}</div>
              <div v-else class="cosmetic-card__title-preview">{{ row.cosmetic.value }}</div>

              <div class="cosmetic-card__name">{{ row.cosmetic.name }}</div>
              <div v-if="row.quest" class="cosmetic-card__cost cosmetic-card__cost--quest">Quest: {{ row.quest }}</div>
              <div v-else-if="row.cosmetic.cost_gems > 0" class="cosmetic-card__cost">💎 {{ row.cosmetic.cost_gems }}</div>
              <div v-else class="cosmetic-card__cost">Free</div>

              <button v-if="row.active" class="cosmetic-card__btn cosmetic-card__btn--active" disabled>Equipped</button>
              <button v-else-if="row.owned" class="cosmetic-card__btn" @click="equip(row)">Equip</button>
              <button v-else class="cosmetic-card__btn" @click="unlock(row)">Unlock</button>
            </template>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<style lang="scss" src="./ProfilePage.scss" scoped></style>
