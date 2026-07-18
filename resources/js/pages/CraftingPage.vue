<script setup>
import ScreenPlaceholder from '../components/ScreenPlaceholder.vue';
</script>

<template>
  <ScreenPlaceholder
    icon="🔨"
    title="Crafting"
    description="Turn materials (like Iron Ore) into gear and consumables using recipes. Missing materials are highlighted before the craft is attempted."
    :planned="[
      'Recipe list with required materials + owned quantities',
      'Craft button disabled until materials are sufficient',
      'GET /crafting/recipes, POST /crafting/{recipe}/craft',
    ]"
  />
</template>
