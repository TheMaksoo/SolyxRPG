<script setup>
import ScreenPlaceholder from '../components/ScreenPlaceholder.vue';
</script>

<template>
  <ScreenPlaceholder
    icon="🏠"
    title="Dashboard"
    description="The character's home screen: current zone card, HP/MP/XP stat bars, quick actions (battle, travel, shop), equipped gear, and side rails for guild/daily/events."
    :planned="[
      'Zone card showing current location + travel shortcut',
      'HP / MP / XP stat bars driven by GET /me + /character',
      'Equipped gear summary (weapon, armor, pet)',
      'Quick actions: Battle, World Map, Shop, Daily claim',
    ]"
  />
</template>
