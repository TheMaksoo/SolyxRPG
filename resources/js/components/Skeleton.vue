<script setup>
// Generic shimmering placeholder — swap in wherever a page currently shows a bare "Loading…" string
// while its first fetch is in flight. `variant` picks the shape, `width`/`height` are raw CSS values
// (defaults suit the variant), `count` repeats the same shape N times (e.g. a handful of list rows).
defineProps({
  variant: { type: String, default: 'block' }, // 'block' | 'text' | 'circle'
  width: { type: String, default: null },
  height: { type: String, default: null },
  count: { type: Number, default: 1 },
});
</script>

<template>
  <template v-for="i in count" :key="i">
    <span
      class="skeleton"
      :class="`skeleton--${variant}`"
      :style="{ width, height }"
    ></span>
  </template>
</template>

<style lang="scss" scoped>
@use '../../scss/variables' as v;

.skeleton {
  display: block;
  background: linear-gradient(
    100deg,
    rgba(255, 255, 255, 0.05) 30%,
    rgba(255, 255, 255, 0.12) 50%,
    rgba(255, 255, 255, 0.05) 70%
  );
  background-size: 200% 100%;
  animation: skeleton-shimmer 1.4s ease-in-out infinite;
  border-radius: v.$radius-sm;
}

.skeleton--block {
  width: 100%;
  height: 80px;
  margin-bottom: 10px;
}

.skeleton--text {
  width: 100%;
  height: 12px;
  margin-bottom: 8px;
}

.skeleton--circle {
  width: 40px;
  height: 40px;
  border-radius: 50%;
}

@keyframes skeleton-shimmer {
  0% {
    background-position: 200% 0;
  }
  100% {
    background-position: -200% 0;
  }
}

@media (prefers-reduced-motion: reduce) {
  .skeleton {
    animation: none;
    background: rgba(255, 255, 255, 0.06);
  }
}

html.reduce-motion .skeleton {
  animation: none;
  background: rgba(255, 255, 255, 0.06);
}
</style>
