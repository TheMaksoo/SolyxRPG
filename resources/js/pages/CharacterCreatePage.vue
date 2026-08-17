<script setup>
import { computed, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useCharacterStore } from '../stores/character';
import { useAuthStore } from '../stores/auth';
import Toast from '../components/Toast.vue';

const store = useCharacterStore();
const auth = useAuthStore();
const router = useRouter();

const classes = [
  {
    key: 'warrior',
    icon: '⚔',
    name: 'Warrior',
    blurb: 'High HP and defense. Tank.',
    stats: { hp: 230, atk: 12, def: 14, mp: 90 },
    firstSkill: { glyph: '⚔', name: 'Power Strike' },
    gearNote: '🛡🥋 2 defense slots: Shield + Armor, worn together',
  },
  {
    key: 'mage',
    icon: '✷',
    name: 'Mage',
    blurb: 'Fragile burst caster.',
    stats: { hp: 155, atk: 11, def: 8, mp: 240 },
    firstSkill: { glyph: '✷', name: 'Shadow Bolt' },
    gearNote: '⚔🥋 Weapon + Armor',
  },
  {
    key: 'rogue',
    icon: '🗡',
    name: 'Rogue',
    blurb: 'Fast, evasive, crit-focused.',
    stats: { hp: 180, atk: 13, def: 10, mp: 120 },
    firstSkill: { glyph: '🗡', name: 'Precision Strikes' },
    gearNote: '🗡🔪 2 weapon slots: Weapon + Knife Holder, worn together',
  },
  {
    key: 'ranger',
    icon: '🏹',
    name: 'Ranger',
    blurb: 'Precise ranged DPS.',
    stats: { hp: 195, atk: 12, def: 11, mp: 140 },
    firstSkill: { glyph: '🎯', name: 'Focused Aim' },
    gearNote: '🏹🎯 2 weapon slots: Bow + Quiver, worn together',
  },
];

const STAT_META = [
  { key: 'hp', label: 'HP' },
  { key: 'atk', label: 'ATK' },
  { key: 'def', label: 'DEF' },
  { key: 'mp', label: 'MP' },
];

// Scale each stat's bar relative to the highest value among the classes,
// so the bars are meaningful for comparing builds rather than absolute.
const maxStats = STAT_META.reduce((max, meta) => {
  max[meta.key] = Math.max(...classes.map((c) => c.stats[meta.key]));
  return max;
}, {});

function statsFor(c) {
  return STAT_META.map((meta) => ({
    ...meta,
    value: c.stats[meta.key],
    pct: Math.round((c.stats[meta.key] / maxStats[meta.key]) * 100),
  }));
}

// A short lore/world screen before class selection — previously a brand-new player landed directly on
// the class grid with no framing for what the world even is. One-time, first-run only: skipped for
// players who already have another character (they've seen it) — mirrors router.js's own
// hasAnyCharacters check (auth.user.characters).
const stage = ref((auth.user?.characters?.length ?? 0) > 0 ? 'class' : 'welcome');

const selected = ref(null);
const name = ref('');
const error = ref('');
const loading = ref(false);
let errorToastTimer = null;
watch(error, (val) => {
  clearTimeout(errorToastTimer);
  if (val) errorToastTimer = setTimeout(() => { error.value = ''; }, 5000);
});

const canSubmit = computed(() => !!selected.value && !!name.value.trim() && !loading.value);

async function submit() {
  if (!selected.value || !name.value.trim()) {
    error.value = 'Pick a class and enter a name.';
    return;
  }
  error.value = '';
  loading.value = true;
  try {
    await store.create({ name: name.value.trim(), base_class: selected.value });
    // Straight into a guided first fight instead of the Dashboard — see BattlePage.vue's tutorial
    // overlay, which takes over from here for a level 1-2 character.
    router.push('/battle');
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not create character.';
  } finally {
    loading.value = false;
  }
}
</script>

<template>
  <div v-if="stage === 'welcome'" class="create-page">
    <div class="create-page__header">
      <h1 class="ox create-page__title">Welcome to Solyx</h1>
      <p class="create-page__subtitle">A realm on the edge of ruin needs an adventurer. That's you.</p>
    </div>

    <div class="class-card create-welcome-card">
      <p>
        The old roads out of Whispering Meadows aren't safe anymore. Something is stirring in the
        deeper zones, and the guilds are calling for anyone willing to pick up a weapon and go find out
        what.
      </p>
      <p>
        You'll pick a class, take your first few fights right here in the Meadows, and grow from
        there — new gear, skills, and whole regions open up as you go.
      </p>
    </div>

    <button type="button" @click="stage = 'class'" class="create-submit-btn create-welcome-btn">Begin your journey</button>
  </div>

  <div v-else class="create-page">
    <div class="create-page__header">
      <h1 class="ox create-page__title">Choose your class</h1>
      <p class="create-page__subtitle">Every class can master other paths later. Start with your favorite.</p>
    </div>

    <div class="class-grid">
      <button
        v-for="c in classes"
        :key="c.key"
        type="button"
        @click="selected = c.key"
        class="class-card"
        :class="{ 'class-card--selected': selected === c.key }"
      >
        <div class="class-card__check" v-if="selected === c.key">✓</div>

        <div class="class-card__top">
          <div class="class-card__icon">{{ c.icon }}</div>
          <div>
            <div class="ox class-card__name">{{ c.name }}</div>
            <div class="class-card__blurb">{{ c.blurb }}</div>
          </div>
        </div>

        <div class="class-card__stats">
          <div v-for="stat in statsFor(c)" :key="stat.key" class="stat-row">
            <span class="stat-row__label" :class="`stat-row__label--${stat.key}`">{{ stat.label }}</span>
            <span class="stat-bar-track">
              <span class="stat-bar-fill" :class="`stat-bar-fill--${stat.key}`" :style="{ width: stat.pct + '%' }"></span>
            </span>
            <span class="stat-row__value">{{ stat.value }}</span>
          </div>
        </div>

        <div class="class-card__skill">
          <span class="class-card__skill-glyph">{{ c.firstSkill.glyph }}</span>
          First Skill: <span class="class-card__skill-name">{{ c.firstSkill.name }}</span>
        </div>
        <div class="class-card__gear-note">{{ c.gearNote }}</div>
      </button>
    </div>

    <div class="create-page__form">
      <label for="character-name" class="create-name-label">Character name</label>
      <input
        id="character-name"
        v-model="name"
        placeholder="Character name"
        maxlength="30"
        class="create-name-input"
      />

      <Toast :message="error" type="error" />

      <button
        @click="submit"
        :disabled="!canSubmit"
        class="create-submit-btn"
      >
        {{ loading ? 'Creating…' : 'Begin your journey' }}
      </button>
    </div>
  </div>
</template>

<style lang="scss" src="./CharacterCreatePage.scss" scoped></style>
