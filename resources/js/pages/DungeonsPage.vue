<script setup>
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import api from '../api/client';
import AdBanner from '../components/AdBanner.vue';

const router = useRouter();
const dungeons = ref([]);
const error = ref('');

const DIFF = {
  normal: { color: '#4ade80', bg: 'rgba(74,222,128,.13)' },
  hard: { color: '#eab308', bg: 'rgba(234,179,8,.13)' },
  raid: { color: '#ff8163', bg: 'rgba(255,129,99,.13)' },
  mythic: { color: '#a78bfa', bg: 'rgba(167,139,250,.13)' },
};

async function load() {
  const { data } = await api.get('/dungeons');
  dungeons.value = data.dungeons;
}

async function enter(row) {
  error.value = '';
  try {
    await api.post(`/dungeons/${row.dungeon.id}/enter`);
    router.push('/battle');
  } catch (e) {
    error.value = e.response?.data?.message || 'Cannot enter yet.';
  }
}

onMounted(load);
</script>

<template>
  <div>
    <div class="dungeons-header">
      <div class="dungeons-header__icon">🏰</div>
      <h1 class="ox dungeons-title">Dungeons</h1>
    </div>

    <p class="dungeons-intro">Boss raids with dedicated drop tables. Higher difficulty, better rewards.</p>
    <p v-if="error" class="dungeons-error">{{ error }}</p>

    <AdBanner variant="inline" />

    <div class="dungeon-list">
      <div v-for="row in dungeons" :key="row.dungeon.id" class="dungeon-card" :class="{ 'dungeon-card--locked': !row.unlocked }">
        <div class="dungeon-card__art">
          {{ row.unlocked ? row.dungeon.glyph : '🔒' }}
        </div>
        <div class="dungeon-card__body">
          <div class="dungeon-card__title-row">
            <div class="ox dungeon-card__name">{{ row.unlocked ? row.dungeon.name : '???' }}</div>
            <span
              v-if="row.unlocked"
              class="dungeon-card__difficulty"
              :style="{ background: DIFF[row.dungeon.difficulty]?.bg, color: DIFF[row.dungeon.difficulty]?.color }"
              >{{ row.dungeon.difficulty }}</span
            >
          </div>
          <template v-if="row.unlocked">
            <div class="dungeon-card__meta">
              Boss: {{ row.dungeon.boss_monster?.name ?? 'Unknown' }}
              <span v-if="row.dungeon.party_size > 1"> · Party of {{ row.dungeon.party_size }}</span>
            </div>
            <div v-if="row.dungeon.drops_json" class="dungeon-card__drops">
              Drops:
              <span v-if="row.dungeon.drops_json.gold">{{ row.dungeon.drops_json.gold }}g</span>
              <span v-if="row.dungeon.drops_json.gems"> · {{ row.dungeon.drops_json.gems }}◆</span>
            </div>
          </template>
          <div v-else class="dungeon-card__meta dungeon-card__meta--locked">
            🔒 Unlocks at level {{ row.dungeon.min_level }}
          </div>
        </div>
        <button @click="enter(row)" :disabled="!row.unlocked" class="dungeon-card__enter">
          {{ row.active_run ? `Resume (Stage ${row.active_run.stage}/${row.active_run.total_stages})` : row.unlocked ? 'Enter' : `Lv.${row.dungeon.min_level}` }}
        </button>
      </div>
    </div>
  </div>
</template>

<style lang="scss" src="./DungeonsPage.scss" scoped></style>
