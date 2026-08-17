<script setup>
defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, required: true },
  message: { type: String, default: '' },
  confirmLabel: { type: String, default: 'Confirm' },
  cancelLabel: { type: String, default: 'Cancel' },
  danger: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
});

const emit = defineEmits(['confirm', 'cancel']);
</script>

<template>
  <div v-if="open" class="confirm-modal-overlay" @click.self="emit('cancel')">
    <div class="confirm-modal">
      <h3 class="ox confirm-modal__title">{{ title }}</h3>
      <p v-if="message" class="confirm-modal__message">{{ message }}</p>
      <slot />
      <div class="confirm-modal__actions">
        <button type="button" class="confirm-modal__btn confirm-modal__btn--cancel" :disabled="busy" @click="emit('cancel')">{{ cancelLabel }}</button>
        <button type="button" class="confirm-modal__btn" :class="{ 'confirm-modal__btn--danger': danger }" :disabled="busy" @click="emit('confirm')">
          {{ busy ? 'Working…' : confirmLabel }}
        </button>
      </div>
    </div>
  </div>
</template>

<style lang="scss" scoped>
@use '../../scss/variables' as v;
@use '../../scss/mixins' as m;

.confirm-modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.6);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
  z-index: 300;
}

.confirm-modal {
  @include m.card(22px);
  max-width: 380px;
  width: 100%;
}

.confirm-modal__title {
  font-size: 17px;
  margin-bottom: 8px;
}

.confirm-modal__message {
  font-size: 12.5px;
  color: v.$text-muted-55;
  line-height: 1.5;
  margin-bottom: 18px;
}

.confirm-modal__actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 18px;
}

.confirm-modal__btn {
  @include m.btn-reset;
  padding: 9px 16px;
  border-radius: v.$radius-sm;
  font-size: 12.5px;
  font-weight: 700;
  background: v.$accent;
  color: #fff;

  &:disabled {
    opacity: 0.6;
    cursor: default;
  }

  &--cancel {
    background: transparent;
    border: 1px solid rgba(255, 255, 255, 0.14);
    color: v.$text-muted-55;
  }

  &--danger {
    background: v.$danger;
  }
}
</style>
