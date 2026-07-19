<script setup>
import { ref, onMounted, computed } from 'vue';
import api from '../api/client';

const pets = ref([]);
const message = ref('');
const maxActiveSlots = ref(1);
const activeCount = ref(0);

// The two cheapest not-yet-owned companions get shown in full (a clear, affordable goal); pricier
// unowned ones stay a mystery until unlocked, so the roster still has something left to wonder about.
const REVEALED_UNOWNED = 2;

const petRows = computed(() => {
  const unownedSorted = [...pets.value]
    .filter((row) => !row.owned)
    .sort((a, b) => a.pet.unlock_gems - b.pet.unlock_gems)
    .slice(0, REVEALED_UNOWNED)
    .map((row) => row.pet.id);

  return pets.value.map((row) => ({
    ...row,
    mystery: !row.owned && !unownedSorted.includes(row.pet.id),
  }));
});

async function load() {
  const { data } = await api.get('/pets');
  pets.value = data.pets;
  maxActiveSlots.value = data.max_active_slots;
  activeCount.value = data.active_count;
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
  message.value = '';
  try {
    await api.post(`/pets/${row.pet.id}/activate`);
    await load();
  } catch (e) {
    message.value = e.response?.data?.message || 'Could not toggle that companion.';
  }
}

onMounted(load);
</script>

<template>
  <div>
    <div class="pets-header">
      <div class="pets-header__icon">🐾</div>
      <h1 class="ox pets-title">Companions</h1>
      <span class="pets-header__slots">{{ activeCount }} / {{ maxActiveSlots }} active</span>
    </div>

    <p v-if="message" class="pets-message">{{ message }}</p>

    <div class="pets-grid">
      <div
        v-for="row in petRows"
        :key="row.pet.id"
        class="pet-card"
        :class="{ 'pet-card--active': row.active, 'pet-card--mystery': row.mystery }"
      >
        <template v-if="row.mystery">
          <div class="pet-card__glyph">❔</div>
          <div class="ox pet-card__name">???</div>
          <div class="pet-card__desc">🔒 Unlock for {{ row.pet.unlock_gems }}◆ to reveal this companion.</div>
        </template>
        <template v-else>
          <div class="pet-card__glyph">{{ row.pet.glyph }}</div>
          <div class="ox pet-card__name">{{ row.pet.name }}</div>
          <div class="pet-card__desc">{{ row.pet.description }}</div>
          <div class="pet-card__bonuses">
            <span v-for="b in row.bonuses" :key="b.label" class="pet-card__bonus-chip">+{{ b.pct }}% {{ b.label }}</span>
          </div>
        </template>
        <div v-if="row.owned" class="pet-card__level-block">
          <div class="pet-card__level-row">
            <span>Lv.{{ row.level }}</span>
            <span v-if="row.level < row.max_level">{{ row.xp }} / {{ row.xp_needed }} xp</span>
            <span v-else>MAX</span>
          </div>
          <div class="pet-card__xp-track">
            <div
              class="pet-card__xp-fill"
              :style="{ width: (row.level >= row.max_level ? 100 : Math.round((row.xp / row.xp_needed) * 100)) + '%' }"
            ></div>
          </div>
        </div>
        <button
          v-if="!row.owned"
          @click="unlock(row)"
          class="pet-card__btn--unlock"
        >
          Unlock — {{ row.pet.unlock_gems }}◆
        </button>
        <button
          v-else-if="!row.active"
          @click="activate(row)"
          class="pet-card__btn--activate"
        >
          Activate
        </button>
        <button v-else @click="activate(row)" class="pet-card__status--active">
          ✔ Active — click to bench
        </button>
      </div>
    </div>
  </div>
</template>

<style lang="scss" src="./PetsPage.scss" scoped></style>
