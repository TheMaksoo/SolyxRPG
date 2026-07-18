<script setup>
import ScreenPlaceholder from '../components/ScreenPlaceholder.vue';
</script>

<template>
  <ScreenPlaceholder
    icon="🐾"
    title="Companions"
    description="Unlock and activate companions that grant passive combat bonuses (attack, crit, XP, defense) — one active at a time."
    :planned="[
      'Owned vs unlockable companion grid',
      'Active companion toggle (character_pets.active)',
      'GET /pets, POST /pets/{id}/activate, POST /pets/{id}/unlock',
    ]"
  />
</template>
