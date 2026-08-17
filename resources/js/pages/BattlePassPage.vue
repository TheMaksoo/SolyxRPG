<script setup>
import { ref, computed, onMounted, nextTick } from 'vue';
import api from '../api/client';
import AdBanner from '../components/AdBanner.vue';
import Skeleton from '../components/Skeleton.vue';
import { useCharacterStore } from '../stores/character';

const characterStore = useCharacterStore();

const loading = ref(true);
const pass = ref(null);
const gemCost = ref(0);
const cashLabel = ref('');
const totalTiers = ref(100);
const track = ref([]);
const message = ref('');
const trackScrollEl = ref(null);
const vipBonusPct = ref(0);
const pointsIncome = ref(null);
const seasonProgress = ref(null);

const quests = ref([]);
const objTab = ref('daily');
const OBJ_TABS = [
  { key: 'daily', label: 'Daily' },
  { key: 'weekly', label: 'Weekly' },
  { key: 'monthly', label: 'Monthly' },
  { key: 'main', label: 'Main' },
  { key: 'raid', label: 'Raid' },
];

async function load() {
  const { data } = await api.get('/battlepass');
  pass.value = data.battle_pass;
  gemCost.value = data.premium_gem_cost;
  cashLabel.value = data.premium_cash_label;
  totalTiers.value = data.total_tiers;
  track.value = data.track;
  vipBonusPct.value = data.vip_bp_xp_bonus_pct || 0;
  pointsIncome.value = data.points_income;
  seasonProgress.value = data.season_progress;
}

async function loadQuests() {
  const { data } = await api.get('/quests');
  quests.value = data.quests;
}

function scrollToCurrentTier() {
  const container = trackScrollEl.value;
  if (!container || !pass.value) return;
  const tileWidth = 96; // keep in sync with .tier-tile width + gap in the scss
  const currentTier = Math.max(1, pass.value.tier);
  const target = tileWidth * (currentTier - 1) - container.clientWidth / 2 + tileWidth / 2;
  container.scrollTo({ left: Math.max(0, target), behavior: 'smooth' });
}

function showMessage(text) {
  message.value = text;
  setTimeout(() => {
    if (message.value === text) message.value = '';
  }, 4000);
}

async function unlockWithGems() {
  try {
    const { data } = await api.post('/battlepass/unlock');
    pass.value = data.battle_pass;
    showMessage('Premium unlocked!');
  } catch (e) {
    showMessage(e.response?.data?.message || 'Not enough gems.');
  }
}

async function unlockWithCash() {
  try {
    const { data } = await api.post('/store/checkout', { sku: 'pass_ashfall' });
    window.location.href = data.checkout_url;
  } catch (e) {
    showMessage(e.response?.data?.message || 'Checkout unavailable.');
  }
}

const claimSummary = ref(null);

/** Merges duplicate item names (Claim All can grant the same repair pack on several tiers) into one line. */
function mergeItems(items) {
  const byName = {};
  for (const item of items) {
    if (!byName[item.name]) byName[item.name] = { ...item };
    else byName[item.name].qty += item.qty;
  }
  return Object.values(byName);
}

async function claim(tier, trackKey) {
  try {
    const { data } = await api.post('/battlepass/claim', { tier, track: trackKey });
    pass.value = data.battle_pass;
    claimSummary.value = {
      title: `Tier ${tier} claimed (${trackKey})`,
      gold: data.reward.gold,
      gems: data.reward.gems,
      items: mergeItems(data.reward.items),
    };
  } catch (e) {
    showMessage(e.response?.data?.message || 'Could not claim that.');
  }
}

async function claimAll() {
  try {
    const { data } = await api.post('/battlepass/claim-all');
    pass.value = data.battle_pass;
    if (data.totals.tiers_claimed === 0) {
      showMessage('Nothing ready to claim.');
      return;
    }
    claimSummary.value = {
      title: `${data.totals.tiers_claimed} tier${data.totals.tiers_claimed > 1 ? 's' : ''} claimed`,
      gold: data.totals.gold,
      gems: data.totals.gems,
      items: mergeItems(data.totals.items),
    };
  } catch (e) {
    showMessage(e.response?.data?.message || 'Could not claim rewards.');
  }
}

/** Claiming a quest here (not just on the Quests page) grants points that can tier the pass up
 * immediately, so the reward track/points-to-next-tier line needs a fresh /battlepass read right after. */
async function claimQuest(row) {
  try {
    const { data } = await api.post(`/quests/${row.quest.id}/claim`);
    quests.value = data.quests;
    if (data.character) characterStore.character = data.character;
    claimSummary.value = {
      title: `${row.quest.name} claimed`,
      gold: data.reward?.gold,
      gems: data.reward?.gems,
      xp: data.reward?.xp,
      bpXp: data.reward?.bp_xp,
    };
    await load();
  } catch (e) {
    showMessage(e.response?.data?.message || 'Could not claim.');
  }
}

const hasClaimable = computed(() => {
  if (!pass.value) return false;
  const freeClaimed = pass.value.claimed_free_tiers || [];
  const premClaimed = pass.value.claimed_premium_tiers || [];
  for (let tier = 1; tier <= pass.value.tier; tier++) {
    if (!freeClaimed.includes(tier)) return true;
    if (pass.value.premium && !premClaimed.includes(tier)) return true;
  }
  return false;
});

/** How many reached tiers still have an unclaimed reward sitting on either track — the "N ready to
 * claim" counter on the reward track card. */
const claimableTierCount = computed(() => {
  if (!pass.value) return 0;
  const freeClaimed = pass.value.claimed_free_tiers || [];
  const premClaimed = pass.value.claimed_premium_tiers || [];
  let count = 0;
  for (let tier = 1; tier <= pass.value.tier; tier++) {
    if (!freeClaimed.includes(tier)) count++;
    if (pass.value.premium && !premClaimed.includes(tier)) count++;
  }
  return count;
});

// Reward-tile display: a tier can grant several items at once (e.g. a repair pack + an ore bar + class
// gear all on the same milestone tier) plus gold and sometimes gems — too much to cram legibly into an
// 86px tile as one concatenated string. Instead the tile headlines the single most exciting item (a
// cosmetic beats gear beats a bar beats a pack beats a raw material) with its real icon and name, tags
// anything left over as a compact "+N" badge, and always shows the actual gold/gem amounts as their own
// small line — so what's on the tile matches what you actually get, just prioritized instead of jumbled.
const CATEGORY_PRIORITY = { cosmetic: 0, gear: 1, bar: 2, pack: 3, material: 4 };

function primaryItem(reward) {
  if (!reward.items.length) return null;
  return [...reward.items].sort((a, b) => (CATEGORY_PRIORITY[a.category] ?? 9) - (CATEGORY_PRIORITY[b.category] ?? 9))[0];
}

function tileGlyph(row, isPremium) {
  const reward = isPremium ? row.premium_reward : row.free_reward;
  return primaryItem(reward)?.glyph ?? (reward.gems ? '💎' : '🪙');
}

function tileLabel(row, isPremium) {
  const reward = isPremium ? row.premium_reward : row.free_reward;
  const item = primaryItem(reward);
  if (!item) return reward.gems ? `${reward.gems}◆ Gems` : `${reward.gold}g Gold`;
  return item.qty > 1 ? `${item.qty}× ${item.name}` : item.name;
}

function tileExtraCount(row, isPremium) {
  const reward = isPremium ? row.premium_reward : row.free_reward;
  return Math.max(0, reward.items.length - 1);
}

/** Full breakdown for the tile's hover tooltip, since the compact on-tile text only headlines one item. */
function tileTooltip(row, isPremium) {
  const reward = isPremium ? row.premium_reward : row.free_reward;
  const parts = [];
  if (reward.gold) parts.push(`${reward.gold} gold`);
  if (reward.gems) parts.push(`${reward.gems} gems`);
  for (const item of reward.items) {
    parts.push(item.qty > 1 ? `${item.qty}× ${item.name}` : item.name);
  }
  return parts.join(' + ');
}

function tileState(tier, trackKey) {
  if (!pass.value) return 'locked';
  if (tier > pass.value.tier) return 'locked';
  const claimedList = trackKey === 'premium' ? pass.value.claimed_premium_tiers : pass.value.claimed_free_tiers;
  return (claimedList || []).includes(tier) ? 'claimed' : 'ready';
}

/** Premium's own accumulated totals across the whole season (raw premium_reward sums, NOT a delta against
 * Free) — these are meant to read as the exact numbers Premium is worth (1,000,000 gold, 1,000 gems, each
 * resource's exact season total), matching BattlePassService's reward tables 1:1. Gear and cosmetics are
 * excluded here (see 'items' below) — the pitch card only pitches currency and gathering/crafting
 * resources, not gear. */
const premiumTotals = computed(() => {
  const totals = { gold: 0, gems: 0, items: {} };
  for (const row of track.value) {
    totals.gold += row.premium_reward.gold;
    totals.gems += row.premium_reward.gems;

    for (const item of row.premium_reward.items) {
      if (item.category === 'gear' || item.category === 'cosmetic') continue;
      totals.items[item.name] = (totals.items[item.name] || 0) + item.qty;
    }
  }
  return totals;
});
const premiumResourceTotalQty = computed(() => Object.values(premiumTotals.value.items).reduce((sum, qty) => sum + qty, 0));
const premiumResourceTypeCount = computed(() => Object.keys(premiumTotals.value.items).length);
const resourcesExpanded = ref(false);

const xpIntoTier = computed(() => pass.value?.xp ?? 0);
const xpNeededForNextTier = computed(() => {
  const row = track.value.find((r) => r.tier === (pass.value?.tier ?? 0) + 1);
  return row?.xp_required ?? null;
});
const tierProgressPct = computed(() => {
  if (!xpNeededForNextTier.value) return 0;
  return Math.min(100, Math.round((xpIntoTier.value / xpNeededForNextTier.value) * 100));
});

/** Pace readout for the season-progress card — a REAL projection from actual days-active and points
 * earned so far (see BattlePassService::seasonProgress), not a simulated countdown. Compared against the
 * day-25 target the curve was actually sized for (see REACHABLE_BY_DAY_TARGET), not a fake expiry date. */
const paceOnTrack = computed(() => {
  if (!seasonProgress.value?.projected_finish_day) return null;
  return seasonProgress.value.projected_finish_day <= seasonProgress.value.reachable_by_day_target;
});
const paceLabel = computed(() => {
  if (!seasonProgress.value) return 'N/A';
  if (pass.value?.tier >= totalTiers.value) return 'Maxed!';
  if (!seasonProgress.value.projected_finish_day) return 'Not started';
  return `Day ${seasonProgress.value.projected_finish_day}`;
});
const paceSubLabel = computed(() => {
  if (!seasonProgress.value?.projected_finish_day || pass.value?.tier >= totalTiers.value) return '';
  const target = seasonProgress.value.reachable_by_day_target;
  const day = seasonProgress.value.projected_finish_day;
  return paceOnTrack.value ? `${target - day} days to spare` : `${day - target} days behind target`;
});

/** Real days remaining in the current calendar month (the season) — season_length_days is however many
 * days THIS month actually has (28-31, see BattlePassService::seasonLengthDays), days_active is today's
 * day-of-month, so the difference is always accurate without needing its own countdown timer. */
const daysLeftInSeason = computed(() => {
  if (!pointsIncome.value || !seasonProgress.value) return null;
  return Math.max(0, pointsIncome.value.season_length_days - seasonProgress.value.days_active);
});

// ---- Earn-points panel: real quest data, tabbed the same way the Quests page is ----

const GOAL_GLYPHS = {
  battles_won: '⚔',
  dungeons_cleared: '🗺',
  items_crafted: '⚒',
  zones_visited: '🧭',
  pvp_wins: '🏆',
  materials_gathered: '🌾',
  level: '★',
  skill_unlocked: '✦',
  boss_kill: '🐉',
};
function questGlyph(quest) {
  return GOAL_GLYPHS[quest.goal_json?.kind] ?? '📜';
}

function questsCompletedTarget(row) {
  return row.quest.goal_json.target ?? 1;
}
function progressPct(row) {
  return Math.min(100, Math.round((row.progress / questsCompletedTarget(row)) * 100));
}

const filteredQuests = computed(() =>
  quests.value
    .filter((r) => r.quest.type === objTab.value)
    .slice()
    .sort((a, b) => (a.claimed === b.claimed ? 0 : a.claimed ? 1 : -1))
);

const claimableCountByTab = computed(() => {
  const counts = {};
  for (const row of quests.value) {
    if (row.completed && !row.claimed) {
      counts[row.quest.type] = (counts[row.quest.type] ?? 0) + 1;
    }
  }
  return counts;
});

/** Rough countdown to this objective type's next reset — daily at local midnight, weekly next Monday,
 * monthly the 1st. Main/raid quests never reset. Approximate on purpose; it's a pacing hint, not a
 * server-authoritative clock. */
function resetLabel(type) {
  if (type === 'main' || type === 'raid') return 'One-time';

  const now = new Date();
  let target;
  if (type === 'daily') {
    target = new Date(now.getFullYear(), now.getMonth(), now.getDate() + 1);
  } else if (type === 'weekly') {
    const daysUntilMonday = ((8 - now.getDay()) % 7) || 7;
    target = new Date(now.getFullYear(), now.getMonth(), now.getDate() + daysUntilMonday);
  } else {
    target = new Date(now.getFullYear(), now.getMonth() + 1, 1);
  }

  const ms = target - now;
  const days = Math.floor(ms / 86400000);
  const hours = Math.floor((ms % 86400000) / 3600000);
  return days > 0 ? `${days}d ${hours}h` : `${hours}h`;
}

function sourceCycleLabel(source) {
  if (source.type === 'bonus') return 'one-time total';
  const unit = { daily: 'days', weekly: 'weeks', monthly: 'months' }[source.type];
  return `${source.per_cycle} pts × ${source.cycles} ${unit}`;
}

onMounted(async () => {
  await Promise.all([load(), loadQuests()]);
  loading.value = false;
  await nextTick();
  scrollToCurrentTier();
});
</script>

<template>
  <div>
    <div class="battlepass-header">
      <div>
        <div class="battlepass-header__title-line">
          <span class="ox battlepass-header__title">Ashfall Season Pass</span>
          <span v-if="pointsIncome" class="battlepass-header__season-badge">{{ pointsIncome.season_length_days }}-DAY SEASON</span>
        </div>
        <template v-if="loading">
          <Skeleton variant="text" width="130px" height="13px" />
        </template>
        <template v-else-if="pointsIncome">
          <div class="battlepass-header__xp">
            {{ pointsIncome.season_length_days }}-day season · {{ totalTiers }} tiers
            <template v-if="daysLeftInSeason !== null">
              · ends <span class="battlepass-header__days-left">{{ daysLeftInSeason }} day{{ daysLeftInSeason === 1 ? '' : 's' }}</span> from now
            </template>
          </div>
        </template>
      </div>
      <div class="battlepass-header__actions">
        <template v-if="!loading">
          <span v-if="vipBonusPct" class="battlepass-header__vip-badge">👑 +{{ vipBonusPct }}% Points (Rank)</span>
          <span v-if="pass?.premium" class="battlepass-header__premium-badge">✨ Premium unlocked</span>
          <button v-if="hasClaimable" @click="claimAll" class="battlepass-claim-all-btn">Claim All</button>
        </template>
      </div>
    </div>

    <div v-if="loading" class="battlepass-skeleton">
      <Skeleton height="130px" />
      <div class="battlepass-skeleton__columns">
        <Skeleton height="280px" />
        <Skeleton height="280px" width="322px" />
      </div>
    </div>

    <template v-else>

    <div v-if="pass" class="battlepass-progress-card">
      <div class="battlepass-progress-card__tier">
        <span class="battlepass-progress-card__tier-label">TIER</span>
        <span class="ox battlepass-progress-card__tier-value">{{ pass.tier }}</span>
        <span class="ox battlepass-progress-card__tier-max">/ {{ totalTiers }}</span>
      </div>
      <div class="battlepass-progress-card__bar">
        <div class="battlepass-progress-card__bar-row">
          <span v-if="xpNeededForNextTier">Progress to tier {{ pass.tier + 1 }}</span>
          <span v-else>Max tier reached!</span>
          <span v-if="xpNeededForNextTier" class="ox">{{ xpIntoTier }} / {{ xpNeededForNextTier }} pts</span>
        </div>
        <div class="battlepass-progress-card__bar-track">
          <div class="battlepass-progress-card__bar-fill" :style="{ width: tierProgressPct + '%' }"></div>
        </div>
        <div v-if="seasonProgress && pointsIncome" class="battlepass-progress-card__bar-sub">
          Season total {{ seasonProgress.points_earned.toLocaleString() }} / {{ pointsIncome.total_to_max.toLocaleString() }} pts
        </div>
      </div>
      <div v-if="seasonProgress" class="battlepass-progress-card__pace">
        <span class="battlepass-progress-card__pace-label">PROJECTED TIER {{ totalTiers }}</span>
        <span class="ox battlepass-progress-card__pace-value" :class="{ 'battlepass-progress-card__pace-value--off': paceOnTrack === false }">{{ paceLabel }}</span>
        <span v-if="paceSubLabel" class="battlepass-progress-card__pace-sub" :class="{ 'battlepass-progress-card__pace-sub--off': paceOnTrack === false }">{{ paceSubLabel }}</span>
      </div>
    </div>

    <p v-if="message" class="battlepass-message">{{ message }}</p>

    <div v-if="claimSummary" class="claim-summary">
      <div class="claim-summary__header">
        <span class="ox claim-summary__title">🎉 {{ claimSummary.title }}</span>
        <button class="claim-summary__close" @click="claimSummary = null">✕</button>
      </div>
      <div class="claim-summary__rows">
        <span v-if="claimSummary.gold" class="claim-summary__chip">🪙 +{{ claimSummary.gold }} gold</span>
        <span v-if="claimSummary.gems" class="claim-summary__chip">💎 +{{ claimSummary.gems }} gems</span>
        <span v-if="claimSummary.xp" class="claim-summary__chip">✨ +{{ claimSummary.xp }} xp</span>
        <span v-if="claimSummary.bpXp" class="claim-summary__chip claim-summary__chip--bp">🎖 +{{ claimSummary.bpXp }} Battle Pass Points</span>
        <span v-for="item in claimSummary.items" :key="item.name" class="claim-summary__chip">
          {{ item.glyph }} {{ item.name }}<template v-if="item.qty > 1"> ×{{ item.qty }}</template>
        </span>
      </div>
    </div>

    <AdBanner variant="inline" />

    <div v-if="pass && !pass.premium" class="battlepass-premium-pitch">
      <div class="battlepass-premium-pitch__header">
        <div class="ox battlepass-premium-pitch__title">Unlock Premium</div>
        <div class="battlepass-premium-pitch__subtitle">
          Everything below is included with Premium, for the whole season (Free gets half):
        </div>
      </div>
      <div class="battlepass-premium-pitch__totals">
        <span class="battlepass-premium-pitch__total-chip">🪙 {{ premiumTotals.gold.toLocaleString() }}g total</span>
        <span class="battlepass-premium-pitch__total-chip">💎 {{ premiumTotals.gems.toLocaleString() }}◆ total</span>
        <button
          type="button"
          class="battlepass-premium-pitch__total-chip battlepass-premium-pitch__total-chip--toggle"
          @click="resourcesExpanded = !resourcesExpanded"
        >
          📦 {{ premiumResourceTotalQty.toLocaleString() }} resources ({{ premiumResourceTypeCount }} types)
          <span class="battlepass-premium-pitch__toggle-arrow">{{ resourcesExpanded ? '▲' : '▼' }}</span>
        </button>
      </div>
      <div v-if="resourcesExpanded" class="battlepass-premium-pitch__totals battlepass-premium-pitch__totals--breakdown">
        <span
          v-for="(qty, name) in premiumTotals.items"
          :key="name"
          class="battlepass-premium-pitch__total-chip battlepass-premium-pitch__total-chip--sub"
        >
          {{ qty.toLocaleString() }}× {{ name }}
        </span>
      </div>
      <div class="battlepass-premium-pitch__actions">
        <button @click="unlockWithGems" class="battlepass-unlock-btn">Unlock: {{ gemCost }}◆</button>
        <button @click="unlockWithCash" class="battlepass-unlock-btn battlepass-unlock-btn--cash">Unlock: {{ cashLabel }}</button>
      </div>
    </div>

    <div class="battlepass-track-card">
      <div class="battlepass-track-card__head">
        <div class="battlepass-track-card__legend">
          <span class="battlepass-track-card__legend-label">REWARD TRACK</span>
          <span class="battlepass-track-card__legend-item"><span class="battlepass-track-card__swatch battlepass-track-card__swatch--free"></span>Free</span>
          <span class="battlepass-track-card__legend-item"><span class="battlepass-track-card__swatch battlepass-track-card__swatch--premium"></span>Premium</span>
        </div>
        <div class="battlepass-track-card__claimable">{{ claimableTierCount }} rewards ready to claim · scroll for all {{ totalTiers }} →</div>
      </div>
      <div class="battlepass-track" ref="trackScrollEl">
        <div class="battlepass-row">
          <div class="battlepass-row__label">FREE</div>
          <div
            v-for="row in track"
            :key="'f' + row.tier"
            class="tier-tile"
            :class="{
              'tier-tile--reached': tileState(row.tier, 'free') !== 'locked',
              'tier-tile--claimed': tileState(row.tier, 'free') === 'claimed',
            }"
            :title="tileTooltip(row, false)"
          >
            <div class="tier-tile__glyph">
              {{ tileGlyph(row, false) }}
              <span v-if="tileExtraCount(row, false)" class="tier-tile__extra">+{{ tileExtraCount(row, false) }}</span>
            </div>
            <div class="tier-tile__reward">{{ tileLabel(row, false) }}</div>
            <div class="tier-tile__currency">
              🪙{{ row.free_reward.gold }}<template v-if="row.free_reward.gems"> 💎{{ row.free_reward.gems }}</template>
            </div>
            <button
              v-if="tileState(row.tier, 'free') === 'ready'"
              @click="claim(row.tier, 'free')"
              class="tier-tile__claim-btn"
            >
              Claim
            </button>
            <div v-else-if="tileState(row.tier, 'free') === 'claimed'" class="tier-tile__claimed-tag">✓</div>
          </div>
        </div>
        <div class="battlepass-row">
          <div class="battlepass-row__label battlepass-row__label--tier">TIER</div>
          <div v-for="row in track" :key="'n' + row.tier" class="tier-number-cell">
            <div class="ox tier-number" :class="{ 'tier-number--reached': row.tier <= (pass?.tier ?? 0) }">{{ row.tier }}</div>
          </div>
        </div>
        <div class="battlepass-row">
          <div class="battlepass-row__label battlepass-row__label--premium">PREMIUM</div>
          <div
            v-for="row in track"
            :key="'p' + row.tier"
            class="tier-tile"
            :class="{
              'tier-tile--premium-reached': pass?.premium && tileState(row.tier, 'premium') !== 'locked',
              'tier-tile--claimed': tileState(row.tier, 'premium') === 'claimed',
              'tier-tile--dim': !pass?.premium,
            }"
            :title="tileTooltip(row, true)"
          >
            <div class="tier-tile__glyph">
              {{ tileGlyph(row, true) }}
              <span v-if="tileExtraCount(row, true)" class="tier-tile__extra">+{{ tileExtraCount(row, true) }}</span>
            </div>
            <div class="tier-tile__reward tier-tile__reward--premium">{{ tileLabel(row, true) }}</div>
            <div class="tier-tile__currency">
              🪙{{ row.premium_reward.gold }}<template v-if="row.premium_reward.gems"> 💎{{ row.premium_reward.gems }}</template>
            </div>
            <button
              v-if="pass?.premium && tileState(row.tier, 'premium') === 'ready'"
              @click="claim(row.tier, 'premium')"
              class="tier-tile__claim-btn tier-tile__claim-btn--premium"
            >
              Claim
            </button>
            <div v-else-if="pass?.premium && tileState(row.tier, 'premium') === 'claimed'" class="tier-tile__claimed-tag">✓</div>
          </div>
        </div>
      </div>
    </div>

    <div class="battlepass-columns">
      <div class="battlepass-earn-panel">
        <div class="battlepass-earn-panel__head">
          <div class="battlepass-earn-panel__tabs">
            <button
              v-for="t in OBJ_TABS"
              :key="t.key"
              @click="objTab = t.key"
              class="battlepass-earn-panel__tab"
              :class="{ 'battlepass-earn-panel__tab--active': objTab === t.key }"
            >
              {{ t.label }}
              <span v-if="claimableCountByTab[t.key]" class="battlepass-earn-panel__tab-badge">{{ claimableCountByTab[t.key] }}</span>
            </button>
          </div>
          <div class="battlepass-earn-panel__reset">Resets in <span>{{ resetLabel(objTab) }}</span></div>
        </div>

        <div class="battlepass-objective" v-for="row in filteredQuests" :key="row.quest.id">
          <div class="battlepass-objective__icon">{{ questGlyph(row.quest) }}</div>
          <div class="battlepass-objective__body">
            <div class="battlepass-objective__name-line">
              <span class="battlepass-objective__name" :class="{ 'battlepass-objective__name--done': row.claimed }">{{ row.quest.name }}</span>
              <span v-if="row.quest.reward_json?.bp_xp" class="battlepass-objective__xp">+{{ row.quest.reward_json.bp_xp }}</span>
            </div>
            <div class="battlepass-objective__bar-row">
              <div class="battlepass-objective__bar-track">
                <div
                  class="battlepass-objective__bar-fill"
                  :class="{ 'battlepass-objective__bar-fill--done': row.completed }"
                  :style="{ width: progressPct(row) + '%' }"
                ></div>
              </div>
              <span class="battlepass-objective__progress">{{ row.progress }}/{{ questsCompletedTarget(row) }}</span>
            </div>
          </div>
          <button
            v-if="row.completed && !row.claimed"
            @click="claimQuest(row)"
            class="battlepass-objective__btn battlepass-objective__btn--claim"
          >
            Claim
          </button>
          <span v-else-if="row.claimed" class="battlepass-objective__btn battlepass-objective__btn--done">✓</span>
          <span v-else class="battlepass-objective__btn battlepass-objective__btn--track">Track</span>
        </div>
        <p v-if="!filteredQuests.length" class="battlepass-earn-panel__empty">No {{ objTab }} quests right now.</p>
      </div>

      <div class="battlepass-sidebar">
        <div class="battlepass-earn-card" v-if="pointsIncome">
          <div class="battlepass-earn-card__head">HOW YOU EARN POINTS</div>
          <div class="battlepass-earn-card__row" v-for="src in pointsIncome.sources" :key="src.type">
            <div class="battlepass-earn-card__row-main">
              <div class="battlepass-earn-card__row-name">{{ src.label }}</div>
              <div class="battlepass-earn-card__row-detail">{{ sourceCycleLabel(src) }}</div>
            </div>
            <span class="ox battlepass-earn-card__row-total">{{ src.total.toLocaleString() }}</span>
          </div>
          <div class="battlepass-earn-card__ceiling">
            <span>Season ceiling</span>
            <span class="ox">{{ pointsIncome.grand_total_with_bonus.toLocaleString() }}</span>
          </div>
          <div class="battlepass-earn-card__note">
            {{ pointsIncome.total_to_max.toLocaleString() }} needed to hit tier {{ totalTiers }}. Daily/weekly/monthly quests
            alone cover {{ pointsIncome.recurring_pct_of_max }}% of that, so missing a few is fine.
          </div>
        </div>
      </div>
    </div>

    </template>
  </div>
</template>

<style lang="scss" src="./BattlePassPage.scss" scoped></style>
