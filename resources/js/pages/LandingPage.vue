<script setup>
import { ref, onMounted, nextTick, watch } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import api from '../api/client';

const auth = useAuthStore();
const router = useRouter();
const route = useRoute();

const mode = ref('login'); // 'login' | 'register'
const form = ref({ name: '', email: '', password: '', referral_code: '' });
const showPassword = ref(false);
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
      // A returning player with an already-selected character should land straight on the dashboard,
      // not be routed through character-select every time they log in — that screen is for picking or
      // creating a slot, which only matters if there's no active character yet.
      router.push(auth.hasCharacter ? '/dashboard' : '/characters');
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
  // A ?ref= link (see ReferralController's invite_url) should land straight on the signup form with
  // the code already filled in — someone clicking an invite link is here to sign up, not log in.
  if (route.query.ref) {
    // Codes are base64url(account id) — case-sensitive, unlike the old random-uppercase scheme, so
    // don't touch the casing here.
    form.value.referral_code = String(route.query.ref);
    mode.value = 'register';
  }

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
        <img
          src="/images/solyx-logo.png"
          alt="Solyx"
          class="landing-hero__logo"
          width="150"
          height="137"
          fetchpriority="high"
        />
        <h1 class="ox landing-hero__title">Solyx <span class="landing-hero__title-accent">Web Game</span></h1>
        <h6>Beta version!</h6>
        <p class="landing-hero__tagline">
          A browser RPG built on the Solyx Discord bot. Forge a class, battle monsters, raid dungeons with your
          guild, and climb the world leaderboard.
        </p>
        <div v-if="stats" class="landing-stats">
          <div class="landing-stat">
            <div class="ox landing-stat__value">{{ stats.adventurers }}</div>
            <div class="landing-stat__label">Adventures</div>
          </div>
          <div class="landing-stat">
            <div class="ox landing-stat__value">{{ stats.zones_dungeons }}</div>
            <div class="landing-stat__label">Zones &amp; dungeons</div>
          </div>
          <div class="landing-stat">
            <div class="ox landing-stat__value">{{ stats.classes }}</div>
            <div class="landing-stat__label">Playable classes</div>
          </div>
          <div class="landing-stat">
            <div class="ox landing-stat__value">{{ stats.quests }}</div>
            <div class="landing-stat__label">Total quests</div>
          </div>
          <div class="landing-stat">
            <div class="ox landing-stat__value">{{ stats.items }}</div>
            <div class="landing-stat__label">Total items</div>
          </div>
        </div>
      </div>

      <div class="landing-auth-card">
        <div class="landing-auth-card__header">
          <img src="/images/solyx-logo.png" alt="Solyx" class="landing-auth-card__logo" width="76" height="70" />
          <div class="ox landing-auth-card__title">{{ mode === 'register' ? 'Create your account' : 'Enter Solyx' }}</div>
          <div class="landing-auth-card__subtitle">Log in or create your account</div>
        </div>

        <form @submit.prevent="submit" class="landing-form" autocomplete="on">
          <input
            v-if="mode === 'register'"
            v-model="form.name"
            id="reg-name"
            name="name"
            placeholder="Name"
            autocomplete="username"
            required
            class="landing-input"
          />
          <input
            v-model="form.email"
            id="auth-email"
            name="email"
            type="email"
            placeholder="Email"
            autocomplete="email"
            required
            class="landing-input"
          />
          <div class="landing-password-wrapper">
            <input
              v-model="form.password"
              id="auth-password"
              name="password"
              :type="showPassword ? 'text' : 'password'"
              placeholder="Password"
              :autocomplete="mode === 'register' ? 'new-password' : 'current-password'"
              required
              class="landing-input"
            />
            <button
              type="button"
              @click="showPassword = !showPassword"
              class="landing-password-toggle"
              :aria-label="showPassword ? 'Hide password' : 'Show password'"
            >
              {{ showPassword ? '👁️' : '👁️‍🗨️' }}
            </button>
          </div>
          <input
            v-if="mode === 'register'"
            v-model="form.referral_code"
            placeholder="Referral code (optional)"
            maxlength="10"
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
      <router-link to="/terms">Terms of Service</router-link> ·
      <router-link to="/privacy">Privacy Policy</router-link>
    </div>
  </div>
</template>

<style lang="scss" src="./LandingPage.scss" scoped></style>
