<script setup>
import ScreenPlaceholder from '../../components/ScreenPlaceholder.vue';
</script>

<template>
  <ScreenPlaceholder
    icon="🛠"
    title="GM Console"
    description="No-code content editing: CRUD over items/monsters/events/zones/quests/skills/recipes/pets, feature + tester flags, economy multipliers, player moderation, and support tickets. Everything here is Policy-guarded to gm/owner roles and writes to audit_logs."
    :planned="[
      'Overview: content stats + feature/tester toggles',
      'CRUD tables: Items, Monsters, Events (mirrors the Wiki data, but editable)',
      'Players: search, grant, ban/mute, support tickets',
      'Economy: gold_mult / xp_mult / drop_rate / gem_mult sliders',
      'Broadcast: server-wide announcements',
    ]"
  />
</template>
