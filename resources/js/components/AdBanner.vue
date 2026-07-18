<script setup>
import { computed } from 'vue';
import { useAuthStore } from '../stores/auth';

const props = defineProps({
  variant: { type: String, default: 'inline' }, // 'inline' | 'sidebar'
});

const auth = useAuthStore();
const visible = computed(() => !auth.user?.ads_removed && (auth.user?.vip_tier ?? 'none') === 'none');
</script>

<template>
  <div v-if="visible && variant === 'inline'" class="ad-banner--inline">
    <div class="ad-banner__left">
      <span class="ad-banner__tag">AD</span>
      <span class="ad-banner__sponsored">Sponsored · your ad here</span>
    </div>
    <router-link to="/vip" class="ad-banner__remove">Remove ads</router-link>
  </div>

  <div v-else-if="visible && variant === 'sidebar'" class="ad-banner--sidebar">
    <div class="ad-banner__tag ad-banner__tag--sidebar">AD</div>
    <div class="ad-banner__slot">Your 160×600<br />ad here</div>
    <router-link to="/vip" class="ad-banner__remove ad-banner__remove--block">Remove ads</router-link>
  </div>
</template>

<style lang="scss" src="./AdBanner.scss" scoped></style>
