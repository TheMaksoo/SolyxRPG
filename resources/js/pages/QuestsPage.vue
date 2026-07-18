<script setup>
import { ref, onMounted, computed } from 'vue';
import api from '../api/client';
import AdBanner from '../components/AdBanner.vue';

const rows = ref([]);
const tab = ref('daily');
const message = ref('');

const tabs = [
  { key: 'daily', label: 'Daily' },
  { key: 'weekly', label: 'Weekly' },
  { key: 'main', label: 'Main' },
  { key: 'raid', label: 'Raid' },
];

const filtered = computed(() => rows.value.filter((r) => r.quest.type === tab.value));

async function load() {
  const { data } = await api.get('/quests');
  rows.value = data.quests;
}

async function claim(row) {
  message.value = '';
  try {
    await api.post(`/quests/${row.quest.id}/claim`);
    await load();
  } catch (e) {
    message.value = e.response?.data?.message || 'Could not claim.';
  }
}

onMounted(load);
</script>

<template>
  <div>
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px">
      <div style="font-size:28px">📜</div>
      <h1 class="ox" style="font-size:28px;font-weight:800;margin:0">Quests</h1>
    </div>

    <div style="display:flex;gap:8px;margin-bottom:18px">
      <button
        v-for="t in tabs"
        :key="t.key"
        @click="tab = t.key"
        :style="{
          padding: '8px 16px',
          borderRadius: '20px',
          border: '1px solid rgba(255,255,255,.1)',
          background: tab === t.key ? '#e8482f' : '#151517',
          color: '#fff',
          fontSize: '13px',
          fontWeight: 600,
          cursor: 'pointer',
        }"
      >
        {{ t.label }}
      </button>
    </div>

    <p v-if="message" style="font-size:13px;color:#ff6a4d;margin-bottom:14px">{{ message }}</p>

    <AdBanner variant="inline" />

    <div style="display:flex;flex-direction:column;gap:10px;max-width:600px">
      <div
        v-for="row in filtered"
        :key="row.quest.id"
        style="background:#151517;border:1px solid rgba(255,255,255,.07);border-radius:13px;padding:16px;display:flex;align-items:center;gap:14px"
      >
        <div style="flex:1">
          <div class="ox" style="font-weight:700;font-size:14px">{{ row.quest.name }}</div>
          <div style="font-size:12px;color:rgba(255,255,255,.5);margin-top:4px">{{ row.quest.description }}</div>
          <div style="font-size:11.5px;color:rgba(255,255,255,.35);margin-top:6px">
            Progress: {{ row.progress }} / {{ row.quest.goal_json.target ?? 1 }}
          </div>
        </div>
        <button
          v-if="row.completed && !row.claimed"
          @click="claim(row)"
          style="padding:9px 16px;border-radius:8px;border:none;background:#eab308;color:#151517;font-weight:700;font-size:12.5px;cursor:pointer"
        >
          Claim
        </button>
        <span v-else-if="row.claimed" style="font-size:12px;color:#4ade80">Claimed</span>
        <span v-else style="font-size:12px;color:rgba(255,255,255,.3)">In progress</span>
      </div>
    </div>
  </div>
</template>
