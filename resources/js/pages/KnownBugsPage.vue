<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../api/client';
import Skeleton from '../components/Skeleton.vue';

const bugs = ref([]);
const loading = ref(true);
const filter = ref('open'); // 'open' (reported+investigating) | 'fixed' | 'all'

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get('/known-bugs');
    bugs.value = data.bugs;
  } finally {
    loading.value = false;
  }
}

const filtered = computed(() => {
  if (filter.value === 'all') return bugs.value;
  if (filter.value === 'fixed') return bugs.value.filter((b) => b.status === 'fixed');
  return bugs.value.filter((b) => b.status !== 'fixed');
});

const openCount = computed(() => bugs.value.filter((b) => b.status !== 'fixed').length);

const STATUS_LABEL = { reported: 'Reported', investigating: 'Investigating', fixed: 'Fixed' };
const SEVERITY_LABEL = { minor: 'Minor', major: 'Major', critical: 'Critical' };

function timeAgo(isoString) {
  const seconds = Math.max(0, Math.round((Date.now() - new Date(isoString).getTime()) / 1000));
  if (seconds < 60) return 'just now';
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
  if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`;
  return `${Math.floor(seconds / 86400)}d ago`;
}

onMounted(load);
</script>

<template>
  <div class="bugs-page">
    <div class="bugs-header">
      <div class="bugs-header__icon">🐞</div>
      <h1 class="ox bugs-title">Known Bugs</h1>
      <p class="bugs-header__subtitle">
        Check here before reporting — if it's already listed, no need to file a duplicate ticket.
      </p>
    </div>

    <div class="bugs-tabs">
      <button class="bugs-tab" :class="{ 'bugs-tab--active': filter === 'open' }" @click="filter = 'open'">
        Open
        <span v-if="openCount" class="bugs-tab__badge">{{ openCount }}</span>
      </button>
      <button class="bugs-tab" :class="{ 'bugs-tab--active': filter === 'fixed' }" @click="filter = 'fixed'">Fixed</button>
      <button class="bugs-tab" :class="{ 'bugs-tab--active': filter === 'all' }" @click="filter = 'all'">All</button>
    </div>

    <div v-if="loading" class="bugs-skeleton">
      <Skeleton height="90px" :count="4" />
    </div>
    <div v-else class="bugs-list">
      <div v-for="bug in filtered" :key="bug.id" class="bug-row" :class="{ 'bug-row--fixed': bug.status === 'fixed' }">
        <div class="bug-row__head">
          <span class="ox bug-row__title">{{ bug.title }}</span>
          <span class="bug-row__status" :class="`bug-row__status--${bug.status}`">{{ STATUS_LABEL[bug.status] }}</span>
          <span class="bug-row__severity" :class="`bug-row__severity--${bug.severity}`">{{ SEVERITY_LABEL[bug.severity] }}</span>
        </div>
        <p class="bug-row__desc">{{ bug.description }}</p>
        <div class="bug-row__meta">
          <span v-if="bug.area">{{ bug.area }}</span>
          <span>Logged {{ timeAgo(bug.created_at) }}</span>
          <span v-if="bug.fixed_at">Fixed {{ timeAgo(bug.fixed_at) }}</span>
        </div>
      </div>
      <p v-if="!filtered.length" class="bugs-empty">Nothing here — {{ filter === 'fixed' ? 'no fixed bugs logged yet.' : 'no known issues right now.' }}</p>
    </div>
  </div>
</template>

<style lang="scss" src="./KnownBugsPage.scss" scoped></style>
