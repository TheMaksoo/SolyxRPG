<script setup>
import { ref, onMounted } from 'vue';
import api from '../api/client';

const stats = ref(null);

onMounted(async () => {
  try {
    const { data } = await api.get('/stats/public');
    stats.value = data;
  } catch {
    // Homepage still works fine without the live stat row.
  }
});

const FEATURES = [
  { icon: '✦', title: 'Classes & Skills', text: 'Forge a class and master a build with a deep, evolving skill tree.' },
  { icon: '🏰', title: 'Dungeons', text: 'Scout dangerous dungeons solo or with a party — the first opens at level 15.' },
  { icon: '🔨', title: 'Crafting & Gathering', text: 'Gather raw materials and craft gear, potions, and consumables.' },
  { icon: '🛡', title: 'Guilds & Parties', text: 'Team up with friends, found a guild, and tackle content together.' },
  { icon: '⚔', title: 'PvP Arena', text: 'Test your build against other players in the arena.' },
  { icon: '🐾', title: 'Companions', text: 'Adopt and raise companions to fight alongside you.' },
  { icon: '🏪', title: 'Marketplace', text: 'Trade gear and materials with other adventurers.' },
  { icon: '🎫', title: 'Daily Rewards & Battle Pass', text: 'Log in daily and climb the battle pass for exclusive rewards.' },
];
</script>

<template>
  <div class="home-page">
    <div class="home-topbar">
      <router-link to="/" class="home-brand">
        <img src="/images/solyx-icon.png" alt="" class="home-brand__icon" />
        <span class="ox home-brand__name">SOLYX</span>
        <span class="home-brand__tag">RPG</span>
        <span class="home-brand__tag">Beta version!</span>
      </router-link>
      <div class="home-topbar__actions">
        <div v-if="stats" class="home-online">
          <span class="home-online__dot"></span>
          {{ stats.players_online }} online
        </div>
        <router-link to="/landing" class="home-login-link">Log in</router-link>
        <router-link :to="{ path: '/landing', query: { mode: 'register' } }" class="home-play-btn">
          Play Free
        </router-link>
      </div>
    </div>

    <div class="home-hero">
      <img
        src="/images/solyx-logo.png"
        alt="Solyx"
        class="home-hero__logo"
        width="170"
        height="155"
        fetchpriority="high"
      />
      <h1 class="ox home-hero__title">Solyx <span class="home-hero__title-accent">Web Game</span></h1>
      <p class="home-hero__tagline">
        A free browser RPG built on the Solyx Discord bot. Forge a class, battle monsters, raid dungeons
        with your guild, and climb the world leaderboard — all from your browser, no download required.
      </p>
      <div class="home-hero__ctas">
        <router-link :to="{ path: '/landing', query: { mode: 'register' } }" class="home-cta-btn home-cta-btn--primary">
          Play Free →
        </router-link>
        <router-link to="/landing" class="home-cta-btn home-cta-btn--secondary">Log in</router-link>
      </div>
      <div v-if="stats" class="home-stats">
        <div class="home-stat">
          <div class="ox home-stat__value">{{ stats.adventurers }}</div>
          <div class="home-stat__label">Adventurers</div>
        </div>
        <div class="home-stat">
          <div class="ox home-stat__value">{{ stats.zones_dungeons }}</div>
          <div class="home-stat__label">Zones &amp; dungeons</div>
        </div>
        <div class="home-stat">
          <div class="ox home-stat__value">{{ stats.classes }}</div>
          <div class="home-stat__label">Playable classes</div>
        </div>
        <div class="home-stat">
          <div class="ox home-stat__value">{{ stats.quests }}</div>
          <div class="home-stat__label">Total quests</div>
        </div>
        <div class="home-stat">
          <div class="ox home-stat__value">{{ stats.items }}</div>
          <div class="home-stat__label">Total items</div>
        </div>
      </div>
    </div>

    <div class="home-section">
      <h2 class="ox home-section__title">Everything an adventurer needs</h2>
      <div class="home-features">
        <div v-for="f in FEATURES" :key="f.title" class="home-feature-card">
          <div class="home-feature-card__icon">{{ f.icon }}</div>
          <div class="home-feature-card__title">{{ f.title }}</div>
          <div class="home-feature-card__text">{{ f.text }}</div>
        </div>
      </div>
    </div>

    <div class="home-section home-section--community">
      <h2 class="ox home-section__title">Built in the open</h2>
      <p class="home-section__lead">
        Solyx is in active beta — new content ships regularly and the community shapes what comes next.
      </p>
      <div class="home-community-links">
        <a href="https://discord.gg/WhCmHm3KaS" target="_blank" rel="noopener" class="home-community-link">
          💬 Join the Discord
        </a>
        <router-link to="/changelog" class="home-community-link">📜 See the Changelog</router-link>
        <router-link to="/known-bugs" class="home-community-link">🐞 Known Bugs</router-link>
        <router-link to="/wiki" class="home-community-link">📖 Wiki</router-link>
      </div>
    </div>

    <div class="home-final-cta">
      <h2 class="ox home-final-cta__title">Ready to start your adventure?</h2>
      <router-link :to="{ path: '/landing', query: { mode: 'register' } }" class="home-cta-btn home-cta-btn--primary">
        Create your free account →
      </router-link>
    </div>

    <div class="home-footer">
      Solyx RPG · Built on the Solyx Discord bot ·
      <router-link to="/terms">Terms of Service</router-link> ·
      <router-link to="/privacy">Privacy Policy</router-link>
    </div>
  </div>
</template>

<style lang="scss" src="./HomePage.scss" scoped></style>
