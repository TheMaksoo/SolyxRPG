<script setup>
import ScreenPlaceholder from '../components/ScreenPlaceholder.vue';
</script>

<template>
  <ScreenPlaceholder
    icon="🎫"
    title="Battle Pass"
    description="Seasonal tiered rewards (Ashfall Season, currently live) with a free track and a premium track unlocked via purchase."
    :planned="[
      'Tier progression bar with free vs premium rewards',
      'GET /battlepass, POST /battlepass/unlock',
      'battle_passes.tier / xp / premium fields',
    ]"
  />
</template>
