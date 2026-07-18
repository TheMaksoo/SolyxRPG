<script setup>
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useCharacterStore } from '../stores/character';

const store = useCharacterStore();
const router = useRouter();

const classes = [
  {
    key: 'warrior',
    icon: '⚔',
    name: 'Warrior',
    blurb: 'High HP and defense. Tank.',
    stats: { hp: 230, atk: 12, def: 14, mp: 90 },
    firstSkill: { glyph: '⚔', name: 'Power Strike' },
  },
  {
    key: 'mage',
    icon: '✷',
    name: 'Mage',
    blurb: 'Fragile burst caster.',
    stats: { hp: 155, atk: 11, def: 8, mp: 240 },
    firstSkill: { glyph: '✷', name: 'Shadow Bolt' },
  },
  {
    key: 'rogue',
    icon: '🗡',
    name: 'Rogue',
    blurb: 'Fast, evasive, crit-focused.',
    stats: { hp: 180, atk: 13, def: 10, mp: 120 },
    firstSkill: { glyph: '🛡', name: 'Tough Skin' },
  },
  {
    key: 'ranger',
    icon: '🏹',
    name: 'Ranger',
    blurb: 'Precise ranged DPS.',
    stats: { hp: 195, atk: 12, def: 11, mp: 140 },
    firstSkill: { glyph: '🛡', name: 'Tough Skin' },
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

const selected = ref(null);
const name = ref('');
const error = ref('');
const loading = ref(false);

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
    router.push('/dashboard');
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not create character.';
  } finally {
    loading.value = false;
  }
}
</script>

<template>
  <div class="create-page">
    <div class="create-page__header">
      <h1 class="ox create-page__title">Choose your class</h1>
      <p class="create-page__subtitle">Every class can master other paths later — start with your favorite.</p>
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

      <p v-if="error" class="create-error">{{ error }}</p>

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
