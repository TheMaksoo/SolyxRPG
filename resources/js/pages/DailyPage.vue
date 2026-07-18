<script setup>
import { ref, onMounted } from 'vue';
import api from '../api/client';
import AdBanner from '../components/AdBanner.vue';

const info = ref(null);
const message = ref('');

async function load() {
  const { data } = await api.get('/daily');
  info.value = data;
}

async function claim() {
  message.value = '';
  try {
    const { data } = await api.post('/daily/claim');
    message.value = `+${data.gold}g${data.gems ? ` +${data.gems}◆` : ''} — streak ${data.streak}`;
    await load();
  } catch (e) {
    message.value = e.response?.data?.message || 'Already claimed.';
  }
}

onMounted(load);
</script>

<template>
  <div>
    <div class="daily-header">
      <div class="daily-header__icon">🎁</div>
      <h1 class="ox daily-title">Daily</h1>
    </div>

    <div class="daily-ad-wrap">
      <AdBanner variant="inline" />
    </div>

    <div v-if="info" class="daily-card">
      <div class="ox daily-card__streak">{{ info.streak }}</div>
      <div class="daily-card__label">day streak</div>
      <p v-if="message" class="daily-message">{{ message }}</p>
      <button
        @click="claim"
        :disabled="!info.can_claim"
        class="daily-claim-btn"
      >
        {{ info.can_claim ? 'Claim today’s reward' : 'Already claimed' }}
      </button>
    </div>
  </div>
</template>

<style lang="scss" src="./DailyPage.scss" scoped></style>
