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
  <div
    style="min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:22px;padding:24px;text-align:center"
  >
    <img src="/images/solyx-icon.png" alt="" style="width:56px;height:56px" />
    <div>
      <h1 class="ox" style="font-size:26px;font-weight:800;margin:0 0 8px">Reset your password</h1>
      <p style="color:rgba(255,255,255,.55);font-size:13.5px;margin:0;max-width:320px">
        Enter your account email and we'll send a link to reset your password.
      </p>
    </div>

    <form @submit.prevent="submit" style="display:flex;flex-direction:column;gap:10px;width:280px">
      <input
        v-model="email"
        type="email"
        placeholder="Email"
        required
        style="padding:10px 14px;border-radius:10px;border:1px solid rgba(255,255,255,.12);background:#151517;color:#fff;font-size:14px"
      />
      <p v-if="message" style="color:#4ade80;font-size:12.5px;margin:0">{{ message }}</p>
      <p v-if="error" style="color:#ff6a4d;font-size:12.5px;margin:0">{{ error }}</p>
      <button
        type="submit"
        :disabled="loading"
        style="padding:11px 16px;border-radius:10px;border:none;background:#e8482f;color:#fff;font-size:14px;font-weight:700;cursor:pointer"
      >
        {{ loading ? 'Sending…' : 'Send reset link' }}
      </button>
    </form>

    <router-link to="/landing" style="font-size:12.5px;color:rgba(255,255,255,.5)">Back to login</router-link>
  </div>
</template>
