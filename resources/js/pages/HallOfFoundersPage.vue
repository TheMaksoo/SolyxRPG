<script setup>
import { ref, onMounted } from 'vue';
import api from '../api/client';
import Skeleton from '../components/Skeleton.vue';

const founders = ref([]);
const loading = ref(true);

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get('/founders');
    founders.value = data.founders;
  } finally {
    loading.value = false;
  }
}

function formatDate(iso) {
  return iso ? new Date(iso).toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' }) : '';
}

onMounted(load);
</script>

<template>
  <div class="founders-page">
    <div class="founders-header">
      <div class="founders-header__icon">⚜️</div>
      <h1 class="ox founders-title">Hall of Founders</h1>
      <p class="founders-header__subtitle">Everyone who backed Solyx during beta with the Founder Beta Pack.</p>
    </div>

    <div v-if="loading" class="founders-skeleton">
      <Skeleton height="36px" :count="8" />
    </div>
    <div v-else class="founders-list">
      <div v-for="(f, i) in founders" :key="f.name + f.founded_at" class="founder-row">
        <span class="founder-row__rank">#{{ i + 1 }}</span>
        <span class="ox founder-row__name">{{ f.name }}</span>
        <span class="founder-row__date">{{ formatDate(f.founded_at) }}</span>
      </div>
      <p v-if="!founders.length" class="founders-empty">No Founders yet — be the first, from the Premium page.</p>
    </div>
  </div>
</template>

<style lang="scss" src="./HallOfFoundersPage.scss" scoped></style>
