<script setup>
import ScreenPlaceholder from '../components/ScreenPlaceholder.vue';
</script>

<template>
  <ScreenPlaceholder
    icon="🗺"
    title="World Map"
    description="All six zones from Whispering Meadows up to The Void, with danger tier, level requirement, and lock state. Selecting a zone travels the character there and updates the Battle enemy pool."
    :planned="[
      'Zone cards with danger tier + level gate (see the Wiki Zones tab for the content)',
      'Locked-zone indicator until the level requirement is met',
      'GET /zones, POST /zones/{zone}/travel',
    ]"
  />
</template>
