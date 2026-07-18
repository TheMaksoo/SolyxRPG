<script setup>
import { ref, onMounted } from 'vue';
import api from '../api/client';

const pets = ref([]);
const message = ref('');

async function load() {
  const { data } = await api.get('/pets');
  pets.value = data.pets;
}

async function unlock(row) {
  message.value = '';
  try {
    await api.post(`/pets/${row.pet.id}/unlock`);
    await load();
  } catch (e) {
    message.value = e.response?.data?.message || 'Not enough gems.';
  }
}

async function activate(row) {
  await api.post(`/pets/${row.pet.id}/activate`);
  await load();
}

onMounted(load);
</script>

<template>
  <div>
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px">
      <div style="font-size:28px">🐾</div>
      <h1 class="ox" style="font-size:28px;font-weight:800;margin:0">Companions</h1>
    </div>

    <p v-if="message" style="font-size:13px;color:#ff6a4d;margin-bottom:14px">{{ message }}</p>

    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px">
      <div
        v-for="row in pets"
        :key="row.pet.id"
        :style="{
          background: '#151517',
          border: row.active ? '1px solid #e8482f' : '1px solid rgba(255,255,255,.07)',
          borderRadius: '13px',
          padding: '16px',
        }"
      >
        <div style="font-size:24px;margin-bottom:8px">{{ row.pet.glyph }}</div>
        <div class="ox" style="font-weight:700;font-size:14.5px;margin-bottom:4px">{{ row.pet.name }}</div>
        <div style="font-size:12px;color:rgba(255,255,255,.5);margin-bottom:12px">{{ row.pet.description }}</div>
        <button
          v-if="!row.owned"
          @click="unlock(row)"
          style="width:100%;padding:9px;border-radius:8px;border:none;background:#e8482f;color:#fff;font-weight:700;font-size:12.5px;cursor:pointer"
        >
          Unlock — {{ row.pet.unlock_gems }}◆
        </button>
        <button
          v-else-if="!row.active"
          @click="activate(row)"
          style="width:100%;padding:9px;border-radius:8px;border:1px solid rgba(255,255,255,.12);background:transparent;color:#fff;font-size:12.5px;cursor:pointer"
        >
          Activate
        </button>
        <span v-else style="font-size:12px;color:#4ade80">Active</span>
      </div>
    </div>
  </div>
</template>
