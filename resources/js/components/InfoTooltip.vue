<script setup>
defineProps({
  text: { type: String, required: true },
});
</script>

<template>
  <span class="info-tooltip" tabindex="0" role="button" :aria-label="text">
    <span class="info-tooltip__icon">i</span>
    <span class="info-tooltip__bubble" role="tooltip">{{ text }}</span>
  </span>
</template>

<style lang="scss" scoped>
@use '../../scss/variables' as v;

.info-tooltip {
  position: relative;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  margin-left: 5px;
  cursor: help;
  outline: none;
}

.info-tooltip__icon {
  width: 14px;
  height: 14px;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.1);
  color: v.$text-muted-55;
  font-size: 9.5px;
  font-weight: 700;
  font-style: italic;
  font-family: Georgia, serif;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  line-height: 1;
}

.info-tooltip__bubble {
  position: absolute;
  bottom: calc(100% + 8px);
  left: 50%;
  transform: translateX(-50%) translateY(4px);
  width: 220px;
  padding: 9px 11px;
  border-radius: v.$radius-sm;
  background: #14171c;
  border: 1px solid rgba(255, 255, 255, 0.12);
  color: v.$text-muted-70;
  font-size: 11.5px;
  font-weight: 400;
  line-height: 1.45;
  text-transform: none;
  letter-spacing: normal;
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4);
  opacity: 0;
  visibility: hidden;
  transition: opacity 0.12s ease, transform 0.12s ease;
  z-index: 40;
  pointer-events: none;
}

.info-tooltip:hover .info-tooltip__bubble,
.info-tooltip:focus .info-tooltip__bubble,
.info-tooltip:focus-within .info-tooltip__bubble {
  opacity: 1;
  visibility: visible;
  transform: translateX(-50%) translateY(0);
}

@media (max-width: 480px) {
  .info-tooltip__bubble {
    width: 170px;
  }
}
</style>
