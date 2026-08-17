<script setup>
import { ref, onMounted, watch } from 'vue';
import { useRouter } from 'vue-router';
import api from '../api/client';
import { useCharacterStore } from '../stores/character';
import AdBanner from '../components/AdBanner.vue';
import Toast from '../components/Toast.vue';
import { DANGER } from '../utils/danger';

const router = useRouter();
const characterStore = useCharacterStore();
const zones = ref([]);
const error = ref('');
let errorToastTimer = null;
watch(error, (val) => {
  clearTimeout(errorToastTimer);
  if (val) errorToastTimer = setTimeout(() => { error.value = ''; }, 5000);
});

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
    <Toast :message="error" type="error" />

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
          <div v-if="row.unlocked" class="zone-card__safety">
            <span v-if="row.is_frontier" class="zone-card__safety-tag zone-card__safety-tag--frontier">⚔ Frontier · full rewards</span>
            <span v-else-if="row.reward_penalty_pct > 0" class="zone-card__safety-tag zone-card__safety-tag--penalty">
              ⚠ Outleveled · -{{ row.reward_penalty_pct }}% rewards
            </span>
            <span v-else class="zone-card__safety-tag zone-card__safety-tag--safe">✓ Full rewards</span>
            <span v-if="row.pet_food_bonus_pct > 0" class="zone-card__safety-tag zone-card__safety-tag--food">🍖 +{{ row.pet_food_bonus_pct }}% pet food</span>
          </div>
          <button @click="travel(row)" :disabled="!row.unlocked" class="zone-card__travel-btn">
            {{ row.unlocked ? 'Travel' : `Requires Lv.${row.zone.min_level}` }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style lang="scss" src="./WorldMapPage.scss" scoped></style>
