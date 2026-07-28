<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import api from '../api/client';
import AdBanner from '../components/AdBanner.vue';
import VipBadge from '../components/VipBadge.vue';

// Kept in sync with LeaderboardService::CATEGORIES on the backend — label/glyph/group are display-only,
// the backend is the source of truth for which categories exist and how each is ranked.
const CATEGORIES = [
  { key: 'power', label: 'Power', glyph: '💪', suffix: '', group: 'COMBAT', rangeLocked: true },
  { key: 'level', label: 'Level', glyph: '⭐', suffix: '', group: 'COMBAT' },
  { key: 'trophies', label: 'Trophies', glyph: '🏆', suffix: '', group: 'COMBAT' },
  { key: 'monsters_slain', label: 'Monsters Slain', glyph: '⚔', suffix: '', group: 'COMBAT' },
  { key: 'bosses_slain', label: 'Boss Slayer', glyph: '👹', suffix: '', group: 'COMBAT' },
  { key: 'deaths', label: 'Deaths', glyph: '💀', suffix: '', group: 'COMBAT' },
  { key: 'gold', label: 'Richest', glyph: '🪙', suffix: 'g', group: 'WEALTH' },
  { key: 'gems', label: 'Gem Hoarder', glyph: '💎', suffix: '', group: 'WEALTH' },
  { key: 'quests_completed', label: 'Quest Completionist', glyph: '📜', suffix: '', group: 'WEALTH' },
  { key: 'times_mined', label: 'Master Miner', glyph: '⛏', suffix: '', group: 'PROFESSIONS' },
  { key: 'times_chopped', label: 'Lumberjack', glyph: '🪓', suffix: '', group: 'PROFESSIONS' },
  { key: 'times_smelted', label: 'Forge Master', glyph: '🔥', suffix: '', group: 'PROFESSIONS' },
  { key: 'times_foraged', label: 'Herbalist', glyph: '🌿', suffix: '', group: 'PROFESSIONS' },
  { key: 'times_crafted', label: 'Master Crafter', glyph: '🔨', suffix: '', group: 'PROFESSIONS' },
  { key: 'dungeon_clears', label: 'Dungeon Delver', glyph: '🗺', suffix: '', group: 'EXPLORATION' },
  { key: 'companions_collected', label: 'Companion Collector', glyph: '🐾', suffix: '', group: 'EXPLORATION' },
  { key: 'companion_ranks', label: 'Companion Master', glyph: '🎖', suffix: '', group: 'EXPLORATION' },
  { key: 'guild_power', label: 'Guild Power', glyph: '🛡', suffix: '', group: 'EXPLORATION', rangeLocked: true },
];
const GROUP_ORDER = ['COMBAT', 'WEALTH', 'PROFESSIONS', 'EXPLORATION'];

const RANGES = [
  { key: 'season', label: 'Season' },
  { key: 'weekly', label: 'Weekly' },
  { key: 'daily', label: 'Daily' },
  { key: 'all', label: 'All-time' },
];
const SCOPES = [
  { key: 'global', label: 'Global' },
  { key: 'guild', label: 'My Guild' },
  { key: 'friends', label: 'Friends' },
];

const category = ref('trophies');
const range = ref('all');
const scope = ref('global');
const page = ref(1);
const search = ref('');
const loading = ref(false);

const rows = ref([]);
const myRank = ref(null);
const myStanding = ref(null);
const myRankPage = ref(null);
const scopeEmptyReason = ref(null);
const totalShown = ref(0);
const totalPlayers = ref(0);
const season = ref(null);
const rewardTiers = ref([]);
const hallOfFame = ref([]);
const rivals = ref([]);
const myRanks = ref({});

const currentCategory = computed(() => CATEGORIES.find((c) => c.key === category.value) ?? CATEGORIES[0]);
const isRangeLocked = computed(() => !!currentCategory.value.rangeLocked);
const maxPage = computed(() => Math.max(1, Math.ceil(totalShown.value / 10)));

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get('/leaderboard', {
      params: { category: category.value, range: range.value, scope: scope.value, page: page.value },
    });
    rows.value = data.leaderboard;
    myRank.value = data.my_rank;
    myStanding.value = data.my_standing;
    myRankPage.value = data.my_rank_page;
    scopeEmptyReason.value = data.scope_empty_reason;
    totalShown.value = data.total_shown;
    totalPlayers.value = data.total_players;
    season.value = data.season;
    rewardTiers.value = data.reward_tiers;
    hallOfFame.value = data.hall_of_fame;
    rivals.value = data.rivals;
  } finally {
    loading.value = false;
  }
}

async function loadMyRanks() {
  try {
    const { data } = await api.get('/leaderboard/my-ranks');
    myRanks.value = data.ranks;
  } catch {
    // Sidebar pills are a nice-to-have — silently skip if it fails (e.g. no character yet).
  }
}

function pickCategory(key) {
  category.value = key;
  // Power/Trophies/Guild Power always show live data regardless of range — force the tab back to
  // All-time when switching to one so the (disabled) tab bar doesn't show a stale Weekly/Daily selection.
  if (CATEGORIES.find((c) => c.key === key)?.rangeLocked) {
    range.value = 'all';
  }
  page.value = 1;
  load();
}

function pickRange(key) {
  if (isRangeLocked.value) return;
  range.value = key;
  page.value = 1;
  load();
}

function pickScope(key) {
  scope.value = key;
  page.value = 1;
  load();
}

function prevPage() {
  if (page.value <= 1) return;
  page.value -= 1;
  load();
}

function nextPage() {
  if (page.value >= maxPage.value) return;
  page.value += 1;
  load();
}

function jumpToMe() {
  if (!myRankPage.value) return;
  page.value = myRankPage.value;
  load();
}

const filteredGroups = computed(() => {
  const q = search.value.trim().toLowerCase();
  return GROUP_ORDER
    .map((title) => ({
      title,
      items: CATEGORIES.filter((c) => c.group === title && (!q || c.label.toLowerCase().includes(q))),
    }))
    .filter((g) => g.items.length);
});

function fmt(n) {
  return Math.round(n ?? 0).toLocaleString('en-US');
}

function myRankFor(key) {
  return myRanks.value[key];
}

function rankColor(rank) {
  if (rank === 1) return '#eab308';
  if (rank === 2) return '#c8cdd4';
  if (rank === 3) return '#c07a3e';
  return null;
}

const podium = computed(() => {
  if (page.value !== 1 || rows.value.length < 3) return [];
  const medalOrder = [1, 0, 2]; // silver, gold, bronze — visual order left-to-right
  const medals = ['🥈', '🥇', '🥉'];
  return medalOrder.map((i, slot) => ({ row: rows.value[i], medal: medals[slot], lead: i === 0 }));
});

const now = ref(Date.now());
let clockTimer = null;

const seasonCountdown = computed(() => {
  if (!season.value) return '';
  const remaining = Math.max(0, new Date(season.value.ends_at).getTime() - now.value);
  const days = Math.floor(remaining / 86400000);
  const hours = Math.floor((remaining % 86400000) / 3600000);
  return `${days}d ${String(hours).padStart(2, '0')}h`;
});

onMounted(() => {
  load();
  loadMyRanks();
  clockTimer = setInterval(() => { now.value = Date.now(); }, 60000);
});

onUnmounted(() => {
  clearInterval(clockTimer);
});
</script>

<template>
  <div>
    <div class="lb-header">
      <div>
        <h1 class="ox lb-header__title">Leaderboard</h1>
        <div class="lb-header__season" v-if="season">
          {{ season.name }} · ends in <span class="lb-header__countdown">{{ seasonCountdown }}</span>
        </div>
      </div>
      <div class="lb-ranges">
        <button
          v-for="r in RANGES"
          :key="r.key"
          class="lb-ranges__btn"
          :class="{ 'is-active': range === r.key, 'is-disabled': isRangeLocked && r.key !== 'all' }"
          :title="isRangeLocked && r.key !== 'all' ? 'This board always shows your current standing, not a time window.' : ''"
          @click="pickRange(r.key)"
        >
          {{ r.label }}
        </button>
      </div>
    </div>

    <div class="lb-ad">
      <AdBanner variant="inline" />
    </div>

    <div class="lb-layout">
      <aside class="lb-sidebar" aria-label="Board categories">
        <label for="lb-board-search" class="sr-only">Find a board</label>
        <input id="lb-board-search" v-model="search" class="lb-sidebar__search" placeholder="Find a board…" />
        <div v-for="g in filteredGroups" :key="g.title" class="lb-sidebar__group">
          <div class="lb-sidebar__group-title">{{ g.title }}</div>
          <button
            v-for="c in g.items"
            :key="c.key"
            class="lb-sidebar__cat"
            :class="{ 'is-active': category === c.key }"
            @click="pickCategory(c.key)"
          >
            <span class="lb-sidebar__cat-glyph">{{ c.glyph }}</span>
            <span class="lb-sidebar__cat-label">{{ c.label }}</span>
            <span
              class="lb-sidebar__cat-rank"
              :style="{ color: category === c.key ? '#ff8163' : (myRankFor(c.key) && myRankFor(c.key) <= 3 ? '#eab308' : null) }"
            >{{ myRankFor(c.key) ? `#${myRankFor(c.key)}` : '—' }}</span>
          </button>
        </div>
      </aside>

      <div class="lb-main">
        <div class="lb-board-head">
          <div class="lb-board-head__info">
            <div class="lb-board-head__icon">{{ currentCategory.glyph }}</div>
            <div>
              <div class="ox lb-board-head__name">{{ currentCategory.label }}</div>
            </div>
          </div>
          <div class="lb-scopes">
            <button
              v-for="s in SCOPES"
              :key="s.key"
              class="lb-scopes__btn"
              :class="{ 'is-active': scope === s.key }"
              @click="pickScope(s.key)"
            >
              {{ s.label }}
            </button>
          </div>
        </div>

        <div v-if="scopeEmptyReason === 'not_in_guild'" class="lb-empty-scope">
          You're not in a guild yet. <router-link to="/guild">Join or found one</router-link> to compare with guildmates.
        </div>
        <div v-else-if="scopeEmptyReason === 'no_friends'" class="lb-empty-scope">
          Add some <router-link to="/friends">friends</router-link> to compare rankings with them.
        </div>

        <template v-else>
          <div v-if="podium.length" class="lb-podium">
            <div v-for="p in podium" :key="p.row.character_id ?? p.row.name" class="lb-podium__card" :class="{ 'lb-podium__card--lead': p.lead }">
              <div class="lb-podium__medal">{{ p.medal }}</div>
              <div class="lb-podium__avatar" :class="{ 'lb-podium__avatar--lead': p.lead }" :style="{ borderColor: p.lead ? '#eab308' : '#c8cdd4' }">
                {{ p.row.icon || '✦' }}
              </div>
              <div class="ox lb-podium__name">{{ p.row.name }}</div>
              <div class="lb-podium__sub">{{ p.row.guild_name ?? p.row.base_class }}</div>
              <div class="ox lb-podium__value" :style="{ color: p.lead ? '#eab308' : '#c8cdd4' }">{{ fmt(p.row.value) }}{{ currentCategory.suffix }}</div>
            </div>
          </div>

          <div class="lb-table">
            <div class="lb-table__head">
              <div>RANK</div><div>PLAYER</div><div>GUILD</div><div>CLASS</div><div class="lb-table__num">{{ currentCategory.label.toUpperCase() }}</div>
            </div>

            <component
              :is="row.character_id ? 'router-link' : 'div'"
              v-for="row in rows"
              :key="row.character_id ?? row.name"
              :to="row.character_id ? { name: 'public-profile', params: { id: row.character_id } } : undefined"
              class="lb-row"
              :class="{ 'lb-row--top': row.rank <= 3 }"
              :style="row.banner ? { background: row.banner } : null"
            >
              <div class="ox lb-row__rank" :style="{ color: rankColor(row.rank) }">#{{ row.rank }}</div>
              <div class="lb-row__player">
                <span class="lb-row__avatar">{{ row.icon || '✦' }}</span>
                <span class="ox lb-row__name" :style="row.name_color ? { color: row.name_color } : null">{{ row.name }}</span>
                <span v-if="row.title" class="lb-row__title-badge">{{ row.title }}</span>
                <VipBadge :tier="row.vip_tier" />
              </div>
              <div class="lb-row__meta">{{ row.guild_name ?? '—' }}</div>
              <div class="lb-row__meta">{{ row.base_class }}{{ row.level ? ` · Lv.${row.level}` : '' }}</div>
              <div class="ox lb-row__value">
                {{ fmt(row.value) }}{{ currentCategory.suffix }}
                <span
                  v-if="row.trophy_rank"
                  class="lb-row__trophy"
                  :style="{ color: row.trophy_rank.color }"
                >{{ row.trophy_rank.name }}</span>
              </div>
            </component>

            <div v-if="!rows.length && !loading" class="lb-empty">No ranked characters yet.</div>

            <router-link
              v-if="myRank"
              :to="myRank.character_id ? { name: 'public-profile', params: { id: myRank.character_id } } : {}"
              class="lb-row lb-row--me"
            >
              <div class="ox lb-row__rank" style="color:#ff8163">#{{ myRank.rank }}</div>
              <div class="lb-row__player">
                <span class="lb-row__avatar">{{ myRank.icon || '✦' }}</span>
                <span class="ox lb-row__name">{{ myRank.name }}</span>
                <span class="lb-row__you-tag">YOU</span>
              </div>
              <div class="lb-row__meta">{{ myRank.guild_name ?? '—' }}</div>
              <div class="lb-row__meta">{{ myRank.base_class }}{{ myRank.level ? ` · Lv.${myRank.level}` : '' }}</div>
              <div class="ox lb-row__value" style="color:#ff8163">{{ fmt(myRank.value) }}{{ currentCategory.suffix }}</div>
            </router-link>
          </div>

          <div class="lb-pagination">
            <div class="lb-pagination__info">Showing {{ (page - 1) * 10 + 1 }}–{{ Math.min(page * 10, totalShown) }} of {{ fmt(totalPlayers) }} ranked · updates every 5 min</div>
            <div class="lb-pagination__btns">
              <button :disabled="page <= 1" @click="prevPage">← Prev</button>
              <button :disabled="page >= maxPage" @click="nextPage">Next →</button>
              <button v-if="myRankPage && myRankPage !== page" class="lb-pagination__jump" @click="jumpToMe">Jump to me</button>
            </div>
          </div>
        </template>
      </div>

      <aside class="lb-rail" aria-label="Season summary">
        <div class="lb-card" v-if="myStanding">
          <div class="lb-card__eyebrow">YOUR STANDING</div>
          <div class="lb-standing__rank">#{{ myStanding.rank }}</div>
          <div class="lb-standing__pct">Top {{ totalPlayers ? ((myStanding.rank / totalPlayers) * 100).toFixed(1) : '—' }}% of {{ fmt(totalPlayers) }} heroes</div>
          <template v-if="myStanding.rank > 1">
            <div class="lb-standing__divider"></div>
            <div class="lb-standing__next">Next rank needs <strong>+{{ fmt(myStanding.points_to_next_rank) }}</strong> {{ currentCategory.label.toLowerCase() }}</div>
            <div class="lb-standing__track"><div class="lb-standing__fill" :style="{ width: (myStanding.points_to_next_rank > 0 ? Math.max(6, 100 - Math.min(100, myStanding.points_to_next_rank / Math.max(1, myStanding.value) * 100)) : 100) + '%' }"></div></div>
          </template>
        </div>

        <div class="lb-card">
          <div class="lb-card__eyebrow">SEASON REWARDS</div>
          <div v-for="t in rewardTiers" :key="t.label" class="lb-reward-row">
            <span class="ox lb-reward-row__tier">{{ t.label }}</span>
            <span class="lb-reward-row__prize">
              {{ fmt(t.gold) }}g<span v-if="t.gems"> · {{ t.gems }}◆</span><span v-if="t.title"> · Champion title</span><span v-if="t.banner"> · Top 10 banner</span>
            </span>
          </div>
        </div>

        <div class="lb-card">
          <div class="lb-card__eyebrow">FRIENDS</div>
          <div v-if="!rivals.length" class="lb-empty-small">Add friends to compare rankings.</div>
          <div v-for="r in rivals" :key="r.character_id" class="lb-rival-row">
            <router-link :to="{ name: 'public-profile', params: { id: r.character_id } }" class="lb-rival-row__name">{{ r.name }}</router-link>
            <span class="lb-rival-row__gap" :style="{ color: r.gap >= 0 ? '#4ade80' : '#e8482f' }">
              {{ r.gap >= 0 ? '+' : '' }}{{ fmt(r.gap) }}
            </span>
          </div>
          <router-link to="/friends" class="lb-card__manage-btn">Manage friends</router-link>
        </div>

        <div class="lb-card">
          <div class="lb-card__eyebrow">HALL OF FAME</div>
          <div v-if="!hallOfFame.length" class="lb-empty-small">No seasons have ended yet.</div>
          <div v-for="h in hallOfFame" :key="h.season" class="lb-hof-row">
            <span class="lb-hof-row__season">{{ h.season }}</span>
            <span class="ox lb-hof-row__name">{{ h.name }}</span>
          </div>
        </div>
      </aside>
    </div>
  </div>
</template>

<style lang="scss" src="./LeaderboardPage.scss" scoped></style>
