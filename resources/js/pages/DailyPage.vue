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
    const gemsPart = data.gems ? ` +${data.gems}◆` : '';
    message.value = `+${data.gold}g${gemsPart} — streak ${data.streak}`;
    info.value = data;
  } catch (e) {
    message.value = e.response?.data?.message || 'Already claimed.';
  }
}

function cellState(day) {
  if (day.claimed) return 'claimed';
  if (day.is_today) return 'today';
  return 'locked';
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

    <div v-if="info" class="daily-summary">
      <div class="daily-summary__streak">
        <div class="ox daily-summary__streak-num">{{ info.streak }}</div>
        <div class="daily-summary__streak-label">day streak</div>
      </div>
      <div class="daily-summary__day">Day {{ info.cycle_day }} of {{ info.cycle_length }} this month</div>
      <p v-if="message" class="daily-message">{{ message }}</p>
      <button @click="claim" :disabled="!info.can_claim" class="daily-claim-btn">
        {{ info.can_claim ? "Claim today's reward" : 'Already claimed' }}
      </button>
    </div>

    <div class="daily-calendar-eyebrow">THIS MONTH'S REWARDS</div>
    <div v-if="info" class="daily-calendar">
      <div v-for="day in info.days" :key="day.day" class="daily-cell" :class="`daily-cell--${cellState(day)}`">
        <div v-if="day.claimed" class="daily-cell__badge">✓</div>
        <div class="daily-cell__day">Day {{ day.day }}</div>
        <div class="daily-cell__reward">
          <span v-if="day.gold" class="daily-cell__gold">🪙{{ day.gold }}</span>
          <span v-if="day.gems" class="daily-cell__gems">💎{{ day.gems }}</span>
        </div>
      </div>
    </div>
  </div>
</template>

<style lang="scss" src="./DailyPage.scss" scoped></style>
