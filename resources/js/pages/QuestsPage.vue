<script setup>
import { ref, onMounted, computed } from 'vue';
import api from '../api/client';
import AdBanner from '../components/AdBanner.vue';
import { useCharacterStore } from '../stores/character';

const characterStore = useCharacterStore();
const rows = ref([]);
const questsCompleted = ref(0);
const tab = ref('daily');
const message = ref('');
const claimSummary = ref(null);

const tabs = [
  { key: 'daily', label: 'Daily' },
  { key: 'weekly', label: 'Weekly' },
  { key: 'monthly', label: 'Monthly' },
  { key: 'main', label: 'Main' },
  { key: 'raid', label: 'Raid' },
];

const filtered = computed(() => rows.value.filter((r) => r.quest.type === tab.value));

function applyPayload(data) {
  rows.value = data.quests;
  questsCompleted.value = data.quests_completed;
}

function rewardParts(reward) {
  const parts = [];
  if (reward?.gold) parts.push(`💰 ${reward.gold} Gold`);
  if (reward?.gems) parts.push(`💎 ${reward.gems} Gems`);
  if (reward?.xp) parts.push(`✨ ${reward.xp} XP`);
  return parts;
}

function progressPct(row) {
  const target = row.quest.goal_json.target ?? 1;
  return Math.min(100, Math.round((row.progress / target) * 100));
}

async function load() {
  const { data } = await api.get('/quests');
  applyPayload(data);
}

async function claim(row) {
  message.value = '';
  try {
    const { data } = await api.post(`/quests/${row.quest.id}/claim`);
    applyPayload(data);
    // Gold/gems land immediately in the sidebar balance and everywhere else that reads the
    // character store, rather than needing a manual refresh to notice the reward.
    if (data.character) characterStore.character = data.character;
    claimSummary.value = {
      title: `${row.quest.name} claimed`,
      gold: data.reward?.gold,
      gems: data.reward?.gems,
      xp: data.reward?.xp,
    };
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
      <div class="quests-header__completed">✅ {{ questsCompleted }} quests completed</div>
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

    <div v-if="claimSummary" class="claim-summary">
      <div class="claim-summary__header">
        <span class="ox claim-summary__title">🎉 {{ claimSummary.title }}</span>
        <button class="claim-summary__close" @click="claimSummary = null">✕</button>
      </div>
      <div class="claim-summary__rows">
        <span v-if="claimSummary.gold" class="claim-summary__chip">🪙 +{{ claimSummary.gold }} gold</span>
        <span v-if="claimSummary.gems" class="claim-summary__chip">💎 +{{ claimSummary.gems }} gems</span>
        <span v-if="claimSummary.xp" class="claim-summary__chip">✨ +{{ claimSummary.xp }} xp</span>
      </div>
    </div>

    <AdBanner variant="inline" />

    <div class="quests-list">
      <div
        v-for="row in filtered"
        :key="row.quest.id"
        class="quest-row"
        :class="{ 'quest-row--claimed': row.claimed }"
      >
        <div class="quest-row__info">
          <div class="quest-row__name-line">
            <span class="ox quest-row__name">{{ row.quest.name }}</span>
            <span class="quest-row__type-badge" :class="`quest-row__type-badge--${row.quest.type}`">{{ row.quest.type }}</span>
          </div>
          <div class="quest-row__desc">{{ row.quest.description }}</div>

          <div class="quest-row__bar-track">
            <div class="quest-row__bar-fill" :class="{ 'quest-row__bar-fill--done': row.completed }" :style="{ width: progressPct(row) + '%' }"></div>
          </div>
          <div class="quest-row__progress">
            {{ row.progress }} / {{ row.quest.goal_json.target ?? 1 }}
          </div>

          <div class="quest-row__rewards">
            <span v-for="part in rewardParts(row.quest.reward_json)" :key="part" class="quest-row__reward-chip">
              {{ part }}
            </span>
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
