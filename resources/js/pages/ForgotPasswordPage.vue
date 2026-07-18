<script setup>
import { ref } from 'vue';
import api, { ensureCsrfCookie } from '../api/client';

const email = ref('');
const message = ref('');
const error = ref('');
const loading = ref(false);

async function submit() {
  message.value = '';
  error.value = '';
  loading.value = true;
  try {
    await ensureCsrfCookie();
    const { data } = await api.post('/auth/forgot-password', { email: email.value });
    message.value = data.message;
  } catch (e) {
    error.value = e.response?.data?.message || 'Something went wrong.';
  } finally {
    loading.value = false;
  }
}
</script>

<template>
  <div class="forgot-password-page">
    <img src="/images/solyx-icon.png" alt="" class="forgot-password-page__logo" />
    <div>
      <h1 class="ox forgot-password-page__title">Reset your password</h1>
      <p class="forgot-password-page__subtitle">
        Enter your account email and we'll send a link to reset your password.
      </p>
    </div>

    <form @submit.prevent="submit" class="forgot-password-page__form">
      <input
        v-model="email"
        type="email"
        placeholder="Email"
        required
        class="forgot-password-page__input"
      />
      <p v-if="message" class="forgot-password-page__message">{{ message }}</p>
      <p v-if="error" class="forgot-password-page__error">{{ error }}</p>
      <button type="submit" :disabled="loading" class="forgot-password-page__submit">
        {{ loading ? 'Sending…' : 'Send reset link' }}
      </button>
    </form>

    <router-link to="/landing" class="forgot-password-page__back">Back to login</router-link>
  </div>
</template>

<style lang="scss" src="./ForgotPasswordPage.scss" scoped></style>
