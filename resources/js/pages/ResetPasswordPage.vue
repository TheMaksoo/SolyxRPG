<script setup>
import { ref, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api, { ensureCsrfCookie } from '../api/client';
import Toast from '../components/Toast.vue';

const route = useRoute();
const router = useRouter();

const token = ref('');
const email = ref('');
const password = ref('');
const passwordConfirmation = ref('');
const message = ref('');
const error = ref('');
const loading = ref(false);
const done = ref(false);

let messageToastTimer = null;
watch(message, (val) => {
  clearTimeout(messageToastTimer);
  if (val) messageToastTimer = setTimeout(() => { message.value = ''; }, 4000);
});
let errorToastTimer = null;
watch(error, (val) => {
  clearTimeout(errorToastTimer);
  if (val) errorToastTimer = setTimeout(() => { error.value = ''; }, 5000);
});

onMounted(() => {
  token.value = route.query.token || '';
  email.value = route.query.email || '';
});

async function submit() {
  message.value = '';
  error.value = '';
  loading.value = true;
  try {
    await ensureCsrfCookie();
    const { data } = await api.post('/auth/reset-password', {
      token: token.value,
      email: email.value,
      password: password.value,
      password_confirmation: passwordConfirmation.value,
    });
    message.value = data.message;
    done.value = true;
    setTimeout(() => router.push('/landing'), 2000);
  } catch (e) {
    error.value = e.response?.data?.message || Object.values(e.response?.data?.errors ?? {})[0]?.[0] || 'Reset failed.';
  } finally {
    loading.value = false;
  }
}
</script>

<template>
  <div class="reset-page">
    <img src="/images/solyx-icon.png" alt="" class="reset-page__logo" />
    <h1 class="ox reset-page__title">Choose a new password</h1>

    <Toast :message="error" type="error" />
    <Toast :message="message" type="success" />

    <form v-if="!done" @submit.prevent="submit" class="reset-form">
      <input
        v-model="email"
        type="email"
        placeholder="Email"
        autocomplete="email"
        required
        class="reset-form__input"
      />
      <input
        v-model="password"
        type="password"
        placeholder="New password"
        autocomplete="new-password"
        required
        class="reset-form__input"
      />
      <input
        v-model="passwordConfirmation"
        type="password"
        placeholder="Confirm new password"
        autocomplete="new-password"
        required
        class="reset-form__input"
      />
      <button
        type="submit"
        :disabled="loading"
        class="reset-form__submit"
      >
        {{ loading ? 'Resetting…' : 'Reset password' }}
      </button>
    </form>

    <p v-else class="reset-page__success">Redirecting to login…</p>
  </div>
</template>

<style lang="scss" src="./ResetPasswordPage.scss" scoped></style>
