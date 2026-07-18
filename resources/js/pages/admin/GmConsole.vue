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
  if (!confirm(`Revoke ${user.name}'s active sessions?`)) return;
  const { data } = await api.post(`/gm/players/${user.id}/ban`);
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

function switchTab(key) {
  tab.value = key;
  if (key === 'overview') loadOverview();
  if (key === 'players') loadPlayers();
  if (key === 'economy') loadConfig();
  if (key === 'tickets') loadTickets();
}

onMounted(() => loadOverview());
</script>

<template>
  <div>
    <div
      style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;background:linear-gradient(120deg,rgba(232,72,47,.16),rgba(232,72,47,.02));border:1px solid rgba(232,72,47,.28);border-radius:14px;padding:18px 20px;margin-bottom:20px"
    >
      <div style="display:flex;align-items:center;gap:14px">
        <div style="width:46px;height:46px;flex:none;border-radius:12px;background:rgba(232,72,47,.18);display:grid;place-items:center;font-size:22px">⛨</div>
        <div>
          <div class="ox" style="font-weight:800;font-size:19px">Game Master Console</div>
          <div style="font-size:12.5px;color:rgba(255,255,255,.5)">No-code control over every item, monster, event & feature</div>
        </div>
      </div>
    </div>

    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:22px;border-bottom:1px solid rgba(255,255,255,.06);padding-bottom:16px">
      <button
        v-for="t in TABS"
        :key="t.key"
        @click="switchTab(t.key)"
        :style="{
          padding: '8px 16px', borderRadius: '20px', border: '1px solid rgba(255,255,255,.1)',
          background: tab === t.key ? '#e8482f' : '#151517', color: '#fff', fontSize: '13px', fontWeight: 600, cursor: 'pointer',
        }"
      >
        {{ t.label }}
      </button>
    </div>

    <!-- OVERVIEW -->
    <div v-if="tab === 'overview'">
      <div style="font-size:12px;letter-spacing:.15em;color:rgba(255,255,255,.4);font-weight:600;margin-bottom:12px">GAME CONTENT</div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(128px,1fr));gap:12px;margin-bottom:28px">
        <div
          v-for="(count, key) in contentCounts"
          :key="key"
          style="background:#151517;border:1px solid rgba(255,255,255,.07);border-radius:12px;padding:16px"
        >
          <div class="ox" style="font-size:22px;font-weight:800;color:#e8482f;line-height:1">{{ count }}</div>
          <div style="font-size:11.5px;color:rgba(255,255,255,.45);margin-top:2px;text-transform:capitalize">{{ key }}</div>
        </div>
      </div>

      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
        <div style="font-size:12px;letter-spacing:.15em;color:rgba(255,255,255,.4);font-weight:600">FEATURE FLAGS</div>
      </div>
      <div style="background:#151517;border:1px solid rgba(255,255,255,.07);border-radius:12px;overflow:hidden">
        <div style="display:grid;grid-template-columns:1.6fr 90px 90px;padding:11px 18px;font-size:11px;letter-spacing:.08em;color:rgba(255,255,255,.35);font-weight:600;border-bottom:1px solid rgba(255,255,255,.06)">
          <div>FEATURE</div><div style="text-align:center">LIVE</div><div style="text-align:center">TESTERS</div>
        </div>
        <div v-for="flag in flags" :key="flag.id" style="display:grid;grid-template-columns:1.6fr 90px 90px;padding:12px 18px;align-items:center;border-bottom:1px solid rgba(255,255,255,.04)">
          <div style="font-size:13px;font-weight:600">{{ flag.name }}</div>
          <div style="text-align:center">
            <input type="checkbox" :checked="flag.enabled" @change="toggleFlag(flag, 'enabled')" style="width:18px;height:18px" />
          </div>
          <div style="text-align:center">
            <input type="checkbox" :checked="flag.tester_only" @change="toggleFlag(flag, 'tester_only')" style="width:18px;height:18px" />
          </div>
        </div>
        <div v-if="!flags.length" style="padding:16px;color:rgba(255,255,255,.35);font-size:12.5px">No feature flags.</div>
      </div>
    </div>

    <!-- CONTENT -->
    <div v-else-if="tab === 'content'">
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px">
        <button
          v-for="(schema, key) in RESOURCE_SCHEMAS"
          :key="key"
          @click="resource = key"
          :style="{
            padding: '7px 14px', borderRadius: '8px', border: '1px solid rgba(255,255,255,.1)',
            background: resource === key ? 'rgba(232,72,47,.15)' : '#151517',
            color: resource === key ? '#ff8163' : 'rgba(255,255,255,.6)', fontSize: '12px', fontWeight: 600, cursor: 'pointer',
          }"
        >
          {{ schema.label }}
        </button>
      </div>
      <GmContentEditor :resource="resource" :key="resource" />
    </div>

    <!-- PLAYERS -->
    <div v-else-if="tab === 'players'">
      <div style="display:flex;gap:10px;margin-bottom:16px;max-width:420px">
        <input v-model="search" @keyup.enter="loadPlayers" placeholder="Search by name or email…" style="flex:1;padding:9px 12px;border-radius:8px;border:1px solid rgba(255,255,255,.12);background:#151517;color:#fff;font-size:13px" />
        <button @click="loadPlayers" style="padding:9px 16px;border-radius:8px;border:none;background:#e8482f;color:#fff;font-weight:700;font-size:12.5px;cursor:pointer">Search</button>
      </div>
      <p v-if="playerMessage" style="font-size:13px;color:rgba(255,255,255,.6);margin-bottom:14px">{{ playerMessage }}</p>
      <div style="display:flex;flex-direction:column;gap:10px">
        <div v-for="user in players" :key="user.id" style="background:#151517;border:1px solid rgba(255,255,255,.07);border-radius:12px;padding:14px 18px">
          <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:10px">
            <div style="flex:1;min-width:180px">
              <div class="ox" style="font-weight:700;font-size:14px">{{ user.name }}</div>
              <div style="font-size:11.5px;color:rgba(255,255,255,.4)">{{ user.email }} · {{ user.role }}<span v-if="user.character"> · {{ user.character.name }} Lv.{{ user.character.level }}</span></div>
            </div>
            <button @click="ban(user)" style="padding:7px 14px;border-radius:7px;border:1px solid rgba(255,107,107,.3);background:transparent;color:#ff6a6a;font-size:12px;cursor:pointer">Revoke sessions</button>
          </div>
          <div style="display:flex;gap:8px;flex-wrap:wrap">
            <input v-model="grantForm[user.id].gold" placeholder="Gold" type="number" style="width:90px;padding:7px 10px;border-radius:7px;border:1px solid rgba(255,255,255,.12);background:#0e0e10;color:#fff;font-size:12px" />
            <input v-model="grantForm[user.id].gems" placeholder="Gems" type="number" style="width:90px;padding:7px 10px;border-radius:7px;border:1px solid rgba(255,255,255,.12);background:#0e0e10;color:#fff;font-size:12px" />
            <input v-model="grantForm[user.id].item_id" placeholder="Item ID" type="number" style="width:90px;padding:7px 10px;border-radius:7px;border:1px solid rgba(255,255,255,.12);background:#0e0e10;color:#fff;font-size:12px" />
            <button @click="grant(user)" style="padding:7px 14px;border-radius:7px;border:none;background:rgba(232,72,47,.15);color:#ff8163;font-size:12px;font-weight:600;cursor:pointer">Grant</button>
          </div>
        </div>
        <div v-if="!players.length" style="color:rgba(255,255,255,.35);font-size:13px">Search for a player above.</div>
      </div>
    </div>

    <!-- ECONOMY -->
    <div v-else-if="tab === 'economy'">
      <p style="color:rgba(255,255,255,.5);margin:0 0 18px">Global multipliers applied to every battle reward.</p>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;max-width:700px">
        <div v-for="row in config" :key="row.key" style="background:#151517;border:1px solid rgba(255,255,255,.07);border-radius:12px;padding:16px">
          <div style="font-size:11px;color:rgba(255,255,255,.4);margin-bottom:8px;text-transform:capitalize">{{ row.key.replace(/_/g, ' ') }}</div>
          <div style="display:flex;gap:8px">
            <input v-model="row.value" style="flex:1;padding:8px 10px;border-radius:7px;border:1px solid rgba(255,255,255,.12);background:#0e0e10;color:#fff;font-size:13px" />
            <button @click="saveConfig(row)" style="padding:8px 14px;border-radius:7px;border:none;background:#e8482f;color:#fff;font-size:12px;font-weight:700;cursor:pointer">Save</button>
          </div>
        </div>
      </div>
    </div>

    <!-- TICKETS -->
    <div v-else-if="tab === 'tickets'">
      <div style="display:flex;flex-direction:column;gap:10px;max-width:800px">
        <div v-for="ticket in tickets" :key="ticket.id" style="background:#151517;border:1px solid rgba(255,255,255,.07);border-radius:12px;padding:16px">
          <div style="display:flex;justify-content:space-between;gap:10px;margin-bottom:6px">
            <div class="ox" style="font-weight:700;font-size:14px">{{ ticket.subject }}</div>
            <span style="font-size:11px;color:rgba(255,255,255,.4);text-transform:capitalize">{{ ticket.status }} · {{ ticket.priority }}</span>
          </div>
          <div style="font-size:13px;color:rgba(255,255,255,.6);margin-bottom:10px">{{ ticket.body }}</div>
          <div style="font-size:11.5px;color:rgba(255,255,255,.35);margin-bottom:10px">From {{ ticket.user?.name }}</div>
          <div style="display:flex;gap:8px">
            <button @click="resolveTicket(ticket, 'resolved')" style="padding:7px 14px;border-radius:7px;border:none;background:#4ade80;color:#0b0b0c;font-size:12px;font-weight:700;cursor:pointer">Resolve</button>
            <button @click="resolveTicket(ticket, 'closed')" style="padding:7px 14px;border-radius:7px;border:1px solid rgba(255,255,255,.12);background:transparent;color:#fff;font-size:12px;cursor:pointer">Close</button>
          </div>
        </div>
        <div v-if="!tickets.length" style="color:rgba(255,255,255,.35);font-size:13px">No support tickets.</div>
      </div>
    </div>

    <!-- BROADCAST -->
    <div v-else-if="tab === 'broadcast'" style="max-width:520px">
      <p style="color:rgba(255,255,255,.5);margin:0 0 14px">Send a server-wide announcement — appears in every player's Dashboard rail and Inbox.</p>
      <textarea
        v-model="broadcastBody"
        rows="4"
        placeholder="Season 3 goes live this weekend…"
        style="width:100%;padding:12px;border-radius:10px;border:1px solid rgba(255,255,255,.12);background:#151517;color:#fff;font-size:13.5px;box-sizing:border-box;margin-bottom:12px"
      ></textarea>
      <p v-if="broadcastMessage" style="font-size:13px;color:#4ade80;margin-bottom:12px">{{ broadcastMessage }}</p>
      <button @click="sendBroadcast" style="padding:11px 24px;border-radius:10px;border:none;background:#e8482f;color:#fff;font-weight:700;cursor:pointer">Send broadcast</button>
    </div>
  </div>
</template>
