<script setup>
import { ref } from 'vue';
import api from '../api/client';

const open = ref(false);
const subject = ref('');
const body = ref('');
const sending = ref(false);
const sent = ref(false);
const error = ref('');

function reset() {
  subject.value = '';
  body.value = '';
  sent.value = false;
  error.value = '';
}

function close() {
  open.value = false;
  reset();
}

async function submit() {
  error.value = '';
  sending.value = true;
  try {
    await api.post('/support-tickets', {
      subject: subject.value,
      body: body.value,
      category: 'bug',
      priority: 'normal',
    });
    sent.value = true;
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not send the report — try again in a moment.';
  } finally {
    sending.value = false;
  }
}

defineExpose({ open: () => { open.value = true; } });
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="bug-report-overlay" @click.self="close">
      <div class="bug-report-modal">
        <div class="bug-report-modal__header">
          <span class="ox bug-report-modal__title">🐞 Report a Bug</span>
          <button type="button" class="bug-report-modal__close" @click="close">✕</button>
        </div>

        <div v-if="sent" class="bug-report-modal__sent">
          <p>Thanks — your report is in. You can also track it under Settings → Support.</p>
          <button type="button" class="bug-report-modal__ok-btn" @click="close">Done</button>
        </div>
        <form v-else class="bug-report-modal__form" @submit.prevent="submit">
          <p class="bug-report-modal__hint">
            Found something broken? Describe what happened and what you expected instead — screenshots
            help, so mention where one lives if you have it (Discord, etc.).
          </p>
          <input
            v-model="subject"
            class="bug-report-modal__input"
            placeholder="Short summary (e.g. Skill button does nothing)"
            maxlength="120"
            required
          />
          <textarea
            v-model="body"
            class="bug-report-modal__textarea"
            placeholder="What happened, what page/action, what you expected instead..."
            maxlength="2000"
            rows="5"
            required
          ></textarea>
          <p v-if="error" class="bug-report-modal__error">{{ error }}</p>
          <button type="submit" class="bug-report-modal__submit-btn" :disabled="sending">
            {{ sending ? 'Sending…' : 'Send report' }}
          </button>
        </form>
      </div>
    </div>
  </Teleport>
</template>

<style scoped lang="scss">
@use '../../scss/variables' as v;
@use '../../scss/mixins' as m;

.bug-report-overlay {
  position: fixed;
  inset: 0;
  background: rgba(9, 9, 11, 0.72);
  display: grid;
  place-items: center;
  padding: 20px;
  z-index: 999;
}

.bug-report-modal {
  width: min(440px, 100%);
  background: v.$bg-card;
  border: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: v.$radius-xl;
  padding: 20px 18px;
}

.bug-report-modal__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
}

.bug-report-modal__title {
  font-size: 16px;
  font-weight: 800;
}

.bug-report-modal__close {
  @include m.btn-reset;
  color: v.$text-muted-40;
  font-size: 14px;

  &:hover {
    color: v.$text-primary;
  }
}

.bug-report-modal__hint {
  font-size: 12.5px;
  color: v.$text-muted-55;
  line-height: 1.5;
  margin: 0 0 12px;
}

.bug-report-modal__form {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.bug-report-modal__input,
.bug-report-modal__textarea {
  width: 100%;
  background: v.$bg-input;
  border: 1px solid v.$border-medium;
  border-radius: v.$radius-md;
  padding: 10px 12px;
  color: #fff;
  font-size: 13.5px;
  font-family: inherit;
  resize: vertical;
}

.bug-report-modal__error {
  color: v.$danger;
  font-size: 12.5px;
  margin: 0;
}

.bug-report-modal__submit-btn {
  @include m.btn-reset;
  background: v.$accent;
  color: #fff;
  border-radius: v.$radius-md;
  padding: 11px;
  font-weight: 700;
  font-size: 13.5px;

  &:disabled {
    opacity: 0.6;
  }
}

.bug-report-modal__sent {
  font-size: 13.5px;
  color: v.$text-muted-70;
  line-height: 1.5;
}

.bug-report-modal__ok-btn {
  @include m.btn-reset;
  margin-top: 12px;
  width: 100%;
  background: v.$accent;
  color: #fff;
  border-radius: v.$radius-md;
  padding: 10px;
  font-weight: 700;
  font-size: 13px;
}
</style>
