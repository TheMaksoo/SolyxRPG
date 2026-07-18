<script setup>
import ScreenPlaceholder from '../components/ScreenPlaceholder.vue';
</script>

<template>
  <ScreenPlaceholder
    icon="👤"
    title="Profile"
    description="Public-facing character summary: class, level, power score, equipped gear, and any cosmetic flair unlocked."
    :planned="[
      'Class / level / power summary card',
      'Equipped gear showcase',
      'Backed by GET /me + /character',
    ]"
  />
</template>
