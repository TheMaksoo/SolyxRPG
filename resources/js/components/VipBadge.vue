<script setup>
defineProps({
  tier: { type: String, default: 'none' },
});
</script>

<template>
  <span v-if="tier && tier !== 'none'" class="vip-badge" :class="`vip-badge--${tier}`">{{ tier }}</span>
</template>

<style lang="scss" src="./VipBadge.scss" scoped></style>
