<script setup>
import { ref, onMounted, watch } from 'vue';
import { useRouter } from 'vue-router';
import api from '../api/client';
import AdBanner from '../components/AdBanner.vue';
import Toast from '../components/Toast.vue';
import { DIFFICULTY } from '../utils/difficulty';

const router = useRouter();
const dungeons = ref([]);
const error = ref('');
let errorToastTimer = null;
watch(error, (val) => {
  clearTimeout(errorToastTimer);
  if (val) errorToastTimer = setTimeout(() => { error.value = ''; }, 5000);
});
const attemptsUsed = ref(0);
const attemptsMax = ref(3);

async function load() {
  const { data } = await api.get('/dungeons');
  dungeons.value = data.dungeons;
  attemptsUsed.value = data.dungeon_attempts_used;
  attemptsMax.value = data.dungeon_attempts_max;
}

async function enter(row) {
  error.value = '';
  try {
    await api.post(`/dungeons/${row.dungeon.id}/enter`);
    router.push('/battle');
  } catch (e) {
    error.value = e.response?.data?.message || 'Cannot enter yet.';
  } finally {
    await load();
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
    <div class="dungeons-attempts">{{ attemptsMax - attemptsUsed }} / {{ attemptsMax }} raids left today</div>
    <Toast :message="error" type="error" />

    <AdBanner variant="inline" />

    <div class="dungeon-grid">
      <div v-for="row in dungeons" :key="row.dungeon.id" class="dungeon-card" :class="{ 'is-locked': !row.unlocked }">
        <div class="dungeon-card__art" :style="{ background: DIFFICULTY[row.dungeon.difficulty]?.art }">
          {{ row.dungeon.glyph }}
          <div v-if="!row.unlocked" class="dungeon-card__lock">🔒</div>
        </div>
        <div class="dungeon-card__body">
          <div class="dungeon-card__row">
            <div class="ox dungeon-card__name">{{ row.dungeon.name }}</div>
            <span
              class="dungeon-card__difficulty"
              :style="{ background: DIFFICULTY[row.dungeon.difficulty]?.bg, color: DIFFICULTY[row.dungeon.difficulty]?.color }"
              >{{ row.dungeon.difficulty }}</span
            >
          </div>
          <div class="dungeon-card__level">Recommended Lv.{{ row.dungeon.min_level }}+</div>
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
          <button
            type="button"
            @click="enter(row)"
            :disabled="!row.unlocked || (!row.active_run && attemptsUsed >= attemptsMax)"
            class="dungeon-card__enter-btn"
          >
            {{ row.active_run ? `Resume (Stage ${row.active_run.stage}/${row.active_run.total_stages})` : row.unlocked ? 'Enter' : `Requires Lv.${row.dungeon.min_level}` }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style lang="scss" src="./DungeonsPage.scss" scoped></style>
