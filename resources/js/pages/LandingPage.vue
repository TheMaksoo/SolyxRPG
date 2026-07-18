<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const router = useRouter();

const mode = ref('login'); // 'login' | 'register'
const form = ref({ name: '', email: '', password: '' });
const error = ref('');
const loading = ref(false);

const providers = [
  { key: 'discord', label: 'Continue with Discord' },
  { key: 'google', label: 'Continue with Google' },
  { key: 'apple', label: 'Continue with Apple' },
];

async function submit() {
  error.value = '';
  loading.value = true;
  try {
    if (mode.value === 'register') {
      await auth.register(form.value);
      router.push('/character/create');
    } else {
      await auth.login({ email: form.value.email, password: form.value.password });
      router.push('/characters');
    }
  } catch (e) {
    error.value = e.response?.data?.message || Object.values(e.response?.data?.errors ?? {})[0]?.[0] || 'Something went wrong.';
  } finally {
    loading.value = false;
  }
}
</script>

<template>
  <div class="landing-page">
    <img src="/images/solyx-icon.png" alt="" class="landing-logo" />
    <div>
      <h1 class="ox landing-title">SOLYX</h1>
      <p class="landing-tagline">
        A browser RPG built on top of the Solyx Discord bot.
      </p>
    </div>

    <div class="landing-providers">
      <a
        v-for="p in providers"
        :key="p.key"
        :href="`/api/auth/${p.key}/redirect`"
        class="landing-provider-link"
      >
        {{ p.label }}
      </a>
    </div>

    <div class="landing-divider-row">
      <div class="landing-divider-line"></div>
      or
      <div class="landing-divider-line"></div>
    </div>

    <form @submit.prevent="submit" class="landing-form">
      <input
        v-if="mode === 'register'"
        v-model="form.name"
        placeholder="Name"
        required
        class="landing-input"
      />
      <input
        v-model="form.email"
        type="email"
        placeholder="Email"
        required
        class="landing-input"
      />
      <input
        v-model="form.password"
        type="password"
        placeholder="Password"
        required
        class="landing-input"
      />
      <p v-if="error" class="landing-error">{{ error }}</p>
      <button
        type="submit"
        :disabled="loading"
        class="landing-submit-btn"
      >
        {{ loading ? 'Please wait…' : mode === 'register' ? 'Create account' : 'Log in' }}
      </button>
    </form>

    <button
      @click="mode = mode === 'register' ? 'login' : 'register'"
      class="landing-toggle-btn"
    >
      {{ mode === 'register' ? 'Already have an account? Log in' : "New here? Create an account" }}
    </button>

    <router-link v-if="mode === 'login'" to="/forgot-password" class="landing-forgot-link">
      Forgot your password?
    </router-link>
  </div>
</template>

<style lang="scss" src="./LandingPage.scss" scoped></style>
