<script setup>
defineProps({
  icon: { type: String, required: true },
  title: { type: String, required: true },
  description: { type: String, required: true },
  planned: { type: Array, default: () => [] },
});
</script>

<template>
  <div>
    <div class="screen-placeholder__header">
      <div class="screen-placeholder__icon">{{ icon }}</div>
      <h1 class="ox screen-placeholder__title">{{ title }}</h1>
      <span class="screen-placeholder__badge">Not yet implemented</span>
    </div>
    <p class="screen-placeholder__description">
      {{ description }}
    </p>
    <div v-if="planned.length" class="screen-placeholder__planned">
      <div class="ox screen-placeholder__planned-title">
        Planned for this screen
      </div>
      <ul class="screen-placeholder__list">
        <li v-for="item in planned" :key="item" class="screen-placeholder__list-item">
          {{ item }}
        </li>
      </ul>
    </div>
  </div>
</template>

<style lang="scss" src="./ScreenPlaceholder.scss" scoped></style>
