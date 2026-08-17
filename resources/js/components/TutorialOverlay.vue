<script setup>
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import api from '../api/client';
import { useCharacterStore } from '../stores/character';

const router = useRouter();
const store = useCharacterStore();

// Each tip appears once the character's level reaches minLevel — shown one at a time, in order,
// instead of the old fixed 8-slide tour that dumped every system on a level-1 character at once
// (several of whose CTAs pointed at pages gated well above level 1). Mounted only on BattlePage, so
// this never follows the player to other screens either.
const STEPS = [
  {
    minLevel: 0,
    glyph: '🗺',
    title: 'Welcome to the Meadows',
    body: "This is your training ground — fight here until level 3, then real zone combat begins. The monsters here mix it up, so no two fights feel quite the same.",
  },
  {
    minLevel: 0,
    glyph: '⚔',
    title: 'How to fight',
    body: 'Attack for a basic swing, or spend MP on a skill for something stronger. Keep an eye on your MP bar — skills go on cooldown after you use them.',
  },
  {
    minLevel: 2,
    glyph: '✦',
    title: "You've leveled up!",
    body: 'Leveling up gives you attribute and skill points to spend yourself — nothing is spent automatically. Head to the Skills page to put them to use.',
    cta: { label: 'Open Skills', path: '/skills' },
  },
  {
    minLevel: 3,
    glyph: '🎒',
    title: 'Check your gear',
    body: "Level 3 — the Inventory just unlocked. Everything you've looted so far from tutorial fights is waiting there, ready to equip.",
    cta: { label: 'Open Inventory', path: '/inventory' },
  },
  {
    minLevel: 4,
    glyph: '🛒',
    title: 'The Shop is open',
    body: "Level 4 — buy potions and basic gear, or sell off loot you don't need.",
    cta: { label: 'Open Shop', path: '/shop' },
  },
  {
    minLevel: 5,
    glyph: '📦',
    title: 'Crates unlocked',
    body: "Level 5 — start unlocking loot crates for a shot at gear you haven't earned from a drop yet.",
    cta: { label: 'Open Crates', path: '/crates' },
  },
  {
    minLevel: 6,
    glyph: '📜',
    title: 'Quests are open',
    body: 'Level 6 — pick up quests for a steady stream of extra gold, XP, and gear on top of what battles give you.',
    cta: { label: 'Open Quests', path: '/quests' },
  },
  {
    minLevel: 7,
    glyph: '⛏',
    title: 'Time to gather',
    body: 'Level 7 — Gathering is open. When you need a breather between fights, gather raw materials out in the world instead.',
    cta: { label: 'Open Gathering', path: '/trade-skills' },
  },
  {
    minLevel: 8,
    glyph: '🔨',
    title: 'Put those materials to use',
    body: 'Level 8 — Crafting just unlocked. Turn what you gathered into gear of your own instead of just buying it from the Shop.',
    cta: { label: 'Open Crafting', path: '/crafting' },
  },
];

// Progress is tracked server-side (character.tutorial_step), not in localStorage — a localStorage key
// read at setup time used to race against the character still loading (read under the wrong key before
// the real id was known, then every dismiss wrote somewhere that read could never see again, so the tour
// looked like it reset on every single visit to Battle). tutorial_step arrives with every other character
// field, so there's nothing client-side left to get out of sync.
const lastSeenIndex = computed(() => store.character?.tutorial_step ?? -1);

// The most advanced step the character currently qualifies for that they haven't dismissed yet.
const activeStep = computed(() => {
  const level = store.character?.level ?? 1;
  let candidate = -1;
  STEPS.forEach((s, i) => {
    if (level >= s.minLevel && i > lastSeenIndex.value) candidate = i;
  });
  return candidate;
});

const current = computed(() => (activeStep.value >= 0 ? STEPS[activeStep.value] : null));
const visible = computed(() => store.character && !store.character.tutorial_seen && current.value);

// Mutates store.character only AFTER the server confirms — mutating it first (the old behavior) meant
// a failed request (network blip, expired session) left the client ahead of what was actually saved,
// with no error surfaced and no way to tell the tip just wouldn't come back. Wrapped in try/catch so a
// failure here simply leaves tutorial_step at its last-confirmed value instead of throwing an unhandled
// rejection into go()'s caller.
async function dismiss() {
  if (activeStep.value < 0) return;
  const step = activeStep.value;

  try {
    if (step === STEPS.length - 1) {
      const { data } = await api.post('/character/tutorial/dismiss');
      if (store.character) {
        store.character.tutorial_step = step;
        store.character.tutorial_seen = data.character.tutorial_seen;
      }
    } else {
      await api.post('/character/tutorial/advance', { step });
      if (store.character) store.character.tutorial_step = step;
    }
  } catch {
    // Server never recorded the advance/dismiss — leave tutorial_step where it was so the tip can
    // still reappear, rather than pretending the save succeeded.
  }
}

async function go(path) {
  await dismiss();
  router.push(path);
}

// Skips the whole remaining sequence, same server call the old "Skip tour" button made.
async function skipAll() {
  try {
    const { data } = await api.post('/character/tutorial/dismiss');
    if (store.character) {
      store.character.tutorial_step = STEPS.length - 1;
      store.character.tutorial_seen = data.character.tutorial_seen;
    }
  } catch {
    // Same reasoning as dismiss() above.
  }
}

</script>

<template>
  <div v-if="visible" class="tutorial-overlay">
    <div class="tutorial-card">
      <button type="button" class="tutorial-card__skip" @click="skipAll">Skip ✕</button>

      <div class="tutorial-card__glyph">{{ current.glyph }}</div>
      <div class="tutorial-card__progress">Tip {{ activeStep + 1 }} of {{ STEPS.length }}</div>
      <h2 class="ox tutorial-card__title">{{ current.title }}</h2>
      <p class="tutorial-card__body">{{ current.body }}</p>

      <div class="tutorial-card__actions">
        <button v-if="current.cta" type="button" class="tutorial-card__cta" @click="go(current.cta.path)">{{ current.cta.label }}</button>
        <button type="button" class="tutorial-card__next" @click="dismiss">Got it</button>
      </div>
    </div>
  </div>
</template>

<style lang="scss" src="./TutorialOverlay.scss" scoped></style>
