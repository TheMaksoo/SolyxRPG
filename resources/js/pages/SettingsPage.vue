<script setup>
import ScreenPlaceholder from '../components/ScreenPlaceholder.vue';
</script>

<template>
  <ScreenPlaceholder
    icon="⚙"
    title="Settings"
    description="Linked login providers (Discord, Google, Apple) and account preferences. Linking Discord enables cross-save with the Solyx Discord bot."
    :planned="[
      'Linked accounts list with add/remove per provider',
      'Discord link enables bot cross-save via shared discord_id',
      'Preference toggles (notifications, etc.)',
    ]"
  />
</template>
