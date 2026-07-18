<script setup>
import ScreenPlaceholder from '../components/ScreenPlaceholder.vue';
</script>

<template>
  <ScreenPlaceholder
    icon="🛡"
    title="Guild"
    description="Roster, ranks (member / officer / master), and realtime chat. Guilds level up and grant passive perks to members."
    :planned="[
      'Member roster with role management',
      'Realtime chat via Laravel Reverb (guild_messages)',
      'GET /guild, POST /guild/{id}/join, POST /guild/{id}/message',
    ]"
  />
</template>
