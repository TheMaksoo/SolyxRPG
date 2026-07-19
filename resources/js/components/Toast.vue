<script setup>
defineProps({
  message: { type: String, default: '' },
  type: { type: String, default: 'success' },
});
</script>

<template>
  <Teleport to="body">
    <Transition name="toast-fade">
      <div v-if="message" class="app-toast" :class="`app-toast--${type}`">{{ message }}</div>
    </Transition>
  </Teleport>
</template>

<style scoped lang="scss">
.app-toast {
  position: fixed;
  top: 18px;
  left: 50%;
  transform: translateX(-50%);
  z-index: 999;
  max-width: min(90vw, 420px);
  padding: 11px 20px;
  border-radius: 10px;
  font-size: 13px;
  font-weight: 600;
  text-align: center;
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.35);
  backdrop-filter: blur(6px);

  &--success {
    background: rgba(22, 163, 74, 0.92);
    color: #fff;
  }

  &--error {
    background: rgba(220, 38, 38, 0.92);
    color: #fff;
  }
}

.toast-fade-enter-active,
.toast-fade-leave-active {
  transition: opacity 0.2s ease, transform 0.2s ease;
}

.toast-fade-enter-from,
.toast-fade-leave-to {
  opacity: 0;
  transform: translateX(-50%) translateY(-8px);
}
</style>
