<script setup>
import ScreenPlaceholder from '../components/ScreenPlaceholder.vue';
</script>

<template>
  <ScreenPlaceholder
    icon="🏰"
    title="Dungeons"
    description="Wave-based instances (Wolf Den, Haunted Crypt, Dragon Lair, The Void Throne) culminating in a boss fight, with dedicated drop tables."
    :planned="[
      'Difficulty tiers: Normal / Hard / Raid / Mythic',
      'Party size requirement for raid-tier dungeons',
      'GET dungeons list, POST /dungeons/{id}/enter',
    ]"
  />
</template>
