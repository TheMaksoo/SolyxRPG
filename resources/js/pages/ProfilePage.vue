<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useCharacterStore } from '../stores/character';
import { useAuthStore } from '../stores/auth';
import api from '../api/client';
import { RARITY_COLORS, RARITY_LABELS } from '../rarity';
import ActivityChart from '../components/admin/ActivityChart.vue';

const RARITY_ORDER = ['common', 'rare', 'epic', 'legendary', 'mythic'];

// Title categories — mirrors the 'category' column seeded on quest/purchase titles. Only titles get
// subcategorized (colors/banners/icons are small enough sets to stay as one flat grid).
const TITLE_CATEGORIES = [
  { key: 'leveling', label: 'Leveling Milestones' },
  { key: 'mastery', label: 'Class Mastery' },
  { key: 'raid', label: 'Raids & Bosses' },
  { key: 'daily_weekly', label: 'Daily & Weekly' },
  { key: 'event', label: 'Special Events' },
  { key: 'purchased', label: 'Gem Store' },
];

const router = useRouter();
const store = useCharacterStore();
const auth = useAuthStore();
const achievements = ref([]);
const cosmetics = ref([]);
const isTester = ref(false);
const tab = ref('overview');
const message = ref('');

const ALL_TABS = [
  { key: 'overview', label: 'Overview' },
  { key: 'stats', label: 'Stats' },
  { key: 'achievements', label: 'Achievements' },
  { key: 'customize', label: 'Customize' },
];
// Customize is fully unreachable when a GM has switched off both LIVE and TESTERS for cosmetics.
const TABS = computed(() => ALL_TABS.filter((t) => t.key !== 'customize' || auth.featureAccess.cosmetics !== false));

watch(TABS, (next) => {
  if (!next.some((t) => t.key === tab.value)) tab.value = 'overview';
});

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
  if (auth.featureAccess.cosmetics === false) return;
  try {
    const { data } = await api.get('/cosmetics');
    cosmetics.value = data.cosmetics;
    isTester.value = data.is_tester;
  } catch {
    // Flag flipped off between page load and this call — nothing to show, Customize tab is hidden anyway.
  }
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
    .map((row) => ({ ...row, mystery: !row.owned && !!(row.quest || row.event) }))
    .sort((a, b) => RARITY_ORDER.indexOf(a.cosmetic.rarity) - RARITY_ORDER.indexOf(b.cosmetic.rarity));
}

const titleGroups = computed(() =>
  TITLE_CATEGORIES.map((cat) => ({
    ...cat,
    rows: cosmeticsOfType('title').filter((row) => (row.cosmetic.category ?? 'purchased') === cat.key),
  })).filter((cat) => cat.rows.length)
);

const customizeSections = computed(() => [
  ...titleGroups.value.map((g) => ({ label: g.label, rows: g.rows })),
  ...COSMETIC_TYPES.filter((t) => t.key !== 'title').map((t) => ({ label: t.label, rows: cosmeticsOfType(t.key) })),
]);

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

// Every row here has a matching `store.stats.breakdown[key]` (see Character::effectiveStats()) so
// clicking it can expand the exact labeled math behind the number — base + attributes + gear +
// pet/skill/party percentages, etc. `suffix` just controls how the total renders (%, x, or nothing).
const BREAKDOWN_ROWS = [
  { label: 'Attack', base: 'base_atk', key: 'eff_atk' },
  { label: 'Defense', base: 'base_def', key: 'eff_def' },
  { label: 'HP', base: 'hp_max', key: 'eff_hp_max' },
  { label: 'Mana', base: 'mana_max', key: 'eff_mp_max' },
  { label: 'Energy', base: 'energy_max', key: 'eff_energy_max' },
  { label: 'Crit Chance', key: 'crit_chance', suffix: '%' },
  { label: 'Crit Damage', key: 'crit_damage_mult', suffix: 'x' },
  { label: 'Dodge Chance', key: 'dodge_chance', suffix: '%' },
  { label: 'Luck', key: 'luck' },
];

const expandedStat = ref(null);

function statBreakdown(key) {
  return store.stats?.breakdown?.[key] ?? null;
}

function toggleStat(key) {
  if (!statBreakdown(key)) return;
  expandedStat.value = expandedStat.value === key ? null : key;
}

const battlesTotal = computed(() => (store.character?.battles_won ?? 0) + (store.character?.battles_lost ?? 0));
const winRate = computed(() => (battlesTotal.value > 0 ? Math.round(((store.character?.battles_won ?? 0) / battlesTotal.value) * 100) : 0));

// ---- Activity charts (real per-character data — see CharacterController::activity()) ----
const activity = ref(null);

async function loadActivity() {
  const { data } = await api.get('/character/activity');
  activity.value = data;
}

const battleTrendChartData = computed(() => {
  const rows = activity.value?.battle_trend_14d ?? [];
  return { labels: rows.map((r) => r.date.slice(5)), data: rows.map((r) => r.count) };
});

// Rendered as a GM-console-style label+bar+count list (see .bar-list in ProfilePage.scss, matching
// .gm-console-bar-list) rather than a Chart.js bar — reads cleaner next to the doughnut/line charts.
function barListMax(rows) {
  return Math.max(1, ...rows.map((r) => r.count));
}

const winLossChartData = computed(() => ({
  labels: ['Won', 'Lost'],
  data: [store.character?.battles_won ?? 0, store.character?.battles_lost ?? 0],
}));

// ---- Fun stats ---- Granular gathering-resource breakdown only — battles_lost/bosses_slain (hero line)
// and times_crafted (now in the activity_breakdown bar-list below) are deliberately excluded so nothing
// on this tab repeats a number shown elsewhere.
const FUN_STAT_ROWS = [
  { key: 'times_mined', label: 'Ore Mined', glyph: '⛏' },
  { key: 'times_chopped', label: 'Trees Chopped', glyph: '🪓' },
  { key: 'times_smelted', label: 'Bars Smelted', glyph: '🔥' },
  { key: 'times_foraged', label: 'Herbs Foraged', glyph: '🌿' },
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

// Elixir of Power / Phoenix Elixir — a fight-count buff (not time-based), so it reads straight off
// stats.atk_buff_fights_left rather than activeBuff()'s expires-at check.
const atkBuff = computed(() => {
  const fights = store.stats?.atk_buff_fights_left ?? 0;
  if (!fights) return null;
  return `+${store.stats.atk_buff_pct}% for ${fights} fight${fights > 1 ? 's' : ''}`;
});

onMounted(() => {
  if (!store.character) store.fetch();
  loadAchievements();
  loadCosmetics();
  loadActivity();
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
            <template v-for="row in BREAKDOWN_ROWS" :key="row.key">
              <tr
                class="stats-table__row"
                :class="{ 'stats-table__row--clickable': statBreakdown(row.key), 'stats-table__row--expanded': expandedStat === row.key }"
                @click="toggleStat(row.key)"
              >
                <td class="stats-table__label">
                  <span v-if="statBreakdown(row.key)" class="stats-table__caret">{{ expandedStat === row.key ? '▾' : '▸' }}</span>
                  {{ row.label }}
                </td>
                <td class="stats-table__base">{{ row.base ? store.character[row.base] : '—' }}</td>
                <td class="stats-table__eff">
                  {{ store.stats[row.key] ?? 0 }}{{ row.suffix || '' }}
                  <span v-if="row.key === 'eff_atk' && atkBuff" class="stats-table__buff">({{ atkBuff }})</span>
                </td>
              </tr>
              <tr v-if="expandedStat === row.key && statBreakdown(row.key)" class="stats-table__breakdown-row">
                <td colspan="3">
                  <div class="stat-breakdown">
                    <div v-for="src in statBreakdown(row.key).sources" :key="src.label" class="stat-breakdown__line">
                      <span class="stat-breakdown__label">{{ src.label }}</span>
                      <span class="stat-breakdown__value" :class="{ 'stat-breakdown__value--neg': src.value < 0 }">
                        {{ src.value > 0 ? '+' : '' }}{{ src.value }}
                      </span>
                    </div>
                    <div class="stat-breakdown__total">
                      <span>Total</span>
                      <span>{{ statBreakdown(row.key).total }}{{ row.suffix || '' }}</span>
                    </div>
                  </div>
                </td>
              </tr>
            </template>
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

    </template>

    <template v-else-if="tab === 'stats'">
      <div class="metric-cards">
        <div class="metric-card">
          <div class="metric-card__head">
            <div class="metric-card__label">Most-Used Skill</div>
            <div class="metric-card__icon metric-card__icon--green">✨</div>
          </div>
          <div class="metric-card__value metric-card__value--green metric-card__value--text">{{ activity?.top_skills?.[0]?.label || '—' }}</div>
          <div class="metric-card__sub">{{ activity?.top_skills?.[0]?.count ? `used ${activity.top_skills[0].count} times` : 'no skills cast yet' }}</div>
        </div>
        <div class="metric-card">
          <div class="metric-card__head">
            <div class="metric-card__label">Favorite Gathering Skill</div>
            <div class="metric-card__icon metric-card__icon--amber">⭐</div>
          </div>
          <div class="metric-card__value metric-card__value--amber metric-card__value--text">{{ favoriteSkill || '—' }}</div>
          <div class="metric-card__sub">most-performed gathering activity</div>
        </div>
        <div class="metric-card">
          <div class="metric-card__head">
            <div class="metric-card__label">Pets Collected</div>
            <div class="metric-card__icon metric-card__icon--blue">🐾</div>
          </div>
          <div class="metric-card__value metric-card__value--blue">{{ activity?.pets_collected ?? 0 }}</div>
          <div class="metric-card__sub">companions unlocked</div>
        </div>
        <div class="metric-card">
          <div class="metric-card__head">
            <div class="metric-card__label">Cosmetics Unlocked</div>
            <div class="metric-card__icon metric-card__icon--purple">👑</div>
          </div>
          <div class="metric-card__value metric-card__value--purple">{{ activity?.cosmetics_unlocked ?? 0 }}</div>
          <div class="metric-card__sub">titles, colors, banners &amp; icons</div>
        </div>
      </div>

      <div v-if="activity" class="activity-charts">
        <div class="activity-chart-card">
          <div class="activity-chart-card__title">Battles fought (14 days)</div>
          <ActivityChart type="line" color="#e8482f" v-bind="battleTrendChartData" />
        </div>
        <div class="activity-chart-card">
          <div class="activity-chart-card__title">Win / Loss</div>
          <ActivityChart type="doughnut" v-bind="winLossChartData" />
        </div>
        <div class="activity-chart-card">
          <div class="activity-chart-card__title">What you've been doing</div>
          <div class="bar-list">
            <div v-for="row in activity.activity_breakdown" :key="row.key" class="bar-list__row">
              <div class="bar-list__label">{{ row.label }}</div>
              <div class="bar-list__track">
                <div class="bar-list__fill" :style="{ width: `${Math.min(100, (row.count / barListMax(activity.activity_breakdown)) * 100)}%` }"></div>
              </div>
              <div class="ox bar-list__count">{{ row.count }}</div>
            </div>
          </div>
        </div>
        <div class="activity-chart-card">
          <div class="activity-chart-card__title">Skills you use most</div>
          <div v-if="activity.top_skills?.length" class="bar-list">
            <div v-for="row in activity.top_skills" :key="row.key" class="bar-list__row">
              <div class="bar-list__label">{{ row.label }}</div>
              <div class="bar-list__track">
                <div class="bar-list__fill bar-list__fill--alt" :style="{ width: `${Math.min(100, (row.count / barListMax(activity.top_skills)) * 100)}%` }"></div>
              </div>
              <div class="ox bar-list__count">{{ row.count }}</div>
            </div>
          </div>
          <p v-else class="fun-stats-empty">No skills cast in battle yet.</p>
        </div>
      </div>

      <div class="fun-stats-eyebrow">GATHERING &amp; CRAFTING</div>
      <div class="fun-stats-grid">
        <div v-for="s in FUN_STAT_ROWS" :key="s.key" class="fun-stat-chip">
          <div class="fun-stat-chip__glyph">{{ s.glyph }}</div>
          <div class="fun-stat-chip__value">{{ store.character[s.key] ?? 0 }}</div>
          <div class="fun-stat-chip__label">{{ s.label }}</div>
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

      <div v-for="section in customizeSections" :key="section.label" class="customize-section">
        <div class="customize-eyebrow">{{ section.label.toUpperCase() }}</div>
        <div class="customize-grid">
          <div
            v-for="row in section.rows"
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
                v-if="row.cosmetic.type === 'color' || row.cosmetic.type === 'banner'"
                class="cosmetic-card__swatch"
                :style="{ background: row.cosmetic.value }"
              ></div>
              <div v-else-if="row.cosmetic.type === 'icon'" class="cosmetic-card__icon-preview">{{ row.cosmetic.value }}</div>

              <div class="cosmetic-card__header">
                <div class="cosmetic-card__name" :style="{ color: RARITY_COLORS[row.cosmetic.rarity] }">{{ row.cosmetic.name }}</div>
                <div
                  class="cosmetic-card__rarity"
                  :style="{ background: `${RARITY_COLORS[row.cosmetic.rarity]}22`, color: RARITY_COLORS[row.cosmetic.rarity] }"
                >{{ RARITY_LABELS[row.cosmetic.rarity] }}</div>
              </div>
              <div v-if="row.quest" class="cosmetic-card__cost cosmetic-card__cost--quest">Quest: {{ row.quest }}</div>
              <div v-else-if="row.cosmetic.cost_gems > 0" class="cosmetic-card__cost">💎 {{ row.cosmetic.cost_gems }}</div>
              <div v-else class="cosmetic-card__cost">Free</div>

              <button v-if="auth.featureAccess.cosmetics === false" class="cosmetic-card__btn" disabled>Unavailable</button>
              <button v-else-if="row.active" class="cosmetic-card__btn cosmetic-card__btn--active" disabled>Equipped</button>
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
