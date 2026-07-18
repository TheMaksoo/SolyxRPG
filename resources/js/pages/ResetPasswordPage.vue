<script setup>
import { ref, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api, { ensureCsrfCookie } from '../api/client';

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
  <div
    style="min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:22px;padding:24px;text-align:center"
  >
    <img src="/images/solyx-icon.png" alt="" style="width:56px;height:56px" />
    <h1 class="ox" style="font-size:26px;font-weight:800;margin:0">Choose a new password</h1>

    <form v-if="!done" @submit.prevent="submit" style="display:flex;flex-direction:column;gap:10px;width:280px">
      <input
        v-model="email"
        type="email"
        placeholder="Email"
        required
        style="padding:10px 14px;border-radius:10px;border:1px solid rgba(255,255,255,.12);background:#151517;color:#fff;font-size:14px"
      />
      <input
        v-model="password"
        type="password"
        placeholder="New password"
        required
        style="padding:10px 14px;border-radius:10px;border:1px solid rgba(255,255,255,.12);background:#151517;color:#fff;font-size:14px"
      />
      <input
        v-model="passwordConfirmation"
        type="password"
        placeholder="Confirm new password"
        required
        style="padding:10px 14px;border-radius:10px;border:1px solid rgba(255,255,255,.12);background:#151517;color:#fff;font-size:14px"
      />
      <p v-if="error" style="color:#ff6a4d;font-size:12.5px;margin:0">{{ error }}</p>
      <button
        type="submit"
        :disabled="loading"
        style="padding:11px 16px;border-radius:10px;border:none;background:#e8482f;color:#fff;font-size:14px;font-weight:700;cursor:pointer"
      >
        {{ loading ? 'Resetting…' : 'Reset password' }}
      </button>
    </form>

    <p v-else style="color:#4ade80;font-size:14px;max-width:300px">{{ message }} Redirecting to login…</p>
  </div>
</template>
