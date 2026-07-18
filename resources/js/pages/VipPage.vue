<script setup>
import ScreenPlaceholder from '../components/ScreenPlaceholder.vue';
</script>

<template>
  <ScreenPlaceholder
    icon="👑"
    title="VIP"
    description="Bronze / Gold / Diamond subscription tiers via Stripe Billing, each with perks and automatic ad removal."
    :planned="[
      'Tier comparison (bronze / gold / diamond)',
      'GET /vip, POST /vip/subscribe {tier}',
      'users.vip_tier + vip_expires_at, managed by Stripe subscription webhooks',
    ]"
  />
</template>
