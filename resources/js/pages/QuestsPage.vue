<script setup>
import ScreenPlaceholder from '../components/ScreenPlaceholder.vue';
</script>

<template>
  <ScreenPlaceholder
    icon="📜"
    title="Quests"
    description="Daily, weekly, main-story and raid quests, each with a progress goal and a claimable reward once complete."
    :planned="[
      'Tabs: Daily / Weekly / Main / Raid',
      'Progress bars driven by character_quests.progress',
      'Claim button once goal_json target is met',
      'GET /quests, POST /quests/{id}/claim',
    ]"
  />
</template>
