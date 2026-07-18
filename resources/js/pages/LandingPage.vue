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
    } else {
      await auth.login({ email: form.value.email, password: form.value.password });
    }
    router.push(auth.hasCharacter ? '/dashboard' : '/character/create');
  } catch (e) {
    error.value = e.response?.data?.message || Object.values(e.response?.data?.errors ?? {})[0]?.[0] || 'Something went wrong.';
  } finally {
    loading.value = false;
  }
}
</script>

<template>
  <div
    style="min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:24px;padding:24px;text-align:center"
  >
    <img src="/images/solyx-icon.png" alt="" style="width:64px;height:64px" />
    <div>
      <h1 class="ox" style="font-size:36px;font-weight:800;margin:0 0 8px">SOLYX</h1>
      <p style="color:rgba(255,255,255,.55);font-size:14.5px;margin:0">
        A browser RPG built on top of the Solyx Discord bot.
      </p>
    </div>

    <div style="display:flex;flex-direction:column;gap:10px;width:280px">
      <a
        v-for="p in providers"
        :key="p.key"
        :href="`/api/auth/${p.key}/redirect`"
        style="padding:11px 16px;border-radius:10px;border:1px solid rgba(255,255,255,.12);background:#151517;color:#fff;font-size:14px;font-weight:600;text-align:center"
      >
        {{ p.label }}
      </a>
    </div>

    <div style="display:flex;align-items:center;gap:10px;width:280px;color:rgba(255,255,255,.3);font-size:12px">
      <div style="flex:1;height:1px;background:rgba(255,255,255,.1)"></div>
      or
      <div style="flex:1;height:1px;background:rgba(255,255,255,.1)"></div>
    </div>

    <form @submit.prevent="submit" style="display:flex;flex-direction:column;gap:10px;width:280px">
      <input
        v-if="mode === 'register'"
        v-model="form.name"
        placeholder="Name"
        required
        style="padding:10px 14px;border-radius:10px;border:1px solid rgba(255,255,255,.12);background:#151517;color:#fff;font-size:14px"
      />
      <input
        v-model="form.email"
        type="email"
        placeholder="Email"
        required
        style="padding:10px 14px;border-radius:10px;border:1px solid rgba(255,255,255,.12);background:#151517;color:#fff;font-size:14px"
      />
      <input
        v-model="form.password"
        type="password"
        placeholder="Password"
        required
        style="padding:10px 14px;border-radius:10px;border:1px solid rgba(255,255,255,.12);background:#151517;color:#fff;font-size:14px"
      />
      <p v-if="error" style="color:#ff6a4d;font-size:12.5px;margin:0">{{ error }}</p>
      <button
        type="submit"
        :disabled="loading"
        style="padding:11px 16px;border-radius:10px;border:none;background:#e8482f;color:#fff;font-size:14px;font-weight:700;cursor:pointer"
      >
        {{ loading ? 'Please wait…' : mode === 'register' ? 'Create account' : 'Log in' }}
      </button>
    </form>

    <button
      @click="mode = mode === 'register' ? 'login' : 'register'"
      style="background:none;border:none;color:rgba(255,255,255,.5);font-size:12.5px;cursor:pointer"
    >
      {{ mode === 'register' ? 'Already have an account? Log in' : "New here? Create an account" }}
    </button>

    <router-link v-if="mode === 'login'" to="/forgot-password" style="font-size:12.5px;color:rgba(255,255,255,.4)">
      Forgot your password?
    </router-link>
  </div>
</template>
