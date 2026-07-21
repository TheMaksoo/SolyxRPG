<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import api from '../../api/client';
import { useAuthStore } from '../../stores/auth';
import GmContentEditor from './GmContentEditor.vue';
import { RESOURCE_SCHEMAS } from './resourceSchemas';

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
  skills: '✨', recipes: '🔨', pets: '🐾', events: '📅', cosmetics: '👑',
};

// Real "this month" revenue metrics, fed by GET /gm/metrics (see GmMetricsController).
const metrics = ref(null);
function formatCents(cents) {
  return ((cents || 0) / 100).toFixed(2);
}
const TAB_KEYS = ['overview', 'content', 'players', 'economy', 'tickets', 'broadcast', 'audit'];
// Supports deep-linking straight to a tab (e.g. /admin?tab=tickets from the Settings page's
// "manage tickets" link) while still defaulting to the overview tab otherwise.
const tab = ref(TAB_KEYS.includes(route.query.tab) ? route.query.tab : 'overview');
const TABS = [
  { key: 'overview', label: 'Overview' },
  { key: 'content', label: 'Content' },
  { key: 'players', label: 'Players' },
  { key: 'economy', label: 'Economy' },
  { key: 'tickets', label: 'Tickets' },
  { key: 'broadcast', label: 'Broadcast' },
  { key: 'audit', label: 'Audit Log' },
];

// Overview
const flags = ref([]);
const contentCounts = ref({});

async function loadOverview() {
  const { data } = await api.get('/gm/feature-flags');
  flags.value = data.flags;

  const entries = await Promise.all(
    Object.keys(RESOURCE_SCHEMAS).map(async (key) => {
      const { data } = await api.get(`/gm/${key}`);
      return [key, data[key].length];
    })
  );
  contentCounts.value = Object.fromEntries(entries);

  const { data: metricsData } = await api.get('/gm/metrics');
  metrics.value = metricsData;
}

async function toggleFlag(flag, field) {
  const { data } = await api.put(`/gm/feature-flags/${flag.id}`, { [field]: !flag[field] });
  Object.assign(flag, data.flag);
}

// The tester-mode banner is a second UI entry point onto the same flag row/toggleFlag() call already
// used in the Feature Flags table below — no separate endpoint or state.
const globalTesterFlag = computed(() => flags.value.find((f) => f.key === 'global_tester_mode'));

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
  if (key === 'players') loadPlayers();
  if (key === 'economy') loadConfig();
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

      <div class="gm-console-section-label gm-console-section-label--spaced">GAME CONTENT</div>
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

      <div class="gm-console-flags-header">
        <div class="gm-console-section-label">FEATURE FLAGS</div>
      </div>
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
