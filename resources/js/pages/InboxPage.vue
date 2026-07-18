<script setup>
import { ref, onMounted } from 'vue';
import api from '../api/client';

const items = ref([]);

async function load() {
  const { data } = await api.get('/inbox');
  items.value = data.items;
}

async function accept(item) {
  await api.post(`/friends/requests/${item.friendship_id}/accept`);
  await load();
}

async function decline(item) {
  await api.post(`/friends/requests/${item.friendship_id}/decline`);
  await load();
}

onMounted(load);
</script>

<template>
  <div style="max-width:760px">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px">
      <div style="font-size:28px">🔔</div>
      <h1 class="ox" style="font-size:28px;font-weight:800;margin:0">Inbox</h1>
    </div>

    <p style="color:rgba(255,255,255,.5);margin:0 0 18px">Friend requests, purchase receipts, and announcements.</p>

    <div style="display:flex;flex-direction:column;gap:10px">
      <div
        v-for="(item, i) in items"
        :key="i"
        style="display:flex;gap:14px;background:#151517;border:1px solid rgba(255,255,255,.07);border-radius:12px;padding:16px"
      >
        <div style="width:42px;height:42px;flex:none;border-radius:11px;background:rgba(232,72,47,.14);display:grid;place-items:center;font-size:20px">{{ item.icon }}</div>
        <div style="flex:1;min-width:0">
          <div style="display:flex;justify-content:space-between;gap:10px">
            <div class="ox" style="font-weight:700;font-size:14px">{{ item.title }}</div>
          </div>
          <div style="font-size:13px;color:rgba(255,255,255,.55);line-height:1.5;margin:4px 0 12px">{{ item.body }}</div>
          <div v-if="item.invite" style="display:flex;gap:8px">
            <button @click="accept(item)" style="background:#e8482f;color:#fff;border:none;border-radius:8px;padding:8px 16px;font-size:12px;font-weight:700;cursor:pointer">Accept</button>
            <button @click="decline(item)" style="background:#1f1f23;color:rgba(255,255,255,.7);border:none;border-radius:8px;padding:8px 16px;font-size:12px;font-weight:600;cursor:pointer">Decline</button>
          </div>
        </div>
      </div>
      <div v-if="!items.length" style="color:rgba(255,255,255,.35);font-size:13px">Nothing here yet.</div>
    </div>
  </div>
</template>
