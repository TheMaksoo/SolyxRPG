<script setup>
import { ref, onMounted, nextTick, watch } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import api from '../api/client';

const auth = useAuthStore();
const router = useRouter();
const route = useRoute();

const OAUTH_ERROR_MESSAGES = {
  cancelled: 'Sign-in was cancelled.',
  failed: 'Could not sign in with that provider. Please try again.',
};

const mode = ref('login'); // 'login' | 'register'
const form = ref({ name: '', email: '', password: '' });
const tosAccepted = ref(false);
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
];

async function submit() {
  error.value = '';
  loading.value = true;
  try {
    if (mode.value === 'register') {
      if (!tosAccepted.value) {
        error.value = 'Please accept the Terms of Service to continue.';
        loading.value = false;
        return;
      }
      await auth.register({
        ...form.value,
        cf_turnstile_response: turnstileToken.value,
        tos_accepted: tosAccepted.value,
      });
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

  if (route.query.oauth_error) {
    error.value = OAUTH_ERROR_MESSAGES[route.query.oauth_error] || 'Sign-in failed.';
    router.replace({ query: {} });
  }

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
            <svg
              v-if="p.key === 'discord'"
              class="landing-oauth-btn__icon"
              viewBox="0 0 24 24"
              fill="currentColor"
              aria-hidden="true"
            ><path d="M20.317 4.3698a19.7913 19.7913 0 0 0-4.8851-1.5152.0741.0741 0 0 0-.0785.0371c-.211.3753-.4447.8648-.6083 1.2495-1.8447-.2762-3.68-.2762-5.4868 0-.1636-.3933-.4058-.8742-.6177-1.2495a.077.077 0 0 0-.0785-.037 19.7363 19.7363 0 0 0-4.8852 1.515.0699.0699 0 0 0-.0321.0277C.5334 9.0458-.319 13.5799.0992 18.0578a.0824.0824 0 0 0 .0312.0561c1.9422 1.4287 3.8248 2.2966 5.6722 2.8848a.0777.0777 0 0 0 .0842-.0276c.4368-.5978.8266-1.2288 1.1613-1.8917a.076.076 0 0 0-.0416-.1057c-.6184-.2345-1.2065-.5206-1.7716-.8489a.0771.0771 0 0 1-.0076-.1278c.1189-.0892.2377-.1817.351-.2755a.0743.0743 0 0 1 .0776-.0105c3.9278 1.7933 8.18 1.7933 12.0614 0a.0739.0739 0 0 1 .0785.0095c.1134.0938.2321.1873.3511.2765a.0772.0772 0 0 1-.0067.1278 12.2986 12.2986 0 0 1-1.7727.8479.0766.0766 0 0 0-.0407.1067c.3436.6619.7334 1.2929 1.1602 1.8907a.077.077 0 0 0 .0842.0286c1.8523-.5872 3.7349-1.4561 5.6772-2.8848a.077.077 0 0 0 .0312-.0552C23.5878 12.3239 22.1913 8.0038 20.3178 4.3708a.061.061 0 0 0-.0308-.0296z"/></svg>
            <svg
              v-else-if="p.key === 'google'"
              class="landing-oauth-btn__icon"
              viewBox="0 0 18 18"
              aria-hidden="true"
            >
              <path fill="#4285F4" d="M17.64 9.2045c0-.6381-.0573-1.2518-.1636-1.8409H9v3.4814h4.8436c-.2086 1.125-.8427 2.0782-1.7959 2.7164v2.2582h2.9087c1.7018-1.5668 2.6836-3.8741 2.6836-6.6151z"/>
              <path fill="#34A853" d="M9 18c2.43 0 4.4673-.806 5.9564-2.1805l-2.9087-2.2582c-.7855.5262-1.7818.8364-3.0477.8364-2.3427 0-4.3282-1.5818-5.0359-3.7104H.9573v2.3318C2.4382 15.9832 5.4818 18 9 18z"/>
              <path fill="#FBBC05" d="M3.9641 10.71c-.1818-.5426-.2864-1.1223-.2864-1.71s.1046-1.1673.2864-1.71V4.9582H.9573A8.9965 8.9965 0 0 0 0 9c0 1.4523.3477 2.8264.9573 4.0418L3.9641 10.71z"/>
              <path fill="#EA4335" d="M9 3.5795c1.3214 0 2.5077.4541 3.4405 1.346l2.5813-2.5814C13.4632.8918 11.426 0 9 0 5.4818 0 2.4382 2.0168.9573 4.9582L3.9641 7.29C4.6718 5.1613 6.6573 3.5795 9 3.5795z"/>
            </svg>
            {{ p.label }}
          </a>
        </div>
        <p class="landing-oauth-tos-notice">
          By continuing with Discord or Google, you agree to our
          <router-link to="/terms" target="_blank">Terms of Service &amp; Beta Disclaimer</router-link>.
        </p>

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
          <label v-if="mode === 'register'" class="landing-tos-check">
            <input v-model="tosAccepted" type="checkbox" required />
            <span>
              I agree to the
              <router-link to="/terms" target="_blank">Terms of Service &amp; Beta Disclaimer</router-link>
              — I understand Solyx is still in development and that content, values, and data can change or be lost.
            </span>
          </label>
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

    <div class="landing-footer">
      Solyx RPG · Built on the Solyx Discord bot ·
      <router-link to="/terms">Terms of Service</router-link>
    </div>
  </div>
</template>

<style lang="scss" src="./LandingPage.scss" scoped></style>
