<script setup>
import { ref, onMounted } from 'vue';
import api from '../api/client';
import AdBanner from '../components/AdBanner.vue';
import VipBadge from '../components/VipBadge.vue';

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
      <router-link
        v-for="row in rows"
        :key="row.character_id"
        :to="{ name: 'public-profile', params: { id: row.character_id } }"
        class="leaderboard-row"
        :style="row.banner ? { background: row.banner } : null"
      >
        <span class="ox leaderboard-row__rank">#{{ row.rank }}</span>
        <span class="leaderboard-row__name-wrap">
          <span v-if="row.icon" class="leaderboard-row__icon">{{ row.icon }}</span>
          <span
            class="ox leaderboard-row__name"
            :style="row.name_color ? { color: row.name_color } : null"
          >{{ row.name }}</span>
          <span v-if="row.title" class="leaderboard-row__title-badge">{{ row.title }}</span>
          <VipBadge :tier="row.vip_tier" />
        </span>
        <span class="leaderboard-row__meta">{{ row.base_class }} · Lv.{{ row.level }}</span>
        <span class="ox leaderboard-row__power">{{ row.power }}</span>
      </router-link>
      <div v-if="rows.length === 0" class="leaderboard-empty">No ranked characters yet.</div>
    </div>
  </div>
</template>

<style lang="scss" src="./LeaderboardPage.scss" scoped></style>
