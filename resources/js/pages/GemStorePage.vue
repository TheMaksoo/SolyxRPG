<script setup>
import ScreenPlaceholder from '../components/ScreenPlaceholder.vue';
</script>

<template>
  <ScreenPlaceholder
    icon="💎"
    title="Gem Store"
    description="Buy gem packs via Stripe Checkout. Gems are cosmetic/convenience only per the no-pay-to-win rule — never combat stats."
    :planned="[
      'Gem pack tiers (gems_340 through gems_8000)',
      'GET /store/gems, POST /store/checkout — redirects to Stripe',
      'Crediting happens only from the verified /store/webhook event',
    ]"
  />
</template>
