<script setup>
import { ref, onMounted } from 'vue';
import api from '../api/client';
import AdBanner from '../components/AdBanner.vue';

const rows = ref([]);

onMounted(async () => {
  const { data } = await api.get('/leaderboard');
  rows.value = data.leaderboard;
});
</script>

<template>
  <div>
    <div class="leaderboard-header">
      <div class="leaderboard-header__icon">🏆</div>
      <h1 class="ox leaderboard-title">Leaderboard</h1>
    </div>

    <div class="leaderboard-ad">
      <AdBanner variant="inline" />
    </div>

    <div class="leaderboard-table">
      <div
        v-for="row in rows"
        :key="row.character_id"
        class="leaderboard-row"
      >
        <span class="ox leaderboard-row__rank">#{{ row.rank }}</span>
        <span class="ox leaderboard-row__name">{{ row.name }}</span>
        <span class="leaderboard-row__meta">{{ row.base_class }} · Lv.{{ row.level }}</span>
        <span class="ox leaderboard-row__power">{{ row.power }}</span>
      </div>
      <div v-if="rows.length === 0" class="leaderboard-empty">No ranked characters yet.</div>
    </div>
  </div>
</template>

<style lang="scss" src="./LeaderboardPage.scss" scoped></style>
