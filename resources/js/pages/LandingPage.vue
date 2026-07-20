<script setup>
import { ref, onMounted, nextTick, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import api from '../api/client';

const auth = useAuthStore();
const router = useRouter();

const mode = ref('login'); // 'login' | 'register'
const form = ref({ name: '', email: '', password: '' });
const error = ref('');
const loading = ref(false);
const stats = ref(null);

const TURNSTILE_SITE_KEY = import.meta.env.VITE_TURNSTILE_SITE_KEY || '';
const turnstileToken = ref('');
const turnstileEl = ref(null);
let turnstileWidgetId = null;

function renderTurnstile() {
  if (!TURNSTILE_SITE_KEY || !turnstileEl.value || turnstileWidgetId !== null) return;
  if (!window.turnstile) {
    setTimeout(renderTurnstile, 200);
    return;
  }
  turnstileWidgetId = window.turnstile.render(turnstileEl.value, {
    sitekey: TURNSTILE_SITE_KEY,
    callback: (token) => { turnstileToken.value = token; },
    'expired-callback': () => { turnstileToken.value = ''; },
  });
}

function resetTurnstile() {
  turnstileToken.value = '';
  if (window.turnstile && turnstileWidgetId !== null) {
    window.turnstile.reset(turnstileWidgetId);
  }
}

const providers = [
  { key: 'discord', label: 'Continue with Discord', class: 'landing-oauth-btn--discord' },
  { key: 'google', label: 'Continue with Google', class: 'landing-oauth-btn--google' },
  { key: 'apple', label: 'Continue with Apple', class: 'landing-oauth-btn--apple' },
];

async function submit() {
  error.value = '';
  loading.value = true;
  try {
    if (mode.value === 'register') {
      await auth.register({ ...form.value, cf_turnstile_response: turnstileToken.value });
      router.push('/character/create');
    } else {
      await auth.login({ email: form.value.email, password: form.value.password });
      router.push('/characters');
    }
  } catch (e) {
    error.value = e.response?.data?.message || Object.values(e.response?.data?.errors ?? {})[0]?.[0] || 'Something went wrong.';
    if (mode.value === 'register') resetTurnstile();
  } finally {
    loading.value = false;
  }
}

watch(mode, (value) => {
  if (value === 'register') nextTick(renderTurnstile);
});

onMounted(async () => {
  if (mode.value === 'register') nextTick(renderTurnstile);
  try {
    const { data } = await api.get('/stats/public');
    stats.value = data;
  } catch {
    // Landing page still works fine without the live stat row.
  }
});
</script>

<template>
  <div class="landing-page">
    <div class="landing-topbar">
      <div class="landing-brand">
        <img src="/images/solyx-icon.png" alt="" class="landing-brand__icon" />
        <span class="ox landing-brand__name">SOLYX</span>
        <span class="landing-brand__tag">RPG</span>
        <span class="landing-brand__tag">Beta version!</span>
      </div>
      <div v-if="stats" class="landing-online">
        <span class="landing-online__dot"></span>
        {{ stats.players_online }} online
      </div>
    </div>

    <div class="landing-hero">
      <div class="landing-hero__pitch">
        <img src="/images/solyx-logo.png" alt="Solyx" class="landing-hero__logo" />
        <h1 class="ox landing-hero__title">Solyx <span class="landing-hero__title-accent">Web Game</span></h1>
        <h6>Beta version!</h6>
        <p class="landing-hero__tagline">
          A browser RPG built on the Solyx Discord bot. Forge a class, battle monsters, raid dungeons with your
          guild, and climb the world leaderboard.
        </p>
        <div v-if="stats" class="landing-stats">
          <div class="landing-stat">
            <div class="ox landing-stat__value">{{ stats.adventurers }}</div>
            <div class="landing-stat__label">Adventurers</div>
          </div>
          <div class="landing-stat">
            <div class="ox landing-stat__value">{{ stats.zones_dungeons }}</div>
            <div class="landing-stat__label">Zones &amp; dungeons</div>
          </div>
          <div class="landing-stat">
            <div class="ox landing-stat__value">{{ stats.classes }}</div>
            <div class="landing-stat__label">Playable classes</div>
          </div>
        </div>
      </div>

      <div class="landing-auth-card">
        <div class="landing-auth-card__header">
          <img src="/images/solyx-logo.png" alt="Solyx" class="landing-auth-card__logo" />
          <div class="ox landing-auth-card__title">{{ mode === 'register' ? 'Create your account' : 'Enter Solyx' }}</div>
          <div class="landing-auth-card__subtitle">Log in or create your account</div>
        </div>

        <div class="landing-oauth-list">
          <a
            v-for="p in providers"
            :key="p.key"
            :href="`/api/auth/${p.key}/redirect`"
            class="landing-oauth-btn"
            :class="p.class"
          >
            {{ p.label }}
          </a>
        </div>

        <div class="landing-auth-card__divider">
          <div class="landing-auth-card__divider-line"></div>
          or email
          <div class="landing-auth-card__divider-line"></div>
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
          <div
            v-if="mode === 'register' && TURNSTILE_SITE_KEY"
            ref="turnstileEl"
            class="landing-turnstile"
          ></div>
          <p v-if="error" class="landing-error">{{ error }}</p>
          <button
            type="submit"
            :disabled="loading"
            class="landing-submit-btn"
          >
            {{ loading ? 'Please wait…' : mode === 'register' ? 'Create account' : 'Enter the realm →' }}
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
    </div>

    <div class="landing-footer">Solyx RPG · Built on the Solyx Discord bot</div>
  </div>
</template>

<style lang="scss" src="./LandingPage.scss" scoped></style>
