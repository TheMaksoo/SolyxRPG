<script setup>
import { ref, onMounted } from 'vue';
import api from '../../api/client';
import GmContentEditor from './GmContentEditor.vue';
import { RESOURCE_SCHEMAS } from './resourceSchemas';

const tab = ref('overview');
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
}

async function toggleFlag(flag, field) {
  const { data } = await api.put(`/gm/feature-flags/${flag.id}`, { [field]: !flag[field] });
  Object.assign(flag, data.flag);
}

// Content tab
const resource = ref('items');

// Players
const players = ref([]);
const search = ref('');
const grantForm = ref({});
const playerMessage = ref('');

async function loadPlayers() {
  const { data } = await api.get('/gm/players', { params: { search: search.value } });
  players.value = data.players;
  for (const user of data.players) {
    if (!grantForm.value[user.id]) {
      grantForm.value[user.id] = { gold: '', gems: '', item_id: '' };
    }
    if (!mailForm.value[user.id]) {
      mailForm.value[user.id] = { subject: '', body: '' };
    }
  }
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

// Economy
const config = ref([]);

async function loadConfig() {
  const { data } = await api.get('/gm/config');
  config.value = data.config;
}

async function saveConfig(row) {
  await api.put(`/gm/config/${row.key}`, { value: row.value });
}

// Tickets
const tickets = ref([]);

async function loadTickets() {
  const { data } = await api.get('/gm/tickets');
  tickets.value = data.tickets;
}

async function resolveTicket(ticket, status) {
  await api.post(`/gm/tickets/${ticket.id}/resolve`, { status });
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

onMounted(() => loadOverview());
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
      </button>
    </div>

    <!-- OVERVIEW -->
    <div v-if="tab === 'overview'">
      <div class="gm-console-section-label gm-console-section-label--spaced">GAME CONTENT</div>
      <div class="gm-console-stats-grid">
        <div
          v-for="(count, key) in contentCounts"
          :key="key"
          class="gm-console-stat-tile"
        >
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
            <input type="checkbox" :checked="flag.enabled" @change="toggleFlag(flag, 'enabled')" class="gm-console-checkbox" />
          </div>
          <div class="gm-console-flags-row__cell--center">
            <input type="checkbox" :checked="flag.tester_only" @change="toggleFlag(flag, 'tester_only')" class="gm-console-checkbox" />
          </div>
        </div>
        <div v-if="!flags.length" class="gm-console-empty gm-console-empty--panel">No feature flags.</div>
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
            <button @click="ban(user)" class="gm-console-ban-btn" :class="{ 'gm-console-ban-btn--active': user.banned_at }">
              {{ user.banned_at ? 'Unban' : 'Ban' }}
            </button>
          </div>
          <div class="gm-console-grant-row">
            <input v-model="grantForm[user.id].gold" placeholder="Gold" type="number" class="gm-console-grant-input" />
            <input v-model="grantForm[user.id].gems" placeholder="Gems" type="number" class="gm-console-grant-input" />
            <input v-model="grantForm[user.id].item_id" placeholder="Item ID" type="number" class="gm-console-grant-input" />
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
          <div class="gm-console-config-tile__label">{{ row.key.replace(/_/g, ' ') }}</div>
          <div class="gm-console-config-tile__row">
            <input v-model="row.value" class="gm-console-config-input" />
            <button @click="saveConfig(row)" class="gm-console-config-save-btn">Save</button>
          </div>
        </div>
      </div>
    </div>

    <!-- TICKETS -->
    <div v-else-if="tab === 'tickets'">
      <div class="gm-console-list gm-console-list--wide">
        <div v-for="ticket in tickets" :key="ticket.id" class="gm-console-ticket-card">
          <div class="gm-console-ticket-card__row">
            <div class="ox gm-console-ticket-card__subject">{{ ticket.subject }}</div>
            <span class="gm-console-ticket-card__meta">{{ ticket.status }} · {{ ticket.priority }}</span>
          </div>
          <div class="gm-console-ticket-card__body">{{ ticket.body }}</div>
          <div class="gm-console-ticket-card__from">From {{ ticket.user?.name }}</div>
          <div class="gm-console-ticket-card__actions">
            <button @click="resolveTicket(ticket, 'resolved')" class="gm-console-resolve-btn">Resolve</button>
            <button @click="resolveTicket(ticket, 'closed')" class="gm-console-close-btn">Close</button>
          </div>
        </div>
        <div v-if="!tickets.length" class="gm-console-empty">No support tickets.</div>
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
  </div>
</template>

<style lang="scss" src="./GmConsole.scss" scoped></style>
