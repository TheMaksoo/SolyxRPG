<script setup>
import { ref, computed } from 'vue';
import { useRouter } from 'vue-router';
import api from '../api/client';
import { useCharacterStore } from '../stores/character';

const router = useRouter();
const store = useCharacterStore();
const step = ref(0);
const dismissing = ref(false);

const STEPS = [
  {
    glyph: '🗺',
    title: 'Welcome to Solyx',
    body: "You've created your character — now let's get you oriented. This is a quick tour of the core loop: fight, quest, gather, craft, and show off what you've earned. Takes about a minute.",
  },
  {
    glyph: '⚔',
    title: 'Battle',
    body: 'Head to the World Map, pick a zone, and fight monsters for gold, XP, and gems. Away from your keyboard? Buy an Auto-Attack pass and it fights for you.',
    cta: { label: 'Open World Map', path: '/world-map' },
  },
  {
    glyph: '❖',
    title: 'Quests',
    body: 'Daily, weekly, main-story, and raid quests reward gold, XP, and gems for things you were probably doing anyway. Check in often — dailies and weeklies reset on a timer.',
    cta: { label: 'Open Quests', path: '/quests' },
  },
  {
    glyph: '⛏',
    title: 'Gathering & Crafting',
    body: 'Mine ore, chop wood, forage herbs, then smelt and craft them into gear at the Crafting bench. Better tools speed up gathering — better recipes need higher gathering levels.',
    cta: { label: 'Open Gathering', path: '/trade-skills' },
  },
  {
    glyph: '◉',
    title: 'Shop & Gem Store',
    body: 'The Shop sells gear and consumables for gold. The Gem Store is where premium currency goes — cosmetics, Auto-Attack/Auto-Gather passes, the Battle Pass, and more. Gems never affect combat stats.',
    cta: { label: 'Open Shop', path: '/shop' },
  },
  {
    glyph: '👤',
    title: 'Profile — Achievements & Customization',
    body: 'Track your achievements, watch your lifetime quest count climb, and unlock titles, name colors, banners, and profile icons. Most titles are earned free by completing the matching quest — a few prestige ones cost gems.',
    cta: { label: 'Open Profile', path: '/profile' },
  },
];

const isLast = computed(() => step.value === STEPS.length - 1);
const current = computed(() => STEPS[step.value]);

function next() {
  if (isLast.value) {
    finish();
  } else {
    step.value++;
  }
}

function back() {
  if (step.value > 0) step.value--;
}

async function finish() {
  if (dismissing.value) return;
  dismissing.value = true;
  try {
    await api.post('/character/tutorial/dismiss');
    if (store.character) store.character.tutorial_seen = true;
  } finally {
    dismissing.value = false;
  }
}

// Navigating via a step's CTA must NOT end the tour — the overlay lives outside
// <router-view/> in GameLayout, so it stays mounted and keeps its step across
// the route change; only "Skip tour" / "Get Started" actually dismiss it.
function go(path) {
  router.push(path);
}
</script>

<template>
  <div class="tutorial-overlay">
    <div class="tutorial-card">
      <button class="tutorial-card__skip" @click="finish">Skip tour ✕</button>

      <div class="tutorial-card__glyph">{{ current.glyph }}</div>
      <h2 class="ox tutorial-card__title">{{ current.title }}</h2>
      <p class="tutorial-card__body">{{ current.body }}</p>

      <div class="tutorial-card__dots">
        <span
          v-for="(s, i) in STEPS"
          :key="i"
          class="tutorial-card__dot"
          :class="{ 'is-active': i === step }"
        ></span>
      </div>

      <div class="tutorial-card__actions">
        <button v-if="step > 0" class="tutorial-card__back" @click="back">Back</button>
        <button v-if="current.cta" class="tutorial-card__cta" @click="go(current.cta.path)">{{ current.cta.label }}</button>
        <button class="tutorial-card__next" @click="next">{{ isLast ? 'Get Started' : 'Next' }}</button>
      </div>
    </div>
  </div>
</template>

<style lang="scss" src="./TutorialOverlay.scss" scoped></style>
