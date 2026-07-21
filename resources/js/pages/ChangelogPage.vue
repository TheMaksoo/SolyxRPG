<script setup>
import { ref, onMounted } from 'vue';
import api from '../api/client';
import Skeleton from '../components/Skeleton.vue';

const entries = ref([]);
const loading = ref(true);

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get('/changelog');
    entries.value = data.entries;
  } finally {
    loading.value = false;
  }
}

const TAG_LABEL = { feature: 'New', fix: 'Fix', balance: 'Balance', misc: 'Misc' };

function formatDate(isoString) {
  return new Date(isoString).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

onMounted(load);
</script>

<template>
  <div class="changelog-page">
    <div class="changelog-header">
      <div class="changelog-header__icon">📜</div>
      <h1 class="ox changelog-title">Changelog</h1>
      <span v-if="entries.length" class="changelog-header__version">Current version: v{{ entries[0].version }}</span>
    </div>

    <div v-if="loading" class="changelog-skeleton">
      <Skeleton height="90px" :count="4" />
    </div>
    <div v-else class="changelog-list">
      <div v-for="entry in entries" :key="entry.id" class="changelog-row">
        <div class="changelog-row__head">
          <span class="changelog-row__version">v{{ entry.version }}</span>
          <span class="changelog-row__tag" :class="`changelog-row__tag--${entry.tag}`">{{ TAG_LABEL[entry.tag] ?? entry.tag }}</span>
          <span class="ox changelog-row__title">{{ entry.title }}</span>
          <span class="changelog-row__date">{{ formatDate(entry.published_at) }}</span>
        </div>
        <p class="changelog-row__body">{{ entry.body }}</p>
      </div>
      <p v-if="!entries.length" class="changelog-empty">No updates logged yet.</p>
    </div>
  </div>
</template>

<style lang="scss" src="./ChangelogPage.scss" scoped></style>
