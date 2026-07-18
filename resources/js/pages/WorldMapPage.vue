<script setup>
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import api from '../api/client';
import { useCharacterStore } from '../stores/character';
import AdBanner from '../components/AdBanner.vue';

const router = useRouter();
const characterStore = useCharacterStore();
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
  if (!characterStore.character) await characterStore.fetch();
}

async function travel(row) {
  error.value = '';
  try {
    const { data } = await api.post(`/zones/${row.zone.id}/travel`);
    characterStore.character = data.character;
    router.push('/battle');
  } catch (e) {
    error.value = e.response?.data?.message || 'Cannot travel there yet.';
  }
}

onMounted(load);
</script>

<template>
  <div>
    <div class="world-map-header">
      <div class="world-map-header__icon">🗺</div>
      <h1 class="ox world-map-title">World Map</h1>
    </div>

    <p class="world-map-intro">Travel between zones. Locked zones require a higher level.</p>
    <p v-if="error" class="world-map-error">{{ error }}</p>

    <AdBanner variant="inline" />

    <div class="zone-grid">
      <div
        v-for="row in zones"
        :key="row.zone.id"
        class="zone-card"
        :class="{ 'is-locked': !row.unlocked, 'is-current': characterStore.character?.current_zone_id === row.zone.id }"
      >
        <div class="zone-card__art" :style="{ background: DANGER[row.zone.danger]?.art }">
          {{ row.zone.glyph }}
          <div v-if="row.zone.locked" class="zone-card__lock">🔒</div>
        </div>
        <div class="zone-card__body">
          <div class="zone-card__row">
            <div class="zone-card__name-group">
              <div class="ox zone-card__name">{{ row.zone.name }}</div>
              <span v-if="characterStore.character?.current_zone_id === row.zone.id" class="zone-card__here-badge">📍 Here</span>
            </div>
            <span
              class="zone-card__danger-badge"
              :style="{ background: DANGER[row.zone.danger]?.bg, color: DANGER[row.zone.danger]?.color }"
            >
              {{ row.zone.danger }}
            </span>
          </div>
          <div class="zone-card__level">Recommended Lv.{{ row.zone.min_level }}+</div>
          <button @click="travel(row)" :disabled="!row.unlocked" class="zone-card__travel-btn">
            {{ row.unlocked ? 'Travel' : `Requires Lv.${row.zone.min_level}` }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style lang="scss" src="./WorldMapPage.scss" scoped></style>
