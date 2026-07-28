<script setup>
import { ref, onMounted, computed } from 'vue';
import api from '../api/client';

const pets = ref([]);
const message = ref('');
const maxActiveSlots = ref(1);
const activeCount = ref(0);

const petRows = computed(() => pets.value);

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

const rankingUp = ref(null);
async function rankUp(row) {
  if (rankingUp.value) return;
  message.value = '';
  rankingUp.value = row.pet.id;
  try {
    await api.post(`/pets/${row.pet.id}/rank-up`);
    await load();
  } catch (e) {
    message.value = e.response?.data?.message || 'Could not rank up that companion.';
  } finally {
    rankingUp.value = null;
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
        :class="{ 'pet-card--active': row.active }"
      >
        <div class="pet-card__glyph">{{ row.pet.glyph }}</div>
        <div class="ox pet-card__name">{{ row.pet.name }}</div>
        <div class="pet-card__desc">{{ row.pet.description }}</div>
        <div class="pet-card__bonuses">
          <span v-for="b in row.bonuses" :key="b.label" class="pet-card__bonus-chip">+{{ b.pct }}% {{ b.label }}</span>
        </div>
        <div v-if="row.owned" class="pet-card__level-block">
          <div class="pet-card__level-row">
            <span>Rank {{ row.rank }} · Lv.{{ row.level }}</span>
            <span v-if="!row.can_rank_up">{{ row.xp }} / {{ row.xp_needed }} xp</span>
            <span v-else-if="row.rank >= row.max_rank">MAX RANK</span>
            <span v-else>READY TO RANK UP</span>
          </div>
          <div class="pet-card__xp-track">
            <div
              class="pet-card__xp-fill"
              :style="{ width: (row.can_rank_up ? 100 : Math.round((row.xp / row.xp_needed) * 100)) + '%' }"
            ></div>
          </div>
        </div>
        <button
          v-if="!row.owned"
          @click="unlock(row)"
          class="pet-card__btn--unlock"
        >
          <template v-if="row.pet.unlock_gold">Unlock — {{ row.pet.unlock_gold }}🪙</template>
          <template v-else>Unlock — {{ row.pet.unlock_gems }}◆</template>
        </button>
        <button
          v-else-if="row.can_rank_up"
          @click="rankUp(row)"
          :disabled="rankingUp === row.pet.id"
          class="pet-card__btn--rank-up"
        >
          {{ rankingUp === row.pet.id ? 'Ranking up…' : `Rank Up — ${row.rank_up_cost.gold}🪙${row.rank_up_cost.gems ? ` + ${row.rank_up_cost.gems}◆` : ''}` }}
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
