<script setup>
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import api from '../api/client';
import AdBanner from '../components/AdBanner.vue';

const router = useRouter();
const zones = ref([]);
const error = ref('');

const DANGER = {
  safe: { color: '#4ade80', bg: 'rgba(74,222,128,.13)', art: 'repeating-linear-gradient(45deg,#152318,#152318 10px,#101c14 10px,#101c14 20px)' },
  medium: { color: '#eab308', bg: 'rgba(234,179,8,.13)', art: 'repeating-linear-gradient(45deg,#231b10,#231b10 10px,#1c150c 10px,#1c150c 20px)' },
  high: { color: '#ff8163', bg: 'rgba(255,129,99,.13)', art: 'repeating-linear-gradient(135deg,#20161b,#20161b 10px,#1a1216 10px,#1a1216 20px)' },
  deadly: { color: '#a78bfa', bg: 'rgba(167,139,250,.13)', art: 'repeating-linear-gradient(135deg,#1a1425,#1a1425 10px,#14101d 10px,#14101d 20px)' },
};

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

    <p style="color:rgba(255,255,255,.5);margin:0 0 18px">Travel between zones. Locked zones require a higher level.</p>
    <p v-if="error" style="color:#ff6a4d;font-size:13px;margin-bottom:14px">{{ error }}</p>

    <AdBanner variant="inline" />

    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px">
      <div
        v-for="row in zones"
        :key="row.zone.id"
        :style="{
          background: '#151517',
          border: `1px solid ${row.unlocked ? 'rgba(255,255,255,.07)' : 'rgba(255,255,255,.04)'}`,
          borderRadius: '14px',
          overflow: 'hidden',
          opacity: row.unlocked ? 1 : 0.6,
        }"
      >
        <div :style="{ height: '120px', background: DANGER[row.zone.danger]?.art, display: 'grid', placeItems: 'center', fontSize: '40px', position: 'relative' }">
          {{ row.zone.glyph }}
          <div v-if="row.zone.locked" style="position:absolute;inset:0;background:rgba(0,0,0,.55);display:grid;place-items:center;font-size:26px">🔒</div>
        </div>
        <div style="padding:16px">
          <div style="display:flex;justify-content:space-between;align-items:center">
            <div class="ox" style="font-weight:700;font-size:15px">{{ row.zone.name }}</div>
            <span :style="{ fontSize: '11px', padding: '2px 8px', borderRadius: '5px', background: DANGER[row.zone.danger]?.bg, color: DANGER[row.zone.danger]?.color, textTransform: 'capitalize' }">
              {{ row.zone.danger }}
            </span>
          </div>
          <div style="font-size:12px;color:rgba(255,255,255,.5);margin:6px 0 12px">Recommended Lv.{{ row.zone.min_level }}+</div>
          <button
            @click="travel(row)"
            :disabled="!row.unlocked"
            :style="{
              width: '100%',
              padding: '9px',
              borderRadius: '8px',
              border: 'none',
              background: row.unlocked ? '#e8482f' : 'rgba(255,255,255,.08)',
              color: '#fff',
              fontWeight: 700,
              fontSize: '12.5px',
              cursor: row.unlocked ? 'pointer' : 'not-allowed',
            }"
          >
            {{ row.unlocked ? 'Travel' : `Requires Lv.${row.zone.min_level}` }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
