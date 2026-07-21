<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import api from '../../api/client';
import { useAuthStore } from '../../stores/auth';
import GmContentEditor from './GmContentEditor.vue';
import ActivityChart from '../../components/admin/ActivityChart.vue';
import InfoTooltip from '../../components/InfoTooltip.vue';
import Skeleton from '../../components/Skeleton.vue';
import { RESOURCE_SCHEMAS } from './resourceSchemas';
import { NAV } from '../../navigation';

const route = useRoute();
const auth = useAuthStore();

// Purely illustrative, frontend-only badge — no backend call needed since auth.user already carries role/name.
const roleBadge = computed(() => {
  if (auth.user?.role === 'owner') return { label: '★ OWNER', class: 'is-owner' };
  if (auth.user?.role === 'gm') return { label: 'GM', class: 'is-gm' };
  return { label: auth.user?.role?.toUpperCase() || '', class: '' };
});
const initials = computed(() =>
  (auth.user?.name || '')
    .split(' ')
    .map((w) => w[0])
    .filter(Boolean)
    .slice(0, 2)
    .join('')
    .toUpperCase()
);

// Simple resource-type icon swatches for the content-count tiles — purely decorative, contentCounts
// data itself is untouched.
const RESOURCE_ICONS = {
  items: '⚔', monsters: '👹', zones: '🗺', dungeons: '🏰', quests: '📜',
  skills: '✨', recipes: '🔨', pets: '🐾', events: '📅', cosmetics: '👑', known_bugs: '🐞', changelogs: '📜',
};

// Real "this month" revenue metrics, fed by GET /gm/metrics (see GmMetricsController).
const metrics = ref(null);
function formatCents(cents) {
  return ((cents || 0) / 100).toFixed(2);
}
const TAB_KEYS = ['overview', 'activity', 'content', 'players', 'economy', 'revenue', 'flags', 'tickets', 'broadcast', 'audit'];
// Supports deep-linking straight to a tab (e.g. /admin?tab=tickets from the Settings page's
// "manage tickets" link) while still defaulting to the overview tab otherwise.
const tab = ref(TAB_KEYS.includes(route.query.tab) ? route.query.tab : 'overview');
const TABS = [
  { key: 'overview', label: 'Overview' },
  { key: 'activity', label: 'Activity' },
  { key: 'content', label: 'Content' },
  { key: 'players', label: 'Players' },
  { key: 'economy', label: 'Economy' },
  { key: 'revenue', label: 'Revenue' },
  { key: 'flags', label: 'Feature Flags' },
  { key: 'tickets', label: 'Tickets' },
  { key: 'broadcast', label: 'Broadcast' },
  { key: 'audit', label: 'Audit Log' },
];

// Activity tab — real engagement analytics (signups/battles/active-character trend, content interest,
// class & level distribution). See GmAnalyticsController for the query side.
const analytics = ref(null);
const analyticsRangeDays = ref(30);

async function loadActivity() {
  const { data } = await api.get('/gm/analytics', { params: { days: analyticsRangeDays.value } });
  analytics.value = data;
}

function formatPct(v) {
  return v === null || v === undefined ? '—' : `${v}%`;
}

// Error log drill-down — the Activity tab's "Errors" cards only ever showed a bare count with nowhere
// to click through to, so a GM seeing "1 error" had no way to find out what it actually was. This lazily
// loads the real log rows (see GmErrorLogController) the first time the panel is opened.
const errorLogOpen = ref(false);
const errorLog = ref(null);
const expandedErrorId = ref(null);

async function toggleErrorLog() {
  errorLogOpen.value = !errorLogOpen.value;
  if (errorLogOpen.value && !errorLog.value) {
    const { data } = await api.get('/gm/errors');
    errorLog.value = data;
  }
}

function toggleErrorTrace(id) {
  expandedErrorId.value = expandedErrorId.value === id ? null : id;
}

// Doesn't delete the row outright — just hides it from the list and starts the 7-day purge clock (see
// CleanupStaleData), in case a "fixed" error turns out to need a second look.
async function clearError(log) {
  await api.post(`/gm/errors/${log.id}/archive`);
  errorLog.value.logs = errorLog.value.logs.filter((l) => l.id !== log.id);
}

// Anything within the last hour gets flagged as fresh, so a GM re-opening the panel can immediately
// spot new crashes rather than re-scanning the whole list against memory of what was there last time.
function isNewError(isoString) {
  return Date.now() - new Date(isoString).getTime() < 60 * 60 * 1000;
}

function timeAgo(isoString) {
  const seconds = Math.max(0, Math.round((Date.now() - new Date(isoString).getTime()) / 1000));
  if (seconds < 60) return `${seconds}s ago`;
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
  if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`;
  return `${Math.floor(seconds / 86400)}d ago`;
}

const classChartData = computed(() => {
  const dist = analytics.value?.class_distribution ?? {};
  return { labels: Object.keys(dist), data: Object.values(dist) };
});

const levelChartData = computed(() => {
  const rows = analytics.value?.level_distribution ?? [];
  return { labels: rows.map((r) => r.bucket), data: rows.map((r) => r.count) };
});

const contentInterestChartData = computed(() => {
  const rows = analytics.value?.content_interest ?? [];
  return { labels: rows.map((r) => r.label), data: rows.map((r) => r.count) };
});

const referralFunnelChartData = computed(() => {
  const funnel = analytics.value?.referral_funnel;
  if (!funnel) return { labels: [], data: [] };
  return { labels: ['Pending', 'Qualified (finished)'], data: [funnel.pending, funnel.qualified] };
});

const levelGrowthChartData = computed(() => {
  const growth = analytics.value?.level_growth;
  if (!growth) return { labels: [], data: [] };
  return { labels: growth.labels.map((l) => `Lv.${l}`), data: growth.data };
});

// Total kills from level 1 to reach each level, averaged across every monster reachable along the way —
// a rough grind-pace gut check every 10 levels, shown as chips under the Level growth chart (see
// GmAnalyticsController::killsToLevelUp()).
const killsToLevelUp = computed(() => analytics.value?.kills_to_level_up ?? []);

// Merges the backend's zone/dungeon unlock rows with navigation.js's own unlockLevel entries (Shop,
// World Map, etc.) — that file is already the single source of truth for what unlocks at what level in
// the sidebar, so it's read directly here rather than duplicated server-side.
const unlockTimeline = computed(() => {
  const serverRows = analytics.value?.unlock_timeline ?? [];
  const navRows = NAV.filter((n) => n.unlockLevel).map((n) => ({ level: n.unlockLevel, name: n.label, type: 'feature' }));
  return [...serverRows, ...navRows]
    .sort((a, b) => a.level - b.level)
    .map((row) => ({ ...row, cumulative_xp: row.cumulative_xp ?? cumulativeXpForLevel(row.level) }));
});

// Same formula as GmAnalyticsController::cumulativeXpForLevel() — needed here only for the nav-feature
// rows merged in above, which don't come from the backend and so never carry a cumulative_xp value.
function cumulativeXpForLevel(level) {
  let total = 0;
  for (let i = 1; i < level; i++) total += 500 + 800 * (i - 1);
  return total;
}

// Unlocks plotted on their own axis — one stacked bar per level that unlocks ANYTHING (level 1 through
// the last such level), height = how many things unlock there, color-split by type so the mix is
// readable at a glance instead of one flat count. Hovering a segment lists that type's actual unlock
// names at that level; the full sorted table stays below so nothing requires hovering to be found.
const UNLOCK_TYPE_COLORS = {
  zone: '#4ade80',
  dungeon: '#e8482f',
  feature: '#5cc7f5',
  monster: '#ff8163',
  skill: '#a78bfa',
  recipe: '#eab308',
};
const UNLOCK_TYPE_LABELS = { zone: 'Zone', dungeon: 'Dungeon', feature: 'Feature', monster: 'Monster', skill: 'Skill', recipe: 'Recipe' };

function truncateNames(names, max = 8) {
  return names.length > max ? [...names.slice(0, max), `+${names.length - max} more`] : names;
}

const unlockChartData = computed(() => {
  const byLevel = new Map();
  for (const row of unlockTimeline.value) {
    if (!byLevel.has(row.level)) byLevel.set(row.level, {});
    (byLevel.get(row.level)[row.type] ??= []).push(row.name);
  }
  const levels = [...byLevel.keys()].sort((a, b) => a - b);
  const typesPresent = Object.keys(UNLOCK_TYPE_COLORS).filter((t) => levels.some((lvl) => byLevel.get(lvl)[t]));

  return {
    labels: levels.map((lvl) => `Lv.${lvl}`),
    datasets: typesPresent.map((type) => ({
      label: UNLOCK_TYPE_LABELS[type],
      color: UNLOCK_TYPE_COLORS[type],
      data: levels.map((lvl) => (byLevel.get(lvl)[type] ?? []).length),
      // Each entry is an array of names, not a joined string — ActivityChart renders one tooltip line
      // per array item (Chart.js supports an array return from its label callback for this). Capped at
      // 8 names + a "+N more" line so a level with many unlocks (e.g. Lv.8) can't grow a tooltip taller
      // than the chart's own canvas, which would just get clipped instead of actually being visible.
      tooltipLabels: levels.map((lvl) => truncateNames(byLevel.get(lvl)[type] ?? [])),
    })),
  };
});

function seriesChartData(key) {
  const rows = analytics.value?.daily?.[key] ?? [];
  return {
    labels: rows.map((r) => r.date.slice(5)),
    data: rows.map((r) => r.count),
  };
}

// Overview — a curated "what matters right now" summary: quick totals (from Activity's analytics),
// game-content totals, an action-items panel (open tickets/bug reports), and the tester-mode quick
// toggle. The full Feature Flags table and Revenue breakdown live in their own tabs (see loadFlags()/
// loadRevenue() below) so Overview stays a glance, not another wall of tables.
const flags = ref([]);
const contentCounts = ref({});

async function loadOverview() {
  await Promise.all([loadFlags(), loadActivity(), loadTickets()]);

  const entries = await Promise.all(
    Object.keys(RESOURCE_SCHEMAS).map(async (key) => {
      const { data } = await api.get(`/gm/${key}`);
      return [key, data[key].length];
    })
  );
  contentCounts.value = Object.fromEntries(entries);
}

async function loadFlags() {
  const { data } = await api.get('/gm/feature-flags');
  flags.value = data.flags;
}

async function toggleFlag(flag, field) {
  const { data } = await api.put(`/gm/feature-flags/${flag.id}`, { [field]: !flag[field] });
  Object.assign(flag, data.flag);
}

// The tester-mode banner is a second UI entry point onto the same flag row/toggleFlag() call the
// Feature Flags tab's table uses — no separate endpoint or state.
const globalTesterFlag = computed(() => flags.value.find((f) => f.key === 'global_tester_mode'));

async function loadRevenue() {
  const { data } = await api.get('/gm/metrics');
  metrics.value = data;
}

const openBugReportCount = computed(
  () => tickets.value.filter((t) => t.category === 'bug' && ['open', 'pending'].includes(t.status)).length
);

// Content tab
const resource = ref('items');

// Players
const players = ref([]);
const search = ref('');
const grantForm = ref({});
const playerMessage = ref('');
const cosmeticOptions = ref([]);

async function loadCosmeticOptions() {
  if (cosmeticOptions.value.length) return;
  const { data } = await api.get('/gm/cosmetics');
  cosmeticOptions.value = data.cosmetics ?? [];
}

async function loadPlayers() {
  const { data } = await api.get('/gm/players', { params: { search: search.value } });
  players.value = data.players;
  for (const user of data.players) {
    if (!grantForm.value[user.id]) {
      grantForm.value[user.id] = { gold: '', gems: '', item_id: '', cosmetic_id: '' };
    }
    if (!mailForm.value[user.id]) {
      mailForm.value[user.id] = { subject: '', body: '' };
    }
  }
  loadCosmeticOptions();
}

const mailForm = ref({});

async function sendMail(user) {
  playerMessage.value = '';
  const form = mailForm.value[user.id] || {};
  if (!form.subject?.trim() || !form.body?.trim()) return;
  try {
    await api.post(`/gm/players/${user.id}/mail`, { subject: form.subject, body: form.body });
    form.subject = '';
    form.body = '';
    playerMessage.value = `Mail sent to ${user.name}.`;
  } catch (e) {
    playerMessage.value = e.response?.data?.message || 'Could not send mail.';
  }
}

async function grant(user) {
  playerMessage.value = '';
  const form = grantForm.value[user.id] || {};
  try {
    await api.post(`/gm/players/${user.id}/grant`, {
      gold: form.gold ? Number(form.gold) : undefined,
      gems: form.gems ? Number(form.gems) : undefined,
      item_id: form.item_id ? Number(form.item_id) : undefined,
      cosmetic_id: form.cosmetic_id ? Number(form.cosmetic_id) : undefined,
    });
    playerMessage.value = `Granted to ${user.name}.`;
  } catch (e) {
    playerMessage.value = e.response?.data?.message || 'Grant failed.';
  }
}

async function ban(user) {
  const verb = user.banned_at ? 'Unban' : 'Ban';
  if (!confirm(`${verb} ${user.name}?`)) return;
  const { data } = await api.post(`/gm/players/${user.id}/ban`);
  user.banned_at = data.banned ? new Date().toISOString() : null;
  playerMessage.value = data.message;
}

// Edit user modal
const editUserOpen = ref(false);
const editUserTarget = ref(null);
const editUserForm = ref({});
const editUserMessage = ref('');

function openEditUser(user) {
  editUserTarget.value = user;
  editUserMessage.value = '';
  editUserForm.value = {
    role: user.role,
    is_tester: !!user.is_tester,
    vip_tier: user.vip_tier || 'none',
    vip_expires_at: user.vip_expires_at ? user.vip_expires_at.slice(0, 10) : '',
    banned_reason: user.banned_reason || '',
    level: user.character?.level ?? '',
    xp: user.character?.xp ?? '',
    gold: user.character?.gold ?? '',
    gems: user.character?.gems ?? '',
    hp: user.character?.hp ?? '',
    hp_max: user.character?.hp_max ?? '',
    mana: user.character?.mana ?? '',
    mana_max: user.character?.mana_max ?? '',
    energy: user.character?.energy ?? '',
    energy_max: user.character?.energy_max ?? '',
  };
  editUserOpen.value = true;
}

function closeEditUser() {
  editUserOpen.value = false;
  editUserTarget.value = null;
}

async function saveEditUser() {
  if (!editUserTarget.value) return;
  editUserMessage.value = '';
  const form = editUserForm.value;
  const payload = {
    role: form.role || undefined,
    is_tester: form.is_tester,
    vip_tier: form.vip_tier || undefined,
    vip_expires_at: form.vip_expires_at || undefined,
    banned_reason: form.banned_reason === '' ? undefined : form.banned_reason,
    level: form.level === '' ? undefined : Number(form.level),
    xp: form.xp === '' ? undefined : Number(form.xp),
    gold: form.gold === '' ? undefined : Number(form.gold),
    gems: form.gems === '' ? undefined : Number(form.gems),
    hp: form.hp === '' ? undefined : Number(form.hp),
    hp_max: form.hp_max === '' ? undefined : Number(form.hp_max),
    mana: form.mana === '' ? undefined : Number(form.mana),
    mana_max: form.mana_max === '' ? undefined : Number(form.mana_max),
    energy: form.energy === '' ? undefined : Number(form.energy),
    energy_max: form.energy_max === '' ? undefined : Number(form.energy_max),
  };
  try {
    await api.put(`/gm/players/${editUserTarget.value.id}/edit`, payload);
    closeEditUser();
    playerMessage.value = 'Player updated.';
    await loadPlayers();
  } catch (e) {
    editUserMessage.value = e.response?.data?.message || 'Could not save changes.';
  }
}

async function clearStuckState() {
  if (!editUserTarget.value) return;
  editUserMessage.value = '';
  try {
    const { data } = await api.post(`/gm/players/${editUserTarget.value.id}/clear-stuck-state`);
    editUserMessage.value = data.message;
  } catch (e) {
    editUserMessage.value = e.response?.data?.message || 'Could not clear stuck state.';
  }
}

// Economy
const config = ref([]);

async function loadConfig() {
  const { data } = await api.get('/gm/config');
  config.value = data.config;
}

async function saveConfig(row) {
  await api.put(`/gm/config/${row.key}`, { value: row.value });
}

// Sensible bounded ranges per config-key pattern, matched against GmConfigController::DEFAULT_CONFIG's
// naming conventions — a handful of keys (e.g. crafted_value_min_base) have no obvious bound and are
// deliberately left unmatched so they fall back to a plain number input.
const SLIDER_RANGES = [
  { test: /_mult$/, range: { min: 0, max: 5, step: 0.05 } },
  { test: /^drop_rate$/, range: { min: 0, max: 100, step: 1 } },
  { test: /^luck_combat_bonus_per_point$/, range: { min: 0, max: 0.1, step: 0.001 } },
  { test: /^luck_combat_bonus_cap$/, range: { min: 0, max: 1, step: 0.01 } },
  { test: /_pct$/, range: { min: -50, max: 150, step: 1 } },
  { test: /_factor$/, range: { min: 0, max: 2, step: 0.05 } },
  { test: /_weight$/, range: { min: 0, max: 20, step: 0.5 } },
  { test: /_divisor$/, range: { min: 1, max: 50, step: 1 } },
  { test: /^vip_luck_/, range: { min: 0, max: 30, step: 1 } },
  { test: /^vip_(regen_flat|energy_flat|craft_queue_bonus)_/, range: { min: 0, max: 20, step: 1 } },
  { test: /^(auto_battle_gem_cost|auto_gather_gem_cost)_/, range: { min: 0, max: 200, step: 1 } },
  { test: /^vip_monthly_gems_/, range: { min: 0, max: 500, step: 5 } },
];
function sliderRange(key) {
  return SLIDER_RANGES.find((r) => r.test.test(key))?.range ?? null;
}

// Tickets
const tickets = ref([]);

const openTicketCount = ref(0);
const showArchived = ref(false);

// Resolved/closed tickets are archived out of the default view once handled — keeps the working
// list focused on what still needs attention instead of accumulating every ticket ever filed.
const activeTickets = computed(() => tickets.value.filter((t) => !['resolved', 'closed'].includes(t.status)));
const archivedTickets = computed(() => tickets.value.filter((t) => ['resolved', 'closed'].includes(t.status)));

async function loadTickets() {
  const { data } = await api.get('/gm/tickets');
  tickets.value = data.tickets;
  openTicketCount.value = tickets.value.filter((t) => ['open', 'pending'].includes(t.status)).length;
}

async function resolveTicket(ticket, status) {
  await api.post(`/gm/tickets/${ticket.id}/resolve`, { status });
  await loadTickets();
}

const expandedTicket = ref(null);
const ticketReplyBody = ref('');

function toggleTicketThread(ticket) {
  expandedTicket.value = expandedTicket.value === ticket.id ? null : ticket.id;
  ticketReplyBody.value = '';
}

async function sendTicketReply(ticket) {
  if (!ticketReplyBody.value.trim()) return;
  await api.post(`/gm/tickets/${ticket.id}/messages`, { body: ticketReplyBody.value });
  ticketReplyBody.value = '';
  await loadTickets();
}

// Broadcast
const broadcastBody = ref('');
const broadcastMessage = ref('');

async function sendBroadcast() {
  broadcastMessage.value = '';
  if (!broadcastBody.value.trim()) return;
  await api.post('/gm/broadcast', { body: broadcastBody.value.trim() });
  broadcastBody.value = '';
  broadcastMessage.value = 'Broadcast sent.';
}

// Audit Log
const auditLogs = ref([]);

async function loadAuditLog() {
  const { data } = await api.get('/gm/audit-log');
  auditLogs.value = data.logs;
}

function switchTab(key) {
  tab.value = key;
  if (key === 'overview') loadOverview();
  if (key === 'activity') { loadActivity(); loadRevenue(); }
  if (key === 'players') loadPlayers();
  if (key === 'economy') loadConfig();
  if (key === 'revenue') loadRevenue();
  if (key === 'flags') loadFlags();
  if (key === 'tickets') loadTickets();
  if (key === 'audit') loadAuditLog();
}

onMounted(() => {
  switchTab(tab.value);
  if (tab.value !== 'tickets') loadTickets();
});
</script>

<template>
  <div>
    <div class="gm-console-banner">
      <div class="gm-console-banner__left">
        <div class="gm-console-banner__icon">⛨</div>
        <div>
          <div class="ox gm-console-banner__title">Game Master Console</div>
          <div class="gm-console-banner__subtitle">No-code control over every item, monster, event & feature</div>
        </div>
      </div>
      <div v-if="auth.user" class="gm-console-identity">
        <span class="gm-console-role-badge" :class="roleBadge.class">{{ roleBadge.label }}</span>
        <div class="gm-console-identity__avatar">{{ initials }}</div>
        <span class="gm-console-identity__name">{{ auth.user.name }}</span>
      </div>
    </div>

    <div class="gm-console-tabs">
      <button
        v-for="t in TABS"
        :key="t.key"
        @click="switchTab(t.key)"
        class="gm-console-tab-btn"
        :class="{ 'is-active': tab === t.key }"
      >
        {{ t.label }}
        <span v-if="t.key === 'tickets' && openTicketCount > 0" class="gm-console-tab-badge">{{ openTicketCount }}</span>
      </button>
    </div>

    <!-- OVERVIEW -->
    <div v-if="tab === 'overview'">
      <div v-if="globalTesterFlag" class="gm-console-tester-banner">
        <p class="gm-console-tester-banner__note">
          <template v-if="globalTesterFlag.enabled">
            Global Tester Mode is <strong>ON</strong> — every designated tester's perks (titles, colors, banners) are live right now.
          </template>
          <template v-else>
            Global Tester Mode is <strong>OFF</strong> — testers see the game as regular players until you flip this on.
          </template>
        </p>
        <label class="toggle-switch">
          <input
            type="checkbox"
            aria-label="Toggle Global Tester Mode"
            :checked="globalTesterFlag.enabled"
            @change="toggleFlag(globalTesterFlag, 'enabled')"
          />
          <span class="toggle-switch__track"><span class="toggle-switch__knob"></span></span>
        </label>
      </div>

      <div class="gm-console-section-label gm-console-section-label--spaced">RIGHT NOW</div>
      <div v-if="analytics" class="gm-console-activity-stats">
        <div class="gm-console-activity-stat">
          <div class="gm-console-activity-stat__label">Total users</div>
          <div class="ox gm-console-activity-stat__value">{{ analytics.headline.total_users }}</div>
          <div class="gm-console-activity-stat__sub">+{{ analytics.headline.new_users_7d }} last 7d</div>
        </div>
        <div class="gm-console-activity-stat">
          <div class="gm-console-activity-stat__label">Active now</div>
          <div class="ox gm-console-activity-stat__value">{{ analytics.headline.active_1h }}</div>
          <div class="gm-console-activity-stat__sub">last hour · {{ analytics.headline.active_24h }} today</div>
        </div>
        <div class="gm-console-activity-stat">
          <div class="gm-console-activity-stat__label">Battles today</div>
          <div class="ox gm-console-activity-stat__value">{{ analytics.headline.battles_today }}</div>
          <div class="gm-console-activity-stat__sub">{{ analytics.headline.battles_total }} all-time</div>
        </div>
        <router-link :to="{ query: { tab: 'activity' } }" class="gm-console-activity-stat gm-console-activity-stat--link" @click="switchTab('activity')">
          <div class="gm-console-activity-stat__label">Full activity dashboard →</div>
          <div class="gm-console-activity-stat__sub">Signups, engagement trends, content interest</div>
        </router-link>
      </div>

      <div class="gm-console-section-label gm-console-section-label--spaced">ACTION ITEMS</div>
      <div class="gm-console-todo-grid">
        <router-link :to="{ query: { tab: 'tickets' } }" class="gm-console-todo-tile" @click="switchTab('tickets')">
          <div class="ox gm-console-todo-tile__count">{{ openTicketCount }}</div>
          <div class="gm-console-todo-tile__label">Open support tickets</div>
        </router-link>
        <router-link :to="{ query: { tab: 'tickets' } }" class="gm-console-todo-tile" :class="{ 'gm-console-todo-tile--warn': openBugReportCount > 0 }" @click="switchTab('tickets')">
          <div class="ox gm-console-todo-tile__count">{{ openBugReportCount }}</div>
          <div class="gm-console-todo-tile__label">🐞 Open bug reports</div>
        </router-link>
        <router-link :to="{ query: { tab: 'revenue' } }" class="gm-console-todo-tile" @click="switchTab('revenue')">
          <div class="gm-console-todo-tile__label">Revenue breakdown →</div>
        </router-link>
        <router-link :to="{ query: { tab: 'flags' } }" class="gm-console-todo-tile" @click="switchTab('flags')">
          <div class="gm-console-todo-tile__label">Feature flags →</div>
        </router-link>
      </div>

      <div class="gm-console-section-label gm-console-section-label--spaced">GAME CONTENT TOTALS</div>
      <div class="gm-console-stats-grid">
        <div
          v-for="(count, key) in contentCounts"
          :key="key"
          class="gm-console-stat-tile"
        >
          <div class="gm-console-stat-tile__icon">{{ RESOURCE_ICONS[key] || '•' }}</div>
          <div class="ox gm-console-stat-tile__count">{{ count }}</div>
          <div class="gm-console-stat-tile__label">{{ key }}</div>
        </div>
      </div>

      <div class="gm-console-section-label gm-console-section-label--spaced">LEVEL GROWTH & CONTENT UNLOCKS</div>
      <div class="gm-console-activity-charts">
        <div class="gm-console-activity-chart-card gm-console-activity-chart-card--wide">
          <div class="gm-console-activity-chart-card__title">
            Level growth
            <InfoTooltip text="Cumulative XP required to reach each level (xpForLevel is linear: 500 + 800 per level), sampled every 5 levels through 150. There's no real level cap — content unlocks stop around level 150, but a character can keep leveling past it as pure attribute/skill grind." />
          </div>
          <ActivityChart type="line" color="#eab308" v-bind="levelGrowthChartData" :height="200" />
          <div v-if="killsToLevelUp.length" class="gm-console-kills-row">
            <div
              v-for="row in killsToLevelUp"
              :key="row.level"
              class="gm-console-kills-chip"
              :title="`Total kills from level 1 — avg vs. ${row.monster_name} (${row.monster_xp} avg xp each)`"
            >
              <span class="gm-console-kills-chip__level">Lv.{{ row.level }}</span>
              <span class="gm-console-kills-chip__value">{{ row.kills.toLocaleString() }} kills total</span>
              <span class="gm-console-kills-chip__monster">{{ row.monster_name }}</span>
            </div>
          </div>
        </div>
      </div>
      <div class="gm-console-activity-charts">
        <div v-if="unlockTimeline.length" class="gm-console-activity-chart-card gm-console-activity-chart-card--wide">
          <div class="gm-console-activity-chart-card__title">
            Content unlocks
            <InfoTooltip text="Every zone/dungeon/monster/skill/recipe/feature unlock, level 1 through the last level that unlocks anything. Bar height = how many things unlock at that level; hover a bar to see what. Full sorted list below." />
          </div>
          <ActivityChart type="bar" v-bind="unlockChartData" stacked :height="280" />
          <div class="gm-console-unlock-timeline">
            <div v-for="row in unlockTimeline" :key="row.type + row.name" class="gm-console-unlock-row">
              <span class="gm-console-unlock-row__level">Lv.{{ row.level }}</span>
              <span class="gm-console-unlock-row__type" :class="`gm-console-unlock-row__type--${row.type}`">{{ row.type }}</span>
              <span class="gm-console-unlock-row__name">{{ row.name }}</span>
              <span class="gm-console-unlock-row__xp">{{ row.cumulative_xp.toLocaleString() }} xp</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- FEATURE FLAGS -->
    <div v-else-if="tab === 'flags'">
      <p class="gm-console-intro">LIVE makes a feature reachable by everyone. TESTERS-only features stay reachable only to designated testers while Global Tester Mode (Overview) is on.</p>
      <div class="gm-console-panel">
        <div class="gm-console-flags-row gm-console-flags-row--header">
          <div>FEATURE</div><div class="gm-console-flags-row__cell--center">LIVE</div><div class="gm-console-flags-row__cell--center">TESTERS</div>
        </div>
        <div v-for="flag in flags" :key="flag.id" class="gm-console-flags-row">
          <div class="gm-console-flag-name">{{ flag.name }}</div>
          <div class="gm-console-flags-row__cell--center">
            <label class="toggle-switch">
              <input type="checkbox" :aria-label="`${flag.name} live`" :checked="flag.enabled" @change="toggleFlag(flag, 'enabled')" />
              <span class="toggle-switch__track"><span class="toggle-switch__knob"></span></span>
            </label>
          </div>
          <div class="gm-console-flags-row__cell--center">
            <label class="toggle-switch">
              <input type="checkbox" :aria-label="`${flag.name} testers only`" :checked="flag.tester_only" @change="toggleFlag(flag, 'tester_only')" />
              <span class="toggle-switch__track"><span class="toggle-switch__knob"></span></span>
            </label>
          </div>
        </div>
        <div v-if="!flags.length" class="gm-console-empty gm-console-empty--panel">No feature flags.</div>
      </div>
    </div>

    <!-- REVENUE -->
    <div v-else-if="tab === 'revenue'">
      <div class="gm-console-flags-header">
        <div class="gm-console-section-label">REVENUE — {{ metrics?.period?.label || 'THIS MONTH' }}</div>
      </div>
      <div v-if="metrics" class="gm-console-revenue-panel">
        <p class="gm-console-revenue-panel__disclaimer">
          Gem pack, season pass, and other one-time figures are exact — summed from completed purchases this month.
          VIP MRR is an <strong>estimate based on current active subscribers</strong> (active count × current tier price) —
          there's no per-charge VIP ledger to compute an exact figure.
        </p>
        <div class="gm-console-revenue-grid">
          <div class="gm-console-revenue-tile">
            <div class="gm-console-revenue-tile__label">Gem Pack Revenue ({{ metrics.gem_packs_sold_count }} sold)</div>
            <div class="ox gm-console-revenue-tile__value">${{ formatCents(metrics.gem_pack_revenue_cents) }}</div>
          </div>
          <div class="gm-console-revenue-tile">
            <div class="gm-console-revenue-tile__label">Season Pass Revenue ({{ metrics.season_passes_sold_count }} sold)</div>
            <div class="ox gm-console-revenue-tile__value">${{ formatCents(metrics.season_pass_revenue_cents) }}</div>
          </div>
          <div class="gm-console-revenue-tile">
            <div class="gm-console-revenue-tile__label">Other SKU Revenue</div>
            <div class="ox gm-console-revenue-tile__value">${{ formatCents(metrics.other_revenue_cents) }}</div>
          </div>
          <div class="gm-console-revenue-tile gm-console-revenue-tile--total">
            <div class="gm-console-revenue-tile__label">Total One-Time Revenue</div>
            <div class="ox gm-console-revenue-tile__value">${{ formatCents(metrics.total_one_time_revenue_cents) }}</div>
          </div>
          <div class="gm-console-revenue-tile">
            <div class="gm-console-revenue-tile__label">Active VIP — Bronze / Gold / Diamond</div>
            <div class="ox gm-console-revenue-tile__value">
              {{ metrics.active_vip_counts.bronze }} / {{ metrics.active_vip_counts.gold }} / {{ metrics.active_vip_counts.diamond }}
            </div>
          </div>
          <div class="gm-console-revenue-tile gm-console-revenue-tile--total">
            <div class="gm-console-revenue-tile__label">Est. VIP MRR</div>
            <div class="ox gm-console-revenue-tile__value">${{ formatCents(metrics.vip_mrr_cents) }}</div>
          </div>
        </div>
      </div>
      <p v-else class="gm-console-intro">Loading revenue…</p>
    </div>

    <!-- ACTIVITY -->
    <div v-else-if="tab === 'activity'">
      <div v-if="analytics" class="gm-console-activity">
        <div class="gm-console-activity__toolbar">
          <div class="gm-console-activity__range">
            <button
              v-for="d in [7, 30, 90]"
              :key="d"
              class="gm-console-activity__range-btn"
              :class="{ 'is-active': analyticsRangeDays === d }"
              @click="analyticsRangeDays = d; loadActivity();"
            >
              {{ d }}d
            </button>
          </div>
          <div class="gm-console-activity__window-note">{{ analytics.range_days }}-day window</div>
        </div>

        <div class="gm-console-activity-stats">
          <div class="gm-console-activity-stat">
            <div class="gm-console-activity-stat__head">
              <div class="gm-console-activity-stat__label">Total users</div>
              <div class="gm-console-activity-stat__icon gm-console-activity-stat__icon--purple">👥</div>
            </div>
            <div class="ox gm-console-activity-stat__value">{{ analytics.headline.total_users }}</div>
            <div class="gm-console-activity-stat__sub gm-console-activity-stat__sub--up">+{{ analytics.headline.new_users_7d }} this week</div>
          </div>
          <div class="gm-console-activity-stat">
            <div class="gm-console-activity-stat__head">
              <div class="gm-console-activity-stat__label">Active now</div>
              <div class="gm-console-activity-stat__icon gm-console-activity-stat__icon--green">●</div>
            </div>
            <div class="ox gm-console-activity-stat__value">{{ analytics.headline.active_1h }}</div>
            <div class="gm-console-activity-stat__sub">last hour · {{ analytics.headline.active_24h }} today</div>
          </div>
          <div class="gm-console-activity-stat">
            <div class="gm-console-activity-stat__head">
              <div class="gm-console-activity-stat__label">Battles fought</div>
              <div class="gm-console-activity-stat__icon gm-console-activity-stat__icon--red">⚔</div>
            </div>
            <div class="ox gm-console-activity-stat__value">{{ analytics.headline.battles_total }}</div>
            <div class="gm-console-activity-stat__sub">{{ analytics.headline.battles_today }} today</div>
          </div>
          <div class="gm-console-activity-stat">
            <div class="gm-console-activity-stat__head">
              <div class="gm-console-activity-stat__label">
                Referrals used
                <InfoTooltip text="How many times a player has copied their invite link or code — an engagement signal, not a conversion. See the Referral funnel chart below for actual signups/qualified." />
              </div>
              <div class="gm-console-activity-stat__icon gm-console-activity-stat__icon--teal">🎁</div>
            </div>
            <div class="ox gm-console-activity-stat__value">{{ analytics.headline.referrals_used }}</div>
            <div class="gm-console-activity-stat__sub">{{ analytics.headline.referrals_signed_up }} signed up</div>
          </div>
          <div class="gm-console-activity-stat">
            <div class="gm-console-activity-stat__head">
              <div class="gm-console-activity-stat__label">
                Revenue (mo)
                <InfoTooltip text="Real VIP subscription MRR this billing period — from GmMetricsController, not a projection." />
              </div>
              <div class="gm-console-activity-stat__icon gm-console-activity-stat__icon--amber">💰</div>
            </div>
            <div class="ox gm-console-activity-stat__value gm-console-activity-stat__value--amber">${{ metrics ? formatCents(metrics.vip_mrr_cents) : '—' }}</div>
            <div class="gm-console-activity-stat__sub">{{ analytics.headline.active_7d }} active this week</div>
          </div>
          <button
            type="button"
            class="gm-console-activity-stat gm-console-activity-stat--clickable"
            :class="{ 'gm-console-activity-stat--warn': analytics.live_health.errors_24h > 0 }"
            @click="toggleErrorLog"
          >
            <div class="gm-console-activity-stat__head">
              <div class="gm-console-activity-stat__label">
                Errors (24h)
                <InfoTooltip text="Unhandled server errors (500s) caught automatically — not player mistakes or expected 4xx validation. Click to view the actual log. Zero is healthy." />
              </div>
              <div
                class="gm-console-activity-stat__icon"
                :class="analytics.live_health.errors_24h > 0 ? 'gm-console-activity-stat__icon--warn' : 'gm-console-activity-stat__icon--green'"
              >⚠</div>
            </div>
            <div
              class="ox gm-console-activity-stat__value"
              :class="{ 'gm-console-activity-stat__value--warn': analytics.live_health.errors_24h > 0 }"
            >{{ analytics.live_health.errors_24h }}</div>
            <div class="gm-console-activity-stat__sub">{{ analytics.live_health.errors_7d }} in last 7d · click to view log</div>
          </button>
        </div>

        <div v-if="errorLogOpen" class="gm-console-activity-charts">
          <div class="gm-console-activity-chart-card gm-console-activity-chart-card--wide">
            <div class="gm-console-activity-chart-card__title">
              Error log
              <button type="button" class="gm-console-error-log__close" @click="errorLogOpen = false">✕</button>
            </div>
            <div v-if="!errorLog" class="gm-console-empty">Loading…</div>
            <div v-else class="gm-console-error-log">
              <div v-if="errorLog.by_class.length" class="gm-console-error-log__classes">
                <span v-for="row in errorLog.by_class" :key="row.exception_class" class="gm-console-error-log__class-chip">
                  {{ row.exception_class.split('\\').pop() }} × {{ row.count }}
                </span>
              </div>
              <div
                v-for="log in errorLog.logs"
                :key="log.id"
                class="gm-console-error-log__row"
                :class="{ 'gm-console-error-log__row--new': isNewError(log.created_at) }"
                @click="toggleErrorTrace(log.id)"
              >
                <div class="gm-console-error-log__row-head">
                  <span v-if="isNewError(log.created_at)" class="gm-console-error-log__new-badge">NEW</span>
                  <span class="gm-console-error-log__class">{{ log.exception_class.split('\\').pop() }}</span>
                  <span class="gm-console-error-log__when">{{ timeAgo(log.created_at) }}</span>
                  <button
                    type="button"
                    class="gm-console-error-log__clear-btn"
                    title="Fixed — clear from this list (purged after 7 days)"
                    @click.stop="clearError(log)"
                  >Clear</button>
                </div>
                <div class="gm-console-error-log__message">{{ log.message }}</div>
                <div class="gm-console-error-log__meta">
                  <span v-if="log.file">{{ log.file.split('/').pop() }}:{{ log.line }}</span>
                  <span v-if="log.method && log.url">{{ log.method }} {{ log.url }}</span>
                  <span v-if="log.user">{{ log.user.name }}</span>
                </div>
                <pre v-if="expandedErrorId === log.id && log.trace" class="gm-console-error-log__trace">{{ log.trace }}</pre>
              </div>
              <div v-if="!errorLog.logs.length" class="gm-console-empty">No errors logged. All clear.</div>
            </div>
          </div>
        </div>

        <div class="gm-console-activity-charts">
          <div class="gm-console-activity-chart-card">
            <div class="gm-console-activity-chart-card__title">New signups</div>
            <ActivityChart type="line" color="#5cc7f5" v-bind="seriesChartData('signups')" />
          </div>
          <div class="gm-console-activity-chart-card">
            <div class="gm-console-activity-chart-card__title">Battles played</div>
            <ActivityChart type="line" color="#e8482f" v-bind="seriesChartData('battles')" />
          </div>
          <div class="gm-console-activity-chart-card">
            <div class="gm-console-activity-chart-card__title">Active characters</div>
            <ActivityChart type="line" color="#4ade80" v-bind="seriesChartData('active_characters')" />
          </div>
        </div>

        <div class="gm-console-activity-charts">
          <div class="gm-console-activity-chart-card">
            <div class="gm-console-activity-chart-card__title">What players are doing (7d)</div>
            <ActivityChart type="bar" color="#5cc7f5" v-bind="contentInterestChartData" />
          </div>
          <div class="gm-console-activity-chart-card">
            <div class="gm-console-activity-chart-card__title">Class distribution</div>
            <ActivityChart type="doughnut" v-bind="classChartData" />
          </div>
          <div class="gm-console-activity-chart-card">
            <div class="gm-console-activity-chart-card__title">Level distribution</div>
            <ActivityChart type="bar" color="#a78bfa" v-bind="levelChartData" />
          </div>
        </div>

        <div class="gm-console-activity-charts">
          <div class="gm-console-activity-chart-card">
            <div class="gm-console-activity-chart-card__title">Referral signups</div>
            <ActivityChart type="line" color="#2dd4bf" v-bind="seriesChartData('referrals')" />
          </div>
          <div v-if="analytics.referral_funnel" class="gm-console-activity-chart-card">
            <div class="gm-console-activity-chart-card__title">
              Referral funnel
              <InfoTooltip text="Pending = signed up with a code but hasn't reached the required level yet. Qualified = reached it — that's a 'finished' referral, and what counts toward the referrer's reward." />
            </div>
            <ActivityChart type="doughnut" v-bind="referralFunnelChartData" />
            <div class="gm-console-activity-chart-card__footnote">
              {{ analytics.referral_funnel.reward_milestones_granted }} referrer reward{{ analytics.referral_funnel.reward_milestones_granted === 1 ? '' : 's' }} granted ·
              {{ analytics.referral_funnel.referee_bonuses_granted }} referee bonus{{ analytics.referral_funnel.referee_bonuses_granted === 1 ? '' : 'es' }} granted
            </div>
          </div>
        </div>

        <div class="gm-console-activity-charts">
          <div class="gm-console-activity-chart-card">
            <div class="gm-console-activity-chart-card__title">
              Retention
              <InfoTooltip text="Of users who signed up long enough ago to have had the chance, the % that still had an active character N days later. Cohort window caps at 60 days back, so Day 30 always has a real observation window." />
            </div>
            <div class="gm-console-retention">
              <div
                v-for="(row, i) in analytics.retention"
                :key="row.window"
                class="gm-console-retention__row"
                :class="`gm-console-retention__row--${i}`"
              >
                <div class="gm-console-retention__label">{{ row.label }}</div>
                <div class="gm-console-retention__track">
                  <div class="gm-console-retention__fill" :style="{ width: `${row.pct ?? 0}%` }"></div>
                </div>
                <div class="ox gm-console-retention__pct">{{ formatPct(row.pct) }}</div>
              </div>
              <div v-if="!analytics.retention.some((r) => r.cohort_size > 0)" class="gm-console-empty">
                Not enough signup history yet to measure retention.
              </div>
            </div>
          </div>

          <div class="gm-console-activity-chart-card">
            <div class="gm-console-activity-chart-card__title">
              Top players by power
              <InfoTooltip text="Power is the same formula the in-game Leaderboard ranks by — combined stats from level, gear, and active pets." />
            </div>
            <div class="gm-console-top-players">
              <div v-for="(p, i) in analytics.top_players" :key="p.name" class="gm-console-top-players__row">
                <div class="ox gm-console-top-players__rank">{{ i + 1 }}</div>
                <div class="ox gm-console-top-players__avatar">{{ p.name.slice(0, 2).toUpperCase() }}</div>
                <div class="gm-console-top-players__info">
                  <div class="gm-console-top-players__name">{{ p.name }}</div>
                  <div class="gm-console-top-players__meta">{{ p.base_class }}</div>
                </div>
                <div class="gm-console-top-players__stats">
                  <div class="ox gm-console-top-players__power">{{ p.power.toLocaleString() }}</div>
                  <div class="gm-console-top-players__lvl">Lv.{{ p.level }}</div>
                </div>
              </div>
              <div v-if="!analytics.top_players.length" class="gm-console-empty">No characters yet.</div>
            </div>
          </div>

          <div class="gm-console-activity-chart-card">
            <div class="gm-console-activity-chart-card__title">
              Live health
              <InfoTooltip text="Real counts only — this game doesn't fake server load or API latency. Open tickets and crash-report counts are the true signal available today." />
            </div>
            <div class="gm-console-health">
              <div class="gm-console-health-grid">
                <div class="gm-console-health-tile">
                  <span class="gm-console-health__dot" :class="{ 'is-warn': analytics.live_health.open_tickets > 0 }"></span>
                  <span class="gm-console-health-tile__label">Open tickets</span>
                  <span class="ox gm-console-health-tile__value">{{ analytics.live_health.open_tickets }}</span>
                </div>
                <div class="gm-console-health-tile">
                  <span class="gm-console-health__dot" :class="{ 'is-warn': analytics.live_health.errors_24h > 0 }"></span>
                  <span class="gm-console-health-tile__label">Errors, 24h</span>
                  <span class="ox gm-console-health-tile__value">{{ analytics.live_health.errors_24h }}</span>
                </div>
                <div class="gm-console-health-tile">
                  <span class="gm-console-health__dot" :class="{ 'is-warn': analytics.live_health.errors_7d > 0 }"></span>
                  <span class="gm-console-health-tile__label">Errors, 7d</span>
                  <span class="ox gm-console-health-tile__value">{{ analytics.live_health.errors_7d }}</span>
                </div>
              </div>
              <div
                class="gm-console-health__banner"
                :class="{ 'is-warn': analytics.live_health.errors_24h > 0 }"
              >
                {{ analytics.live_health.errors_24h > 0
                  ? `⚠ ${analytics.live_health.errors_24h} error(s) detected in the last 24h`
                  : '✓ All systems operational' }}
              </div>
            </div>
          </div>
        </div>

        <div class="gm-console-activity-charts">
          <div class="gm-console-activity-chart-card gm-console-activity-chart-card--wide">
            <div class="gm-console-activity-chart-card__title">
              Currently active players
              <InfoTooltip text="Every character, newest last-active first, capped at 100 rows — a live who's-online glance distinct from Top Players (which ranks by power, not recency)." />
            </div>
            <div class="gm-console-active-players">
              <div class="gm-console-active-players__row gm-console-active-players__row--header">
                <div>Character</div><div>Account</div><div>Class</div><div>Lvl</div><div>Doing</div><div>Last active</div>
              </div>
              <div v-for="p in analytics.active_players" :key="p.name" class="gm-console-active-players__row">
                <div class="gm-console-active-players__name">{{ p.name }}</div>
                <div class="gm-console-active-players__account">{{ p.user_name || '—' }}</div>
                <div class="gm-console-active-players__class">{{ p.base_class }}</div>
                <div class="ox gm-console-active-players__lvl">{{ p.level }}</div>
                <div class="gm-console-active-players__doing">{{ p.last_action }}</div>
                <div class="gm-console-active-players__ago">{{ timeAgo(p.last_active_at) }}</div>
              </div>
              <div v-if="!analytics.active_players?.length" class="gm-console-empty">No characters yet.</div>
            </div>
          </div>
        </div>
      </div>
      <div v-else class="gm-console-activity-skeleton">
        <Skeleton height="100px" :count="6" />
        <Skeleton height="220px" :count="3" />
      </div>
    </div>

    <!-- CONTENT -->
    <div v-else-if="tab === 'content'">
      <div class="gm-console-resource-tabs">
        <button
          v-for="(schema, key) in RESOURCE_SCHEMAS"
          :key="key"
          @click="resource = key"
          class="gm-console-resource-tab-btn"
          :class="{ 'is-active': resource === key }"
        >
          {{ schema.label }}
        </button>
      </div>
      <GmContentEditor :resource="resource" :key="resource" />
    </div>

    <!-- PLAYERS -->
    <div v-else-if="tab === 'players'">
      <div class="gm-console-search-row">
        <input v-model="search" @keyup.enter="loadPlayers" placeholder="Search by name or email…" class="gm-console-search-input" />
        <button @click="loadPlayers" class="gm-console-search-btn">Search</button>
      </div>
      <p v-if="playerMessage" class="gm-console-player-message">{{ playerMessage }}</p>
      <div class="gm-console-list">
        <div v-for="user in players" :key="user.id" class="gm-console-player-card">
          <div class="gm-console-player-card__row">
            <div class="gm-console-player-card__info">
              <div class="ox gm-console-player-card__name">
                {{ user.name }}
                <span v-if="user.banned_at" class="gm-console-banned-badge">BANNED</span>
              </div>
              <div class="gm-console-player-card__meta">{{ user.email }} · {{ user.role }}<span v-if="user.character"> · {{ user.character.name }} Lv.{{ user.character.level }}</span></div>
              <div class="gm-console-player-card__referral">
                <span v-if="user.referrer">Referred by <strong>{{ user.referrer.name }}</strong></span>
                <span v-else>Not referred</span>
                <span v-if="user.referrals_count"> · referred {{ user.referrals_count }} player{{ user.referrals_count === 1 ? '' : 's' }}</span>
              </div>
            </div>
            <button @click="openEditUser(user)" class="gm-console-edit-btn">✎ Edit user</button>
            <button @click="ban(user)" class="gm-console-ban-btn" :class="{ 'gm-console-ban-btn--active': user.banned_at }">
              {{ user.banned_at ? 'Unban' : 'Ban' }}
            </button>
          </div>
          <div class="gm-console-grant-row">
            <input v-model="grantForm[user.id].gold" placeholder="Gold" type="number" class="gm-console-grant-input" />
            <input v-model="grantForm[user.id].gems" placeholder="Gems" type="number" class="gm-console-grant-input" />
            <input v-model="grantForm[user.id].item_id" placeholder="Item ID" type="number" class="gm-console-grant-input" />
            <select v-model="grantForm[user.id].cosmetic_id" class="gm-console-grant-input gm-console-grant-select">
              <option value="">Cosmetic…</option>
              <option v-for="c in cosmeticOptions" :key="c.id" :value="c.id">{{ c.type }} — {{ c.name }}</option>
            </select>
            <button @click="grant(user)" class="gm-console-grant-btn">Grant</button>
          </div>
          <div v-if="mailForm[user.id]" class="gm-console-mail-row">
            <input v-model="mailForm[user.id].subject" placeholder="Mail subject" class="gm-console-mail-input" />
            <input v-model="mailForm[user.id].body" placeholder="Mail body" class="gm-console-mail-input gm-console-mail-input--body" />
            <button @click="sendMail(user)" class="gm-console-mail-btn">Send mail</button>
          </div>
        </div>
        <div v-if="!players.length" class="gm-console-empty">Search for a player above.</div>
      </div>
    </div>

    <!-- ECONOMY -->
    <div v-else-if="tab === 'economy'">
      <p class="gm-console-intro">Global multipliers applied to every battle reward.</p>
      <p class="gm-console-note">
        Luck and crafting are tunable here, including vip_luck_*, luck_combat_*, crafted_roll_* and crafted_value_* keys.
      </p>
      <div class="gm-console-config-grid">
        <div v-for="row in config" :key="row.key" class="gm-console-config-tile">
          <div class="gm-console-config-tile__label">
            {{ row.key.replace(/_/g, ' ') }}
            <span v-if="sliderRange(row.key)" class="gm-console-config-tile__readout">{{ row.value }}</span>
          </div>
          <div class="gm-console-config-tile__row">
            <input
              v-if="sliderRange(row.key)"
              v-model.number="row.value"
              type="range"
              v-bind="sliderRange(row.key)"
              class="gm-console-config-slider"
              @change="saveConfig(row)"
            />
            <input v-else v-model="row.value" class="gm-console-config-input" />
            <button v-if="!sliderRange(row.key)" @click="saveConfig(row)" class="gm-console-config-save-btn">Save</button>
          </div>
        </div>
      </div>
    </div>

    <!-- TICKETS -->
    <div v-else-if="tab === 'tickets'">
      <div class="gm-console-list gm-console-list--wide">
        <div v-for="ticket in activeTickets" :key="ticket.id" class="gm-console-ticket-card">
          <div class="gm-console-ticket-card__row">
            <div class="ox gm-console-ticket-card__subject">{{ ticket.subject }}</div>
            <span class="gm-console-ticket-card__meta">
              <span v-if="ticket.category === 'bug'" class="gm-console-ticket-card__bug-badge">🐞 BUG</span>
              {{ ticket.status }} · {{ ticket.priority }}
            </span>
          </div>
          <div class="gm-console-ticket-card__body">{{ ticket.body }}</div>
          <div class="gm-console-ticket-card__from">From {{ ticket.user?.name }}</div>
          <div class="gm-console-ticket-card__actions">
            <button @click="toggleTicketThread(ticket)" class="gm-console-resolve-btn">
              {{ expandedTicket === ticket.id ? 'Hide Chat' : `Chat${ticket.messages?.length ? ` (${ticket.messages.length})` : '' }` }}
            </button>
            <button @click="resolveTicket(ticket, 'resolved')" class="gm-console-resolve-btn">Resolve</button>
            <button @click="resolveTicket(ticket, 'closed')" class="gm-console-close-btn">Close</button>
          </div>

          <div v-if="expandedTicket === ticket.id" class="gm-console-ticket-thread">
            <div class="gm-console-ticket-thread__messages">
              <div
                v-for="m in ticket.messages"
                :key="m.id"
                class="gm-console-ticket-thread__msg"
                :class="{ 'gm-console-ticket-thread__msg--gm': m.sender && ['gm', 'owner'].includes(m.sender.role) }"
              >
                <span class="gm-console-ticket-thread__msg-sender">
                  {{ m.sender && ['gm', 'owner'].includes(m.sender.role) ? `${m.sender.name} (GM)` : ticket.user?.name }}
                </span>
                <span class="gm-console-ticket-thread__msg-body">{{ m.body }}</span>
              </div>
              <div v-if="!ticket.messages?.length" class="gm-console-empty">No replies yet.</div>
            </div>
            <div class="gm-console-ticket-thread__reply">
              <input v-model="ticketReplyBody" placeholder="Reply to player…" class="gm-console-config-input" @keyup.enter="sendTicketReply(ticket)" />
              <button @click="sendTicketReply(ticket)" class="gm-console-resolve-btn">Send</button>
            </div>
          </div>
        </div>
        <div v-if="!activeTickets.length" class="gm-console-empty">No open support tickets.</div>

        <button
          v-if="archivedTickets.length"
          type="button"
          class="gm-console-archive-toggle"
          @click="showArchived = !showArchived"
        >
          {{ showArchived ? '▾' : '▸' }} Archived tickets ({{ archivedTickets.length }})
        </button>

        <template v-if="showArchived">
          <div v-for="ticket in archivedTickets" :key="ticket.id" class="gm-console-ticket-card gm-console-ticket-card--archived">
            <div class="gm-console-ticket-card__row">
              <div class="ox gm-console-ticket-card__subject">{{ ticket.subject }}</div>
              <span class="gm-console-ticket-card__meta">
              <span v-if="ticket.category === 'bug'" class="gm-console-ticket-card__bug-badge">🐞 BUG</span>
              {{ ticket.status }} · {{ ticket.priority }}
            </span>
            </div>
            <div class="gm-console-ticket-card__body">{{ ticket.body }}</div>
            <div class="gm-console-ticket-card__from">From {{ ticket.user?.name }}</div>
            <div class="gm-console-ticket-card__actions">
              <button @click="toggleTicketThread(ticket)" class="gm-console-resolve-btn">
                {{ expandedTicket === ticket.id ? 'Hide Chat' : `Chat${ticket.messages?.length ? ` (${ticket.messages.length})` : '' }` }}
              </button>
              <button @click="resolveTicket(ticket, 'pending')" class="gm-console-resolve-btn">Reopen</button>
            </div>

            <div v-if="expandedTicket === ticket.id" class="gm-console-ticket-thread">
              <div class="gm-console-ticket-thread__messages">
                <div
                  v-for="m in ticket.messages"
                  :key="m.id"
                  class="gm-console-ticket-thread__msg"
                  :class="{ 'gm-console-ticket-thread__msg--gm': m.sender && ['gm', 'owner'].includes(m.sender.role) }"
                >
                  <span class="gm-console-ticket-thread__msg-sender">
                    {{ m.sender && ['gm', 'owner'].includes(m.sender.role) ? `${m.sender.name} (GM)` : ticket.user?.name }}
                  </span>
                  <span class="gm-console-ticket-thread__msg-body">{{ m.body }}</span>
                </div>
                <div v-if="!ticket.messages?.length" class="gm-console-empty">No replies yet.</div>
              </div>
            </div>
          </div>
        </template>
      </div>
    </div>

    <!-- AUDIT LOG -->
    <div v-else-if="tab === 'audit'">
      <div class="gm-console-list gm-console-list--wide">
        <div v-for="log in auditLogs" :key="log.id" class="gm-console-audit-row">
          <div class="gm-console-audit-row__head">
            <span class="ox gm-console-audit-row__action">{{ log.action }}</span>
            <span class="gm-console-audit-row__meta">{{ log.gm?.name ?? 'unknown' }} · {{ log.created_at }}</span>
          </div>
          <div v-if="log.target_type" class="gm-console-audit-row__target">
            {{ log.target_type }}<span v-if="log.target_id"> #{{ log.target_id }}</span>
          </div>
        </div>
        <div v-if="!auditLogs.length" class="gm-console-empty">No audit log entries yet.</div>
      </div>
    </div>

    <!-- BROADCAST -->
    <div v-else-if="tab === 'broadcast'" class="gm-console-broadcast">
      <p class="gm-console-broadcast-intro">Send a server-wide announcement — appears in every player's Dashboard rail and Inbox.</p>
      <textarea
        v-model="broadcastBody"
        rows="4"
        placeholder="Season 3 goes live this weekend…"
        class="gm-console-broadcast-textarea"
      ></textarea>
      <p v-if="broadcastMessage" class="gm-console-broadcast-success">{{ broadcastMessage }}</p>
      <button @click="sendBroadcast" class="gm-console-broadcast-btn">Send broadcast</button>
    </div>

    <!-- EDIT USER MODAL -->
    <div v-if="editUserOpen" class="edit-user-modal-overlay" @click.self="closeEditUser">
      <div class="edit-user-modal">
        <div class="edit-user-modal__title">Edit {{ editUserTarget?.name }}</div>

        <div class="edit-user-modal__section-label">Account</div>
        <div class="edit-user-modal__grid">
          <label class="edit-user-modal__field">
            <span>Role</span>
            <select v-model="editUserForm.role">
              <option value="player">player</option>
              <option value="tester">tester</option>
              <option value="gm">gm</option>
              <option value="owner">owner</option>
            </select>
          </label>
          <label class="edit-user-modal__field edit-user-modal__field--checkbox">
            <input type="checkbox" v-model="editUserForm.is_tester" />
            <span>Tester flag</span>
          </label>
          <label class="edit-user-modal__field">
            <span>VIP tier</span>
            <select v-model="editUserForm.vip_tier">
              <option value="none">none</option>
              <option value="bronze">bronze</option>
              <option value="gold">gold</option>
              <option value="diamond">diamond</option>
            </select>
          </label>
          <label class="edit-user-modal__field">
            <span>VIP expires</span>
            <input type="date" v-model="editUserForm.vip_expires_at" />
          </label>
        </div>
        <label class="edit-user-modal__field edit-user-modal__field--full">
          <span>Ban reason (shown to the player if banned)</span>
          <textarea v-model="editUserForm.banned_reason" rows="2" placeholder="Reason for ban…"></textarea>
        </label>

        <div v-if="editUserTarget?.character" class="edit-user-modal__section-label">Character stats — {{ editUserTarget.character.name }}</div>
        <div v-if="editUserTarget?.character" class="edit-user-modal__grid edit-user-modal__grid--stats">
          <label class="edit-user-modal__field"><span>Level</span><input type="number" min="0" v-model="editUserForm.level" /></label>
          <label class="edit-user-modal__field"><span>XP</span><input type="number" min="0" v-model="editUserForm.xp" /></label>
          <label class="edit-user-modal__field"><span>Gold</span><input type="number" min="0" v-model="editUserForm.gold" /></label>
          <label class="edit-user-modal__field"><span>Gems</span><input type="number" min="0" v-model="editUserForm.gems" /></label>
          <label class="edit-user-modal__field"><span>HP</span><input type="number" min="0" v-model="editUserForm.hp" /></label>
          <label class="edit-user-modal__field"><span>HP Max</span><input type="number" min="0" v-model="editUserForm.hp_max" /></label>
          <label class="edit-user-modal__field"><span>Mana</span><input type="number" min="0" v-model="editUserForm.mana" /></label>
          <label class="edit-user-modal__field"><span>Mana Max</span><input type="number" min="0" v-model="editUserForm.mana_max" /></label>
          <label class="edit-user-modal__field"><span>Energy</span><input type="number" min="0" v-model="editUserForm.energy" /></label>
          <label class="edit-user-modal__field"><span>Energy Max</span><input type="number" min="0" v-model="editUserForm.energy_max" /></label>
        </div>

        <div class="edit-user-modal__stuck">
          <button @click="clearStuckState" class="edit-user-modal__stuck-btn">🔧 Clear stuck state</button>
          <p class="edit-user-modal__stuck-caption">
            Un-sticks a character stuck in an active battle or hung auto-battle/auto-gather session.
          </p>
        </div>

        <p v-if="editUserMessage" class="edit-user-modal__message">{{ editUserMessage }}</p>

        <div class="edit-user-modal__actions">
          <button @click="closeEditUser" class="edit-user-modal__cancel-btn">Cancel</button>
          <button @click="saveEditUser" class="edit-user-modal__save-btn">Save</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style lang="scss" src="./GmConsole.scss" scoped></style>
