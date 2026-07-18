<script setup>
import ScreenPlaceholder from '../components/ScreenPlaceholder.vue';
</script>

<template>
  <ScreenPlaceholder
    icon="🏆"
    title="Leaderboard"
    description="Server rankings by character power, recomputed periodically via a background job rather than on every request."
    :planned="[
      'Ranked list backed by leaderboard_cache (power, rank)',
      'Recomputed on a schedule, not live per-request',
      'GET /leaderboard?tab=',
    ]"
  />
</template>
