<script setup>
import ScreenPlaceholder from '../components/ScreenPlaceholder.vue';
</script>

<template>
  <ScreenPlaceholder
    icon="🎁"
    title="Daily"
    description="A daily login reward with a streak counter — missing a day resets the streak, encouraging consistent play."
    :planned="[
      'Streak tracker (daily_claims.streak, last_claim_date)',
      'Reward preview for the next 7 days',
      'GET /daily, POST /daily/claim',
    ]"
  />
</template>
