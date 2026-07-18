<script setup>
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import api from '../api/client';
import AdBanner from '../components/AdBanner.vue';

const router = useRouter();
const zones = ref([]);
const error = ref('');

const DANGER_COLOR = { safe: '#4ade80', medium: '#eab308', high: '#ff8163', deadly: '#a78bfa' };

async function load() {
  const { data } = await api.get('/zones');
  zones.value = data.zones;
}

async function travel(row) {
  error.value = '';
  try {
    await api.post(`/zones/${row.zone.id}/travel`);
    router.push('/battle');
  } catch (e) {
    error.value = e.response?.data?.message || 'Cannot travel there yet.';
  }
}

onMounted(load);
</script>

<template>
  <div>
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px">
      <div style="font-size:28px">🗺</div>
      <h1 class="ox" style="font-size:28px;font-weight:800;margin:0">World Map</h1>
    </div>

    <p v-if="error" style="color:#ff6a4d;font-size:13px;margin-bottom:14px">{{ error }}</p>

    <AdBanner variant="inline" />

    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px">
      <button
        v-for="row in zones"
        :key="row.zone.id"
        @click="travel(row)"
        :disabled="!row.unlocked"
        :style="{
          background: '#151517',
          border: '1px solid rgba(255,255,255,.07)',
          borderRadius: '13px',
          padding: '18px',
          textAlign: 'left',
          cursor: row.unlocked ? 'pointer' : 'not-allowed',
          opacity: row.unlocked ? 1 : 0.5,
          color: '#fff',
        }"
      >
        <div style="font-size:24px;margin-bottom:8px">{{ row.zone.glyph }}</div>
        <div class="ox" style="font-weight:700;font-size:15px;margin-bottom:4px">{{ row.zone.name }}</div>
        <div style="font-size:11.5px;color:rgba(255,255,255,.45)">
          <span :style="{ color: DANGER_COLOR[row.zone.danger] }">{{ row.zone.danger }}</span>
          · Lv.{{ row.zone.min_level }}+
          <span v-if="row.zone.locked"> · 🔒 Locked</span>
        </div>
      </button>
    </div>
  </div>
</template>
