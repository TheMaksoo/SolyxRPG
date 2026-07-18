<script setup>
import ScreenPlaceholder from '../components/ScreenPlaceholder.vue';
</script>

<template>
  <ScreenPlaceholder
    icon="🎒"
    title="Inventory"
    description="Equip slots (weapon, armor, pet) plus the full item bag, with quantities for stackable consumables and materials."
    :planned="[
      'Equip slots bound to inventories.equipped',
      'Bag grid grouped by item type',
      'GET /inventory, POST /inventory/equip',
    ]"
  />
</template>
