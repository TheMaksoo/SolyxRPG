<script setup>
import ScreenPlaceholder from '../components/ScreenPlaceholder.vue';
</script>

<template>
  <ScreenPlaceholder
    icon="🛒"
    title="Shop"
    description="Buy weapons, armor, consumables and cosmetics with gold or gems. Same item catalog that powers the Wiki, but purchasable and stock-aware."
    :planned="[
      'Tabs: Weapons / Armor / Consumables / Cosmetics',
      'Gold and gem price display per item',
      'GET /shop?tab=, POST /shop/buy — server validates funds + grants to inventory',
    ]"
  />
</template>
