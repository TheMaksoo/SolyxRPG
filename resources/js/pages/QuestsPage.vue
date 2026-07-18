<script setup>
import { ref, onMounted, computed } from 'vue';
import api from '../api/client';
import AdBanner from '../components/AdBanner.vue';

const rows = ref([]);
const tab = ref('daily');
const message = ref('');

const tabs = [
  { key: 'daily', label: 'Daily' },
  { key: 'weekly', label: 'Weekly' },
  { key: 'main', label: 'Main' },
  { key: 'raid', label: 'Raid' },
];

const filtered = computed(() => rows.value.filter((r) => r.quest.type === tab.value));

async function load() {
  const { data } = await api.get('/quests');
  rows.value = data.quests;
}

async function claim(row) {
  message.value = '';
  try {
    await api.post(`/quests/${row.quest.id}/claim`);
    await load();
  } catch (e) {
    message.value = e.response?.data?.message || 'Could not claim.';
  }
}

onMounted(load);
</script>

<template>
  <div>
    <div class="quests-header">
      <div class="quests-header__icon">📜</div>
      <h1 class="ox quests-title">Quests</h1>
    </div>

    <div class="quests-tabs">
      <button
        v-for="t in tabs"
        :key="t.key"
        @click="tab = t.key"
        class="quest-tab"
        :class="{ 'quest-tab--active': tab === t.key }"
      >
        {{ t.label }}
      </button>
    </div>

    <p v-if="message" class="quests-message">{{ message }}</p>

    <AdBanner variant="inline" />

    <div class="quests-list">
      <div
        v-for="row in filtered"
        :key="row.quest.id"
        class="quest-row"
      >
        <div class="quest-row__info">
          <div class="ox quest-row__name">{{ row.quest.name }}</div>
          <div class="quest-row__desc">{{ row.quest.description }}</div>
          <div class="quest-row__progress">
            Progress: {{ row.progress }} / {{ row.quest.goal_json.target ?? 1 }}
          </div>
        </div>
        <button
          v-if="row.completed && !row.claimed"
          @click="claim(row)"
          class="quest-row__claim-btn"
        >
          Claim
        </button>
        <span v-else-if="row.claimed" class="quest-row__status--claimed">Claimed</span>
        <span v-else class="quest-row__status--pending">In progress</span>
      </div>
    </div>
  </div>
</template>

<style lang="scss" src="./QuestsPage.scss" scoped></style>
