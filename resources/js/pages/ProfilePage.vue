<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useCharacterStore } from '../stores/character';
import { useAuthStore } from '../stores/auth';
import { useGameTick } from '../composables/gameTick';
import api from '../api/client';
import { RARITY_COLORS, RARITY_LABELS } from '../rarity';
import ActivityChart from '../components/admin/ActivityChart.vue';

const RARITY_ORDER = ['common', 'rare', 'epic', 'legendary', 'mythic'];

const router = useRouter();
const store = useCharacterStore();
const auth = useAuthStore();
const achievements = ref([]);
const cosmetics = ref([]);
const view = ref('profile'); // 'profile' | 'customize'
const customizeAvailable = computed(() => auth.featureAccess.cosmetics !== false);
const message = ref('');
const panels = ref(null);

// ---- Bio / playstyle tags (purely self-description, no gameplay effect) ----
const PLAYSTYLE_TAGS = [
  'Solo-leaning', 'Duels welcome', 'Raid lead', 'Completionist', 'Casual pace',
  'Night player', 'Early bird', 'PvP focused', 'Trader', 'Helps newcomers',
];
const bioDraft = ref('');
const tagsDraft = ref([]);
const savingMeta = ref(false);

function resetMetaDraft() {
  bioDraft.value = store.character?.bio || '';
  tagsDraft.value = [...(store.character?.playstyle_tags || [])];
}

function openCustomize() {
  if (!customizeAvailable.value) return;
  resetMetaDraft();
  resetNameDraft();
  view.value = 'customize';
}

function toggleDraftTag(t) {
  const i = tagsDraft.value.indexOf(t);
  if (i >= 0) tagsDraft.value.splice(i, 1);
  else if (tagsDraft.value.length < 4) tagsDraft.value.push(t);
}

async function saveMeta() {
  savingMeta.value = true;
  try {
    const { data } = await api.patch('/profile/meta', { bio: bioDraft.value, playstyle_tags: tagsDraft.value });
    store.character.bio = data.bio;
    store.character.playstyle_tags = data.playstyle_tags;
    message.value = 'Profile details saved.';
  } catch (e) {
    message.value = e.response?.data?.message || 'Could not save profile details.';
  } finally {
    savingMeta.value = false;
  }
}

watch(customizeAvailable, (ok) => {
  if (!ok && view.value === 'customize') view.value = 'profile';
});

// ---- Rename (flat gem cost, no free tier) ----
const RENAME_COST_GEMS = 1000;
const nameDraft = ref('');
const renaming = ref(false);

function resetNameDraft() {
  nameDraft.value = store.character?.name || '';
}

async function renameCharacter() {
  if (renaming.value || !nameDraft.value.trim() || nameDraft.value === store.character.name) return;
  renaming.value = true;
  try {
    const { data } = await api.patch('/profile/rename', { name: nameDraft.value.trim() });
    store.character.name = data.character.name;
    store.character.gems = data.character.gems;
    message.value = 'Name changed.';
  } catch (e) {
    message.value = e.response?.data?.message || 'Could not change name.';
  } finally {
    renaming.value = false;
  }
}

async function loadAchievements() {
  const { data } = await api.get('/achievements');
  achievements.value = data.achievements;
  questsCompletedTarget.value = data.quests_completed;
}

// ---- Overview panels (equipped gear, recent activity, guild card, rankings, battle pass & pvp summary) ----
const panelsLoadedAtMs = ref(0);

async function loadPanels() {
  const { data } = await api.get('/profile/panels');
  panels.value = data;
  privacyDraft.value = { ...data.privacy };
  panelsLoadedAtMs.value = Date.now();
}

const referrals = ref(null);

async function loadReferrals() {
  const { data } = await api.get('/referrals');
  referrals.value = data;
}

// ---- Profile visibility (see ProfileController::updatePrivacy / Character::privacySetting) ----
// 'activity_feed_visibility' and 'open_to_duels' are real, saved preferences but don't gate anything
// yet — there's no public activity feed or duel-challenge feature on the public profile today to
// enforce them against. gear/combat/playtime are enforced (see CharacterController::publicProfile()).
const PRIVACY_DEFAULTS = { gear_visible: true, combat_stats_visible: true, activity_feed_visibility: 'public', playtime_visible: false, open_to_duels: true };
const privacyDraft = ref({ ...PRIVACY_DEFAULTS });
const savingPrivacy = ref(false);
const ACTIVITY_VISIBILITY_ORDER = ['public', 'friends', 'hidden'];

function togglePrivacyBool(key) {
  privacyDraft.value[key] = !privacyDraft.value[key];
}

function cycleActivityVisibility() {
  const i = ACTIVITY_VISIBILITY_ORDER.indexOf(privacyDraft.value.activity_feed_visibility);
  privacyDraft.value.activity_feed_visibility = ACTIVITY_VISIBILITY_ORDER[(i + 1) % ACTIVITY_VISIBILITY_ORDER.length];
}

async function savePrivacy() {
  savingPrivacy.value = true;
  try {
    const { data } = await api.patch('/profile/privacy', privacyDraft.value);
    privacyDraft.value = data.privacy;
    message.value = 'Profile visibility saved.';
  } catch (e) {
    message.value = e.response?.data?.message || 'Could not save profile visibility.';
  } finally {
    savingPrivacy.value = false;
  }
}

// The server only learns real elapsed time on the next request (see TrackCharacterActivity
// middleware) — sitting on this page with no navigation would otherwise leave the PLAYTIME tile
// frozen on whatever /profile/panels last returned. Projecting forward client-side, the same way
// useRegen() projects HP/mana/energy between server syncs, keeps it visibly climbing every tick
// while the page is open instead of only jumping on the next full reload.
const { tickCount } = useGameTick();
const nowMs = ref(Date.now());
watch(tickCount, () => { nowMs.value = Date.now(); });

const livePlaytimeSeconds = computed(() => {
  const base = panels.value?.playtime_seconds ?? 0;
  if (!panelsLoadedAtMs.value) return base;
  return base + Math.floor((nowMs.value - panelsLoadedAtMs.value) / 1000);
});

// "Xm" under an hour, "Xh Ym" under two days, "Xd Yh" beyond that — granular enough that the live
// projection above is actually visible ticking up, not just a number that happens to change hourly.
const playtimeLabel = computed(() => {
  const total = livePlaytimeSeconds.value;
  const hours = Math.floor(total / 3600);
  const minutes = Math.floor((total % 3600) / 60);
  if (hours < 1) return `${minutes}m`;
  if (hours < 48) return `${hours}h ${minutes}m`;
  return `${Math.floor(hours / 24)}d ${hours % 24}h`;
});

async function toggleShowcase(achievementId) {
  const current = store.character.showcased_achievement_ids || [];
  const has = current.includes(achievementId);
  const next = has ? current.filter((id) => id !== achievementId) : [...current, achievementId].slice(0, 5);
  if (!has && current.length >= 5) return;
  try {
    const { data } = await api.patch('/profile/showcase', { achievement_ids: next });
    store.character.showcased_achievement_ids = data.showcased_achievement_ids;
  } catch (e) {
    message.value = e.response?.data?.message || 'Could not update showcase.';
  }
}

function isShowcased(achievementId) {
  return (store.character?.showcased_achievement_ids || []).includes(achievementId);
}

const earnedAchievements = computed(() => achievements.value.filter((row) => row.earned));

// Trophy Case shows only what's actually pinned in Edit profile (up to 5) — falls back to nothing
// rather than dumping every earned achievement onto the Overview page.
const showcasedAchievements = computed(() => {
  const ids = store.character?.showcased_achievement_ids || [];
  return ids.map((id) => earnedAchievements.value.find((row) => row.achievement.id === id)).filter(Boolean);
});

async function loadCosmetics() {
  if (auth.featureAccess.cosmetics === false) return;
  try {
    const { data } = await api.get('/cosmetics');
    cosmetics.value = data.cosmetics;
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

// ---- Cosmetics (titles / colors / banners / icons / frames) ----
const COSMETIC_TYPES = [
  { key: 'title', label: 'Titles' },
  { key: 'color', label: 'Name Colors' },
  { key: 'banner', label: 'Banners' },
  { key: 'icon', label: 'Profile Icons' },
  { key: 'frame', label: 'Avatar Frames' },
];

// Pulls the season number out of a season/event-tagged unlock_event, when there is one — PvP season
// rewards and leaderboard category placements are all keyed with a trailing season number, just in
// different shapes. Used to badge these cards with "S{n}" now that they no longer sit under a labeled
// "Achievements" group to give that context away.
function eventSeason(event) {
  if (!event) return null;
  let m = /^pvp_season_(\d+)_/.exec(event);
  if (m) return m[1];
  m = /^leaderboard_category_(?:champion|top10):[a-z_]+:(\d+)$/.exec(event);
  if (m) return m[1];
  m = /^leaderboard_trophies_(?:champion|top10_banner):(\d+)$/.exec(event);
  return m ? m[1] : null;
}

// Quest/event-earned cosmetics stay a mystery until unlocked — there's no purchase decision to
// inform, so revealing the exact color/icon/title just spoils it. Gem-purchasable ones stay fully
// visible since you need to see what you're buying.
function cosmeticsOfType(type) {
  return cosmetics.value
    .filter((row) => row.cosmetic.type === type && !seasonBannerInfo(row))
    .map((row) => ({ ...row, mystery: !row.owned && !!(row.quest || row.event), seasonNum: eventSeason(row.event) }))
    .sort((a, b) => RARITY_ORDER.indexOf(a.cosmetic.rarity) - RARITY_ORDER.indexOf(b.cosmetic.rarity));
}

// ---- Season leaderboard placement banners (real per-(category,tier,season) grants — see
// LeaderboardSeasonRollover::grantSeasonBanners) — kept out of the plain Banners grid and shown in
// their own board-style section instead, matching the design's "Season Banners — Leaderboard" panel.
const SEASON_BANNER_TIER_RANK   = { champion: 'RANK #1', top3: 'TOP 3', top10: 'TOP 10', top100: 'TOP 100' };
const SEASON_BANNER_TIER_PILL   = { champion: '#1 CHAMPION', top3: 'TOP 3', top10: 'TOP 10', top100: 'TOP 100' };
const SEASON_BANNER_TIER_LAUREL = { champion: '♛', top3: '★', top10: '❖', top100: '✦' };
const SEASON_BANNER_TIER_HEX   = { champion: '#f5c542', top3: '#d4d8e0', top10: '#c8804a', top100: '#7c8794' };
const seasonBannerFilter = ref('all');

function seasonBannerInfo(row) {
  let m = /^leaderboard_season_banner:([a-z_]+):(champion|top3|top10|top100):(\d+)$/.exec(row.event || '');
  let category, tier, season;
  if (m) {
    [, category, tier, season] = m;
  } else {
    // The generic PvP-Trophies-only reward banner (LeaderboardSeasonRollover::grantReward, ranks 4-10)
    // isn't keyed per-category like the rest — without folding it in here too it'd sit alone in the
    // plain grid with just an "EVENT" pill and no board/tier context, the one card the season-banner
    // system doesn't already explain.
    m = /^leaderboard_trophies_top10_banner:(\d+)$/.exec(row.event || '');
    if (!m) return null;
    category = 'trophies';
    tier = 'top10';
    season = m[1];
  }
  const meta = (history.value?.categories || []).find((c) => c.key === category);
  return {
    category,
    tier,
    season,
    tierRank:  SEASON_BANNER_TIER_RANK[tier],
    pillLabel: SEASON_BANNER_TIER_PILL[tier],
    tierHex:   SEASON_BANNER_TIER_HEX[tier],
    laurel:    SEASON_BANNER_TIER_LAUREL[tier],
    label: meta?.label || category,
    group: meta?.group || 'COMBAT',
    // Backend now stamps the category glyph directly onto the cosmetic (see
    // LeaderboardSeasonRollover::grantSeasonBanners) — prefer that over the history-lookup fallback,
    // which used to silently show 🏆 for every board if /profile/history hadn't resolved yet.
    glyph: row.cosmetic.icon || meta?.glyph || '🏆',
  };
}

// The backend (CosmeticController::index()) only ever sends dynamic cosmetics — season banners included
// — that this character actually owns, so every row reaching here is already earned. No more "NOT
// EARNED" placeholders to browse; this is a trophy shelf of what you've placed for, not a shop.
const seasonBannerRows = computed(() =>
  cosmetics.value
    .map((row) => ({ ...row, seasonInfo: seasonBannerInfo(row) }))
    .filter((row) => row.seasonInfo)
    .filter((row) => seasonBannerFilter.value === 'all' || row.seasonInfo.group === seasonBannerFilter.value)
    .sort((a, b) => a.seasonInfo.label.localeCompare(b.seasonInfo.label) || Number(b.seasonInfo.season) - Number(a.seasonInfo.season))
);

const seasonBannerGroups = computed(() => {
  const groups = new Set(
    cosmetics.value
      .map((row) => seasonBannerInfo(row))
      .filter(Boolean)
      .map((info) => info.group)
  );
  return ['all', ...groups];
});

// Every cosmetic type is grouped the same real way it was actually unlocked — quest-locked cosmetics
// already carry `row.quest`, event-locked ones carry `row.event` (BattlePassService-granted rows are
// tagged category:'battle_pass', PvP-season/tutorial rows are category:'event' → shown as Achievements),
// and anything left over is a plain gem purchase. No invented per-type taxonomy needed.
const COSMETIC_GROUPS = [
  { key: 'achievements', label: 'Achievements' },
  { key: 'battle_pass', label: 'Battle Pass' },
  { key: 'quests', label: 'Quests' },
  { key: 'bought', label: 'Bought with Gems' },
];

function cosmeticGroupKey(row) {
  // Battle-Pass-granted cosmetics (see BattlePassService::claimTier) only ever set category:'battle_pass'
  // — they don't set unlock_event/unlock_quest_key, so this has to be checked independently rather than
  // nested under the quest/event checks below.
  if (row.cosmetic.category === 'battle_pass') return 'battle_pass';
  if (row.quest) return 'quests';
  if (row.event) return 'achievements';
  return 'bought';
}

// Short corner-pill label for the plain banner grid's crest art — same grouping cosmeticGroupKey()
// already derives, just condensed to a few characters of display text.
const KIND_PILL_LABEL = { battle_pass: 'PASS', quests: 'QUEST', achievements: 'EVENT', bought: 'STORE' };
function bannerKind(row) {
  return KIND_PILL_LABEL[cosmeticGroupKey(row)] || 'STORE';
}

const customizeSections = computed(() =>
  COSMETIC_TYPES.map((t) => {
    const rows = cosmeticsOfType(t.key);
    const groups = COSMETIC_GROUPS.map((g) => ({ ...g, rows: rows.filter((row) => cosmeticGroupKey(row) === g.key) })).filter(
      (g) => g.rows.length
    );
    return { ...t, groups, total: rows.length, owned: rows.filter((row) => row.owned).length };
  }).filter((t) => t.groups.length)
);

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

const totalGathered = computed(() => {
  const c = store.character;
  if (!c) return 0;
  return (c.times_mined ?? 0) + (c.times_chopped ?? 0) + (c.times_smelted ?? 0) + (c.times_foraged ?? 0);
});

// ---- Fun stats ---- Granular gathering-resource breakdown only — battles_lost/bosses_slain (hero line)
// and times_crafted (now in the activity_breakdown bar-list below) are deliberately excluded so nothing
// on this tab repeats a number shown elsewhere.
const FUN_STAT_ROWS = [
  { key: 'times_mined', label: 'Ore Mined', glyph: '⛏' },
  { key: 'times_chopped', label: 'Trees Chopped', glyph: '🪓' },
  { key: 'times_smelted', label: 'Bars Smelted', glyph: '🔥' },
  { key: 'times_foraged', label: 'Herbs Foraged', glyph: '🌿' },
];

// ---- Leaderboard-category history graph (real daily snapshots — see leaderboard:snapshot-daily) ----
const historyCategory = ref('power');
const history = ref(null);

async function loadHistory() {
  const { data } = await api.get('/profile/history', { params: { category: historyCategory.value } });
  history.value = data;
}

watch(historyCategory, loadHistory);

const historyGroups = computed(() => {
  const cats = history.value?.categories ?? [];
  const groups = {};
  for (const c of cats) (groups[c.group] ??= []).push(c);
  return Object.entries(groups).map(([group, categories]) => ({ group, categories }));
});

const historyChartData = computed(() => {
  const points = history.value?.points ?? [];
  return { labels: points.map((p) => p.date.slice(5)), data: points.map((p) => p.value) };
});

const heroBackground = computed(() => store.character?.active_banner?.value || null);
const activeBannerIcon = computed(() => store.character?.active_banner?.icon || null);
const activeBannerHex = computed(() => store.character?.active_banner?.accent_hex || null);

// Top-left "rank crest" on the hero art — only meaningful when the equipped banner is itself a
// season-leaderboard placement (regular catalog banners carry no rank/board info to show here).
// Reuses seasonBannerInfo()'s own event-pattern parsing (defined further down, but function
// declarations are hoisted) so this stays in lockstep with the Customize season-banner section.
const heroSeasonInfo = computed(() => {
  const banner = store.character?.active_banner;
  if (!banner) return null;
  return seasonBannerInfo({ event: banner.unlock_event, cosmetic: banner });
});
const nameColor = computed(() => store.character?.active_color?.value || null);
const activeTitleText = computed(() => store.character?.active_title?.value || null);
const activeIconGlyph = computed(() => store.character?.active_icon?.value || null);
const activeFrameColor = computed(() => store.character?.active_frame?.value || null);

// Real recency signal (Character rows save on every tick/action) — same threshold GmAnalyticsController
// uses for its "who's actually online" list, just applied here to a single character.
const isOnline = computed(() => {
  if (!store.character?.updated_at) return false;
  return Date.now() - new Date(store.character.updated_at).getTime() < 3 * 60 * 1000;
});

const memberSince = computed(() => {
  if (!store.character?.created_at) return null;
  return new Date(store.character.created_at).toLocaleDateString(undefined, { year: 'numeric', month: 'short' });
});

function copyProfileLink() {
  const url = `${window.location.origin}/characters/${store.character.id}/profile`;
  navigator.clipboard?.writeText(url);
  message.value = 'Profile link copied!';
}

// Splits "Cleared **Molten Hollow**" into plain/bold segments for the Recent Activity feed — the
// backend wraps the one key name/term per line in **, this renders it without ever using v-html (all
// segments still go through Vue's normal escaped interpolation, even though one of them came from a
// player-chosen character name).
function parseBoldSegments(text) {
  return text.split('**').map((part, i) => ({ text: part, bold: i % 2 === 1 }));
}

function timeAgo(dateStr) {
  const ms = Date.now() - new Date(dateStr).getTime();
  const min = Math.floor(ms / 60000);
  if (min < 1) return 'just now';
  if (min < 60) return `${min}m ago`;
  const hr = Math.floor(min / 60);
  if (hr < 24) return `${hr}h ago`;
  const day = Math.floor(hr / 24);
  if (day === 1) return 'Yesterday';
  if (day < 7) return `${day}d ago`;
  return new Date(dateStr).toLocaleDateString();
}

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
  loadPanels();
  loadHistory();
  loadReferrals();
});
</script>

<template>
  <div v-if="store.character">
    <p v-if="message" class="profile-message">{{ message }}</p>

    <template v-if="view === 'profile'">
      <div class="profile-hero" :style="heroBackground ? { background: heroBackground } : null">
        <div class="profile-hero__art">
          <span
            v-if="activeBannerIcon"
            class="profile-hero__banner-glyph"
            :style="{ color: activeBannerHex || 'rgba(255,255,255,.5)' }"
          >{{ activeBannerIcon }}</span>

          <div v-if="heroSeasonInfo" class="profile-hero__rank-crest">
            <span
              class="profile-hero__rank-crest-icon"
              :style="{ borderColor: heroSeasonInfo.tierHex, boxShadow: `0 0 14px ${heroSeasonInfo.tierHex}55` }"
            >{{ heroSeasonInfo.glyph }}</span>
            <div class="profile-hero__rank-crest-text">
              <span class="ox profile-hero__rank-crest-mark" :style="{ color: heroSeasonInfo.tierHex }">{{ heroSeasonInfo.tierRank }}</span>
              <span class="profile-hero__rank-crest-sub">{{ heroSeasonInfo.label.toUpperCase() }}</span>
            </div>
          </div>

          <div v-if="panels?.battle_pass_summary" class="profile-hero__art-pill">{{ panels.battle_pass_summary.season.toUpperCase() }}</div>
        </div>

        <div class="profile-hero__content">
          <div class="profile-hero__avatar-wrap">
            <div
              class="ox profile-hero__avatar"
              :class="{ 'profile-hero__avatar--icon': activeIconGlyph }"
              :style="activeFrameColor ? { borderColor: activeFrameColor, boxShadow: `0 0 22px ${activeFrameColor}44` } : null"
              :title="activeIconGlyph ? store.character.active_icon?.name : null"
            >
              {{ activeIconGlyph || store.character.name.slice(0, 2).toUpperCase() }}
            </div>
            <div class="ox profile-hero__level-badge">LV {{ store.character.level }}</div>
          </div>

          <div class="profile-hero__info">
            <div class="profile-hero__name-row">
              <div class="ox profile-hero__name" :style="nameColor ? { color: nameColor } : null">{{ store.character.name }}</div>
              <div v-if="activeTitleText" class="profile-hero__title-badge">{{ activeTitleText }}</div>
              <div class="profile-hero__online" :class="{ 'profile-hero__online--live': isOnline }">
                <span class="profile-hero__online-dot"></span>{{ isOnline ? 'Online' : 'Offline' }}<span v-if="store.character.zone"> · {{ store.character.zone.name }}</span>
              </div>
            </div>
            <div class="profile-hero__class">
              {{ store.character.spec_class || store.character.base_class }}
              <span v-if="panels?.guild_card" class="profile-hero__guild">· [{{ panels.guild_card.tag }}] {{ panels.guild_card.name }}</span>
              <span v-if="memberSince" class="profile-hero__since">· joined {{ memberSince }}</span>
            </div>
            <div class="profile-hero__meta">
              {{ store.character.battles_won }} won · {{ store.character.battles_lost ?? 0 }} lost ({{ winRate }}% win rate) · {{ store.character.bosses_slain }} bosses slain
            </div>
            <div v-if="store.character.bio" class="profile-hero__bio">{{ store.character.bio }}</div>
            <div v-if="(store.character.playstyle_tags || []).length" class="profile-hero__tags">
              <span v-for="t in store.character.playstyle_tags" :key="t" class="profile-hero__tag">{{ t }}</span>
            </div>
          </div>

          <div class="profile-hero__actions">
            <button class="profile-hero__action-btn profile-hero__action-btn--primary" @click="openCustomize">Edit profile</button>
            <button class="profile-hero__action-btn" @click="copyProfileLink">Share link</button>
          </div>
        </div>
      </div>

      <div v-if="store.stats" class="headline-grid">
        <div class="headline-tile">
          <div class="headline-tile__label">BATTLES</div>
          <div class="ox headline-tile__value">{{ battlesTotal }}</div>
          <div class="headline-tile__sub">{{ store.character.battles_won }}W · {{ store.character.battles_lost ?? 0 }}L</div>
        </div>
        <div class="headline-tile">
          <div class="headline-tile__label">WIN RATE</div>
          <div class="ox headline-tile__value headline-tile__value--green">{{ winRate }}%</div>
        </div>
        <div class="headline-tile">
          <div class="headline-tile__label">WIN STREAK</div>
          <div class="ox headline-tile__value headline-tile__value--amber">{{ panels?.pvp_summary?.win_streak ?? 0 }}</div>
        </div>
        <div class="headline-tile">
          <div class="headline-tile__label">TOTAL GATHERED</div>
          <div class="ox headline-tile__value">{{ totalGathered }}</div>
        </div>
        <div class="headline-tile">
          <div class="headline-tile__label">REFERRALS</div>
          <div class="ox headline-tile__value headline-tile__value--gold">{{ referrals?.referred?.length ?? 0 }}</div>
          <div class="headline-tile__sub">{{ referrals?.qualifying_count ?? 0 }} qualified</div>
        </div>
        <div class="headline-tile">
          <div class="headline-tile__label">BATTLE PASS TIER</div>
          <div class="ox headline-tile__value headline-tile__value--accent">{{ panels?.battle_pass_summary?.tier ?? 0 }}/{{ panels?.battle_pass_summary?.total_tiers ?? 100 }}</div>
        </div>
        <div class="headline-tile">
          <div class="headline-tile__label">PLAYTIME</div>
          <div class="ox headline-tile__value">{{ playtimeLabel }}</div>
        </div>
      </div>

      <div class="profile-columns">
        <div class="profile-columns__main">

          <div class="panel-card">
            <div class="panel-card__head">
              <span class="panel-card__eyebrow">EQUIPPED LOADOUT</span>
              <span v-if="panels?.equipped_gear?.length" class="panel-card__head-note">Gear score <b class="ox">{{ panels.gear_score }}</b></span>
            </div>
            <div v-if="panels?.equipped_gear?.length" class="loadout-grid">
              <div
                v-for="g in panels.equipped_gear"
                :key="g.name"
                class="loadout-item"
                :style="{ borderColor: `${RARITY_COLORS[g.rarity]}55` }"
              >
                <div class="loadout-item__icon" :style="{ background: `${RARITY_COLORS[g.rarity]}1a`, borderColor: `${RARITY_COLORS[g.rarity]}55` }">{{ g.glyph }}</div>
                <div class="loadout-item__info">
                  <div class="loadout-item__name" :style="{ color: RARITY_COLORS[g.rarity] }">{{ g.name }}</div>
                  <div class="loadout-item__meta">{{ g.slot }} · {{ RARITY_LABELS[g.rarity] }}</div>
                </div>
                <div v-if="g.durability_pct !== null" class="loadout-item__durability" :class="{ 'loadout-item__durability--low': g.durability_pct < 30 }">{{ g.durability_pct }}%</div>
                <div v-else class="ox loadout-item__score">{{ g.score }}</div>
              </div>
            </div>
            <p v-else class="panel-card__empty">Nothing equipped yet — visit the Inventory to gear up.</p>
          </div>

          <div v-if="panels?.combat_profile" class="panel-card">
            <div class="panel-card__head">
              <span class="panel-card__eyebrow">COMBAT PROFILE</span>
              <div class="combat-profile__legend">
                <span class="combat-profile__legend-item"><span class="combat-profile__legend-swatch"></span>{{ store.character.name }}</span>
                <span class="combat-profile__legend-item"><span class="combat-profile__legend-swatch combat-profile__legend-swatch--avg"></span>Server avg · {{ panels.combat_profile.bracket }}</span>
              </div>
            </div>
            <div class="combat-profile">
              <div v-for="row in panels.combat_profile.rows" :key="row.label" class="combat-profile__row">
                <div class="combat-profile__row-head">
                  <span class="combat-profile__row-label">{{ row.label }}</span>
                  <span class="ox combat-profile__row-value">{{ row.value }}{{ row.suffix }}</span>
                </div>
                <div class="combat-profile__track">
                  <div class="combat-profile__fill" :style="{ width: row.pct + '%' }"></div>
                  <div class="combat-profile__avg-marker" :style="{ left: row.avg_pct + '%' }"></div>
                </div>
              </div>
            </div>
          </div>

          <div v-if="store.stats" class="panel-card">
            <div class="panel-card__head">
              <span class="panel-card__eyebrow">STAT BREAKDOWN</span>
              <span class="panel-card__head-note">base value vs total with gear, attributes &amp; buffs</span>
            </div>
            <div class="stats-panel">
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
          </div>

          <div class="panel-card">
            <div class="panel-card__head">
              <span class="panel-card__eyebrow">TROPHY CASE</span>
              <span class="panel-card__head-note">{{ earnedAchievements.length }} of {{ achievements.length }} earned</span>
            </div>
            <div v-if="showcasedAchievements.length" class="trophy-grid">
              <div
                v-for="row in showcasedAchievements"
                :key="row.achievement.id"
                class="trophy-tile trophy-tile--earned"
              >
                <div class="trophy-tile__icon">{{ row.achievement.glyph }}</div>
                <div class="trophy-tile__name">{{ row.achievement.name }}</div>
                <div class="trophy-tile__desc">{{ row.achievement.description }}</div>
              </div>
            </div>
            <p v-else class="panel-card__empty">Pick up to 5 earned achievements to showcase here, in Edit profile.</p>
          </div>

          <div class="panel-card">
            <div class="panel-card__head">
              <span class="panel-card__eyebrow">{{ history?.label ?? 'HISTORY' }} OVER TIME</span>
              <select v-model="historyCategory" aria-label="History category" class="history-select">
                <optgroup v-for="grp in historyGroups" :key="grp.group" :label="grp.group">
                  <option v-for="c in grp.categories" :key="c.key" :value="c.key">{{ c.label }}</option>
                </optgroup>
              </select>
            </div>
            <ActivityChart
              v-if="historyChartData.data.length"
              type="line"
              color="#e8482f"
              :point-radius="historyChartData.data.length < 5 ? 4 : 0"
              v-bind="historyChartData"
            />
            <p v-else class="panel-card__empty">Not enough history yet — check back after a few more days.</p>
          </div>

          <div class="panel-card">
            <div class="panel-card__head">
              <span class="panel-card__eyebrow">MOST DIFFICULT ENEMIES</span>
            </div>
            <div v-if="panels?.toughest_foes?.monster || panels?.toughest_foes?.rival" class="toughest-foes">
              <div v-if="panels.toughest_foes.monster" class="toughest-foes__row">
                <span class="toughest-foes__glyph">{{ panels.toughest_foes.monster.glyph }}</span>
                <div class="toughest-foes__info">
                  <div class="toughest-foes__name">{{ panels.toughest_foes.monster.name }}</div>
                  <div class="toughest-foes__meta">Defeated you {{ panels.toughest_foes.monster.losses }}× in battle</div>
                </div>
              </div>
              <div v-if="panels.toughest_foes.rival" class="toughest-foes__row">
                <span class="toughest-foes__glyph">⚔</span>
                <div class="toughest-foes__info">
                  <div class="toughest-foes__name">{{ panels.toughest_foes.rival.name }}</div>
                  <div class="toughest-foes__meta">Beat you {{ panels.toughest_foes.rival.losses }}× in the arena</div>
                </div>
              </div>
            </div>
            <p v-else class="panel-card__empty">No losses recorded yet — keep it that way.</p>
          </div>

          <div class="fun-stats-eyebrow">GATHERING &amp; CRAFTING</div>
          <div class="fun-stats-grid">
            <div v-for="s in FUN_STAT_ROWS" :key="s.key" class="fun-stat-chip">
              <div class="fun-stat-chip__glyph">{{ s.glyph }}</div>
              <div class="fun-stat-chip__value">{{ store.character[s.key] ?? 0 }}</div>
              <div class="fun-stat-chip__label">{{ s.label }}</div>
            </div>
            <div class="fun-stat-chip">
              <div class="fun-stat-chip__glyph">📜</div>
              <div class="fun-stat-chip__value">{{ questsCompletedDisplay }}</div>
              <div class="fun-stat-chip__label">Quests Completed</div>
            </div>
          </div>

        </div>

        <div class="profile-columns__rail">

          <div v-if="panels?.battle_pass_summary" class="panel-card">
            <div class="panel-card__eyebrow">SEASON HISTORY</div>
            <div class="season-row">
              <div class="season-row__name-row">
                <span class="ox season-row__season">{{ panels.battle_pass_summary.season }}</span>
                <span class="season-row__status" :class="{ 'season-row__status--done': panels.battle_pass_summary.tier >= panels.battle_pass_summary.total_tiers }">
                  {{ panels.battle_pass_summary.tier >= panels.battle_pass_summary.total_tiers ? 'Completed' : 'In progress' }}
                </span>
              </div>
              <div class="season-row__track">
                <div class="season-row__fill" :style="{ width: Math.round((panels.battle_pass_summary.tier / panels.battle_pass_summary.total_tiers) * 100) + '%' }"></div>
              </div>
              <div class="season-row__label">
                Tier {{ panels.battle_pass_summary.tier }} / {{ panels.battle_pass_summary.total_tiers }}
                <span class="season-row__badge" :class="{ 'season-row__badge--premium': panels.battle_pass_summary.premium }">{{ panels.battle_pass_summary.premium ? 'Premium' : 'Free' }}</span>
              </div>
            </div>
          </div>

          <div class="panel-card">
            <div class="panel-card__eyebrow">RANKINGS</div>
            <div v-for="r in panels?.rankings ?? []" :key="r.category" class="ranking-row">
              <span class="ranking-row__label">{{ r.label }}</span>
              <span class="ox ranking-row__rank">{{ r.rank ? `#${r.rank}` : '—' }}</span>
            </div>
          </div>

          <div class="panel-card">
            <div class="panel-card__head">
              <span class="panel-card__eyebrow">RECENT ACTIVITY</span>
              <span v-if="panels?.recent_activity?.items?.length" class="panel-card__head-note">{{ panels.recent_activity.window }}</span>
            </div>
            <div v-if="panels?.recent_activity?.items?.length" class="activity-feed">
              <div v-for="(a, i) in panels.recent_activity.items" :key="i" class="activity-feed__row">
                <div class="activity-feed__rail">
                  <span class="activity-feed__dot" :class="`activity-feed__dot--${a.kind}`"></span>
                  <span v-if="i < panels.recent_activity.items.length - 1" class="activity-feed__line"></span>
                </div>
                <div class="activity-feed__body">
                  <div class="activity-feed__text">
                    <template v-for="(seg, si) in parseBoldSegments(a.text)" :key="si">
                      <b v-if="seg.bold">{{ seg.text }}</b><template v-else>{{ seg.text }}</template>
                    </template>
                  </div>
                  <div class="activity-feed__time">{{ timeAgo(a.time) }}</div>
                </div>
              </div>
            </div>
            <p v-else class="panel-card__empty">No recent activity yet.</p>
          </div>

          <div v-if="panels?.guild_card" class="panel-card">
            <div class="panel-card__head">
              <span class="panel-card__eyebrow">GUILD</span>
              <router-link to="/guild" class="panel-card__link-btn">View guild</router-link>
            </div>
            <div class="guild-card">
              <div class="ox guild-card__tag">{{ panels.guild_card.tag }}</div>
              <div class="guild-card__info">
                <div class="guild-card__name">{{ panels.guild_card.name }}</div>
                <div class="guild-card__meta">{{ panels.guild_card.role }} · {{ panels.guild_card.member_count }} members · Lv {{ panels.guild_card.level }}</div>
              </div>
            </div>
          </div>

          <div v-if="panels" class="panel-card">
            <div class="panel-card__eyebrow">PROFILE VISIBILITY</div>
            <p class="panel-card__hint">Control what other players see when they open your profile.</p>
            <div class="privacy-row">
              <span class="privacy-row__label">Loadout & gear score</span>
              <button class="privacy-toggle" :class="{ 'privacy-toggle--on': privacyDraft.gear_visible }" @click="togglePrivacyBool('gear_visible')">{{ privacyDraft.gear_visible ? 'PUBLIC' : 'HIDDEN' }}</button>
            </div>
            <div class="privacy-row">
              <span class="privacy-row__label">Combat stats</span>
              <button class="privacy-toggle" :class="{ 'privacy-toggle--on': privacyDraft.combat_stats_visible }" @click="togglePrivacyBool('combat_stats_visible')">{{ privacyDraft.combat_stats_visible ? 'PUBLIC' : 'HIDDEN' }}</button>
            </div>
            <div class="privacy-row">
              <span class="privacy-row__label">Activity feed</span>
              <button class="privacy-toggle" :class="{ 'privacy-toggle--on': privacyDraft.activity_feed_visibility === 'public' }" @click="cycleActivityVisibility">{{ privacyDraft.activity_feed_visibility.toUpperCase() }}</button>
            </div>
            <div class="privacy-row">
              <span class="privacy-row__label">Playtime & login times</span>
              <button class="privacy-toggle" :class="{ 'privacy-toggle--on': privacyDraft.playtime_visible }" @click="togglePrivacyBool('playtime_visible')">{{ privacyDraft.playtime_visible ? 'PUBLIC' : 'HIDDEN' }}</button>
            </div>
            <div class="privacy-row">
              <span class="privacy-row__label">Open to duel requests</span>
              <button class="privacy-toggle" :class="{ 'privacy-toggle--on': privacyDraft.open_to_duels }" @click="togglePrivacyBool('open_to_duels')">{{ privacyDraft.open_to_duels ? 'ON' : 'OFF' }}</button>
            </div>
            <button class="profile-meta-editor__save privacy-save" :disabled="savingPrivacy" @click="savePrivacy">Save changes</button>
          </div>

        </div>
      </div>
    </template>

    <template v-else-if="view === 'customize' && customizeAvailable">
      <button class="customize-back-btn" @click="view = 'profile'">← Back to profile</button>

      <div class="customize-layout">
        <div class="customize-layout__main">
          <div v-for="section in customizeSections" :key="section.key" :id="`customize-section-${section.key}`" class="customize-section">
            <div class="customize-section__head">
              <span class="customize-eyebrow">{{ section.label.toUpperCase() }}</span>
              <span class="customize-section__count">{{ section.owned }} of {{ section.total }} unlocked</span>
            </div>

            <div v-for="group in section.groups" :key="group.key" class="customize-group">
              <div v-if="group.key !== 'achievements'" class="customize-group__label">{{ group.label }}</div>
              <div class="customize-grid">
                <div
                  v-for="row in group.rows"
                  :key="row.cosmetic.id"
                  class="cosmetic-card"
                  :class="{ 'cosmetic-card--active': row.active, 'cosmetic-card--mystery': row.mystery }"
                >
                  <div v-if="row.active" class="cosmetic-card__active-badge">✓</div>
                  <div v-if="row.mystery" class="cosmetic-card__locked-badge">🔒 Locked</div>

                  <div
                    v-if="row.cosmetic.type === 'frame'"
                    class="cosmetic-card__frame-preview"
                    :class="{ 'cosmetic-card__frame-preview--dim': row.mystery }"
                    :style="{ borderColor: row.cosmetic.value, boxShadow: `0 0 14px ${row.cosmetic.value}44` }"
                  >{{ store.character.name.slice(0, 2).toUpperCase() }}</div>
                  <div
                    v-else-if="row.cosmetic.type === 'banner'"
                    class="cosmetic-card__swatch cosmetic-card__swatch--banner"
                    :class="{ 'cosmetic-card__swatch--dim': row.mystery }"
                    :style="{ background: row.cosmetic.value, '--accent-hex': row.cosmetic.accent_hex || 'rgba(255,255,255,.3)' }"
                  >
                    <template v-if="!row.mystery">
                      <span class="cosmetic-card__kind-pill">{{ bannerKind(row) }}</span>
                      <span v-if="row.cosmetic.icon" class="cosmetic-card__glyph-watermark">{{ row.cosmetic.icon }}</span>
                      <span v-if="row.cosmetic.icon" class="cosmetic-card__crest">{{ row.cosmetic.icon }}</span>
                    </template>
                  </div>
                  <div
                    v-else-if="row.cosmetic.type === 'color'"
                    class="cosmetic-card__swatch"
                    :class="{ 'cosmetic-card__swatch--dim': row.mystery }"
                    :style="{ background: row.cosmetic.value }"
                  ></div>
                  <div
                    v-else-if="row.cosmetic.type === 'icon'"
                    class="cosmetic-card__icon-preview"
                    :class="{ 'cosmetic-card__icon-preview--dim': row.mystery }"
                  >{{ row.cosmetic.value }}</div>

                  <div class="cosmetic-card__header" :class="{ 'cosmetic-card__header--locked': row.mystery }">
                    <div class="cosmetic-card__name" :style="{ color: RARITY_COLORS[row.cosmetic.rarity] }">{{ row.cosmetic.name }}</div>
                    <div class="cosmetic-card__header-badges">
                      <span v-if="row.seasonNum" class="cosmetic-card__season-tag">S{{ row.seasonNum }}</span>
                      <div
                        v-if="!row.mystery"
                        class="cosmetic-card__rarity"
                        :style="{ background: `${RARITY_COLORS[row.cosmetic.rarity]}22`, color: RARITY_COLORS[row.cosmetic.rarity] }"
                      >{{ RARITY_LABELS[row.cosmetic.rarity] }}</div>
                    </div>
                  </div>
                  <div v-if="row.quest" class="cosmetic-card__cost cosmetic-card__cost--quest">Quest: {{ row.quest }}</div>
                  <div v-else-if="row.event_label" class="cosmetic-card__cost cosmetic-card__cost--quest">🏆 {{ row.event_label }}</div>
                  <div v-else-if="row.cosmetic.cost_gems > 0" class="cosmetic-card__cost">💎 {{ row.cosmetic.cost_gems }}</div>
                  <div v-else class="cosmetic-card__cost">Free</div>

                  <button v-if="row.mystery" class="cosmetic-card__btn" disabled>Earn it to unlock</button>
                  <button v-else-if="auth.featureAccess.cosmetics === false" class="cosmetic-card__btn" disabled>Unavailable</button>
                  <button v-else-if="row.active" class="cosmetic-card__btn cosmetic-card__btn--active" disabled>Equipped</button>
                  <button v-else-if="row.owned" class="cosmetic-card__btn" @click="equip(row)">Equip</button>
                  <button v-else class="cosmetic-card__btn" @click="unlock(row)">Unlock</button>
                </div>
              </div>
            </div>

            <template v-if="section.key === 'banner' && seasonBannerRows.length">
              <div class="customize-section__head customize-section__head--sub">
                <span class="customize-group__label">SEASON BANNERS — LEADERBOARD</span>
                <span class="customize-section__hint-inline">Earned by placing top 100 on a board when its season ends — only you can see these until you show them off.</span>
              </div>
              <div class="season-banner-filters">
                <button
                  v-for="g in seasonBannerGroups"
                  :key="g"
                  type="button"
                  class="season-banner-filter"
                  :class="{ 'season-banner-filter--active': seasonBannerFilter === g }"
                  @click="seasonBannerFilter = g"
                >{{ g === 'all' ? 'All boards' : g.charAt(0) + g.slice(1).toLowerCase() }}</button>
              </div>
              <div class="customize-grid">
                <div
                  v-for="row in seasonBannerRows"
                  :key="row.cosmetic.id"
                  class="cosmetic-card cosmetic-card--season-banner"
                  :class="{ 'cosmetic-card--active': row.active }"
                >
                  <div v-if="row.active" class="cosmetic-card__active-badge">✓</div>
                  <div class="cosmetic-card__season-art" :style="{ background: row.cosmetic.value, '--tier-hex': row.seasonInfo.tierHex }">
                    <span class="cosmetic-card__season-pill">{{ row.seasonInfo.pillLabel }}</span>
                    <span class="cosmetic-card__season-laurel">{{ row.seasonInfo.laurel }}</span>
                    <div class="cosmetic-card__season-bottom">
                      <div class="cosmetic-card__season-left">
                        <div class="cosmetic-card__season-crest" :style="{ '--tier-hex': row.seasonInfo.tierHex }">{{ row.seasonInfo.glyph }}</div>
                        <div class="cosmetic-card__season-ranks">
                          <span class="cosmetic-card__season-rank ox" :style="{ color: row.seasonInfo.tierHex }">{{ row.seasonInfo.tierRank }}</span>
                          <span class="cosmetic-card__season-board">{{ row.seasonInfo.label }}</span>
                        </div>
                      </div>
                      <div class="cosmetic-card__season-season">
                        <span class="cosmetic-card__season-s ox">S{{ row.seasonInfo.season }}</span>
                        <span class="cosmetic-card__season-slabel">SEASON</span>
                      </div>
                    </div>
                  </div>
                  <div class="cosmetic-card__header">
                    <div class="cosmetic-card__name" :style="{ color: RARITY_COLORS[row.cosmetic.rarity] }">{{ row.seasonInfo.label }}</div>
                  </div>
                  <div class="cosmetic-card__cost cosmetic-card__cost--grey">{{ row.event_label }}</div>
                  <button v-if="row.active" class="cosmetic-card__btn cosmetic-card__btn--active" disabled>Equipped</button>
                  <button v-else class="cosmetic-card__btn" @click="equip(row)">Equip</button>
                </div>
              </div>
            </template>
          </div>

          <div class="customize-section">
            <div class="customize-eyebrow">PLAYSTYLE TAGS</div>
            <p class="customize-section__hint">How you want to be found by guilds and duel partners — {{ tagsDraft.length }} / 4 picked.</p>
            <div class="profile-meta-editor__tags">
              <button
                v-for="t in PLAYSTYLE_TAGS"
                :key="t"
                type="button"
                class="profile-meta-editor__tag-btn"
                :class="{ 'profile-meta-editor__tag-btn--picked': tagsDraft.includes(t) }"
                @click="toggleDraftTag(t)"
              >{{ t }}</button>
            </div>
          </div>

          <div class="customize-section">
            <div class="customize-section__head">
              <span class="customize-eyebrow">TROPHY SHOWCASE</span>
              <span class="customize-section__count">{{ (store.character.showcased_achievement_ids || []).length }} / 5 picked</span>
            </div>
            <p class="customize-section__hint">Pin up to 5 earned achievements to feature at the top of your Trophy Case.</p>
            <div v-if="earnedAchievements.length" class="achievements-grid">
              <div
                v-for="row in earnedAchievements"
                :key="'showcase-' + row.achievement.id"
                class="achievement-card achievement-card--earned achievement-card--pickable"
                :class="{ 'achievement-card--picked': isShowcased(row.achievement.id) }"
                @click="toggleShowcase(row.achievement.id)"
              >
                <div class="achievement-card__glyph">{{ row.achievement.glyph }}</div>
                <div class="achievement-card__name">{{ row.achievement.name }}</div>
                <div class="achievement-card__desc">{{ isShowcased(row.achievement.id) ? 'Showcased ✓' : 'Click to showcase' }}</div>
              </div>
            </div>
            <p v-else class="panel-card__empty">Earn an achievement to start showcasing it here.</p>
          </div>
        </div>

        <div class="customize-layout__rail">
          <div class="panel-card customize-preview" :style="heroBackground ? { background: heroBackground } : null">
            <span
              v-if="activeBannerIcon"
              class="profile-hero__banner-glyph customize-preview__banner-glyph"
              :style="{ color: activeBannerHex || 'rgba(255,255,255,.5)' }"
            >{{ activeBannerIcon }}</span>
            <div class="panel-card__eyebrow customize-preview__eyebrow">LIVE PREVIEW <span>as others see you</span></div>
            <div
              v-if="store.character.active_banner"
              class="banner-id-tag"
              :style="{ '--accent-hex': activeBannerHex || 'rgba(255,255,255,.35)' }"
            >
              <span class="banner-id-tag__crest">{{ activeBannerIcon || '🎌' }}</span>
              <span class="banner-id-tag__name">{{ store.character.active_banner.name }}</span>
            </div>
            <div
              class="ox customize-preview__avatar"
              :style="activeFrameColor ? { borderColor: activeFrameColor, boxShadow: `0 0 16px ${activeFrameColor}44` } : null"
            >{{ activeIconGlyph || store.character.name.slice(0, 2).toUpperCase() }}</div>
            <div class="ox customize-preview__name" :style="nameColor ? { color: nameColor } : null">{{ store.character.name }}</div>
            <div v-if="activeTitleText" class="profile-hero__title-badge">{{ activeTitleText }}</div>
            <div v-if="tagsDraft.length" class="profile-hero__tags">
              <span v-for="t in tagsDraft" :key="t" class="profile-hero__tag">{{ t }}</span>
            </div>
          </div>

          <div class="panel-card">
            <div class="panel-card__eyebrow">DISPLAY NAME</div>
            <input
              v-model="nameDraft"
              type="text"
              maxlength="30"
              class="customize-name-input"
              aria-label="Display name"
              @keyup.escape="resetNameDraft"
            />
            <p class="customize-hint">Renaming costs <span class="customize-hint--gems">💎 {{ RENAME_COST_GEMS }} gems</span> every time — no free tier.</p>
            <div class="profile-meta-editor__actions">
              <button
                class="profile-meta-editor__save"
                :disabled="renaming || !nameDraft.trim() || nameDraft === store.character.name || store.character.gems < RENAME_COST_GEMS"
                @click="renameCharacter"
              >Rename (💎 {{ RENAME_COST_GEMS }})</button>
            </div>
          </div>

          <div class="panel-card profile-meta-editor">
            <div class="panel-card__eyebrow">BIO</div>
            <textarea
              id="profile-bio"
              v-model="bioDraft"
              aria-label="Bio"
              maxlength="160"
              rows="3"
              class="profile-meta-editor__textarea"
              placeholder="Say something about how you play…"
            ></textarea>
            <div class="profile-meta-editor__count">{{ bioDraft.length }} / 160 · visible on your public profile</div>

            <div class="profile-meta-editor__actions">
              <button class="profile-meta-editor__save" :disabled="savingMeta" @click="saveMeta">Save changes</button>
            </div>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<style lang="scss" src="./ProfilePage.scss" scoped></style>
