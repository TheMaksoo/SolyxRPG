<script setup>
import { ref, onMounted, computed } from 'vue';
import { useRouter } from 'vue-router';
import { useCharacterStore } from '../stores/character';

const router = useRouter();
const store = useCharacterStore();

const data = ref(null);
const loading = ref(true);
const busyId = ref(null);
const error = ref('');
const deleteTarget = ref(null);

const CLASS_ICON = { warrior: '⚔', mage: '✷', rogue: '🗡', ranger: '🏹' };

const STAT_META = [
  { key: 'hp', label: 'HP' },
  { key: 'atk', label: 'ATK' },
  { key: 'def', label: 'DEF' },
  { key: 'mp', label: 'MP' },
];
const STAT_FIELD = { hp: 'hp_max', atk: 'base_atk', def: 'base_def', mp: 'mana_max' };

// Bars are scaled relative to the highest value among your own characters,
// so they're meaningful even as stats grow with level/gear.
const maxStats = computed(() => {
  const characters = (data.value?.slots ?? []).map((s) => s.character).filter(Boolean);
  const max = {};
  for (const meta of STAT_META) {
    const field = STAT_FIELD[meta.key];
    max[meta.key] = Math.max(1, ...characters.map((c) => c[field] ?? 0));
  }
  return max;
});

function statsFor(character) {
  return STAT_META.map((meta) => {
    const value = character[STAT_FIELD[meta.key]] ?? 0;
    return { ...meta, value, pct: Math.round((value / maxStats.value[meta.key]) * 100) };
  });
}

const nextGemTier = computed(() => (data.value ? data.value.bonus_character_slots + 1 : null));

async function load() {
  loading.value = true;
  try {
    data.value = await store.fetchSlots();
  } finally {
    loading.value = false;
  }
}

async function play(character) {
  error.value = '';
  busyId.value = character.id;
  try {
    await store.select(character.id);
    router.push('/dashboard');
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not switch character.';
  } finally {
    busyId.value = null;
  }
}

function requestDelete(character) {
  if (busyId.value) return;
  deleteTarget.value = character;
}

function cancelDelete() {
  if (busyId.value && String(busyId.value).startsWith('delete:')) return;
  deleteTarget.value = null;
}

async function removeCharacter() {
  error.value = '';
  const character = deleteTarget.value;
  if (!character) return;

  busyId.value = `delete:${character.id}`;
  try {
    const result = await store.remove(character.id);
    await load();

    if (result.active_character_id) {
      const activeSlot = data.value?.slots?.find((slot) => slot.character?.id === result.active_character_id);
      if (activeSlot?.character) {
        await store.select(activeSlot.character.id);
      }
    }
    deleteTarget.value = null;
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not delete character.';
  } finally {
    busyId.value = null;
  }
}

async function unlock() {
  error.value = '';
  const payer = data.value.slots.map((s) => s.character).find(Boolean);
  if (!payer) { error.value = 'Create a character first.'; return; }
  busyId.value = 'unlock';
  try {
    await store.unlockSlot(payer.id);
    await load();
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not unlock slot.';
  } finally {
    busyId.value = null;
  }
}

onMounted(load);
</script>

<template>
  <div class="character-select-page">
    <div class="character-select-page__header">
      <h1 class="ox character-select-page__title">Your Characters</h1>
      <p class="character-select-page__subtitle">
        Choose a character to play, or open a new slot.
      </p>
    </div>

    <p v-if="error" class="character-select-page__error">{{ error }}</p>

    <div v-if="loading" class="character-select-page__loading">Loading…</div>

    <div v-else class="character-grid">
      <div
        v-for="slot in data.slots"
        :key="slot.number"
        class="slot-card"
      >
        <!-- filled -->
        <template v-if="slot.character">
          <div>
            <div class="slot-card__top">
              <div class="slot-card__icon-name">
                <div class="slot-card__class-icon">{{ CLASS_ICON[slot.character.base_class] }}</div>
                <div>
                  <div class="ox slot-card__name">{{ slot.character.name }}</div>
                  <div class="slot-card__meta">
                    Lv.{{ slot.character.level }} {{ slot.character.base_class }}
                  </div>
                </div>
              </div>
              <span
                v-if="slot.character.id === data.active_character_id"
                class="slot-card__active-badge"
                >ACTIVE</span
              >
            </div>
            <div class="slot-card__stats">
              <div v-for="stat in statsFor(slot.character)" :key="stat.key" class="stat-row">
                <span class="stat-row__label" :class="`stat-row__label--${stat.key}`">{{ stat.label }}</span>
                <span class="stat-bar-track">
                  <span class="stat-bar-fill" :class="`stat-bar-fill--${stat.key}`" :style="{ width: stat.pct + '%' }"></span>
                </span>
                <span class="stat-row__value">{{ stat.value }}</span>
              </div>
              <span class="stat-chip stat-chip--luck">🍀 Luck {{ slot.character.luck ?? 0 }}</span>
            </div>
            <div class="slot-card__skill">
              <span v-if="slot.character.first_skill">
                <span class="slot-card__skill-glyph">{{ slot.character.first_skill.glyph }}</span>
                First Skill: <span class="slot-card__skill-name">{{ slot.character.first_skill.name }}</span>
              </span>
              <span v-else class="slot-card__skill--none">First Skill: none yet</span>
            </div>
          </div>
          <button
            @click="play(slot.character)"
            :disabled="busyId === slot.character.id || busyId === `delete:${slot.character.id}`"
            class="slot-card__play-btn"
          >
            {{ busyId === slot.character.id ? 'Loading…' : slot.character.id === data.active_character_id ? 'Continue' : 'Play' }}
          </button>
          <button
            @click="requestDelete(slot.character)"
            :disabled="busyId === slot.character.id || busyId === `delete:${slot.character.id}`"
            class="slot-card__delete-btn"
          >
            {{ busyId === `delete:${slot.character.id}` ? 'Deleting…' : 'Delete' }}
          </button>
        </template>

        <!-- unlocked, empty -->
        <template v-else-if="slot.unlocked">
          <router-link
            to="/character/create"
            class="slot-card__create-link"
          >
            <div class="slot-card__create-icon">+</div>
            <div class="slot-card__create-label">Create Character</div>
          </router-link>
        </template>

        <!-- locked: gems -->
        <template v-else-if="slot.requirement.type === 'gems'">
          <div class="slot-card__locked">
            <div class="slot-card__locked-icon">🔒</div>
            <div class="slot-card__locked-label">Slot {{ slot.number }}</div>
            <div class="slot-card__locked-cost">{{ slot.requirement.cost }}◆ gems</div>
          </div>
          <button
            v-if="slot.requirement.tier === nextGemTier"
            @click="unlock"
            :disabled="busyId === 'unlock'"
            class="slot-card__secondary-btn"
          >
            {{ busyId === 'unlock' ? 'Unlocking…' : `Unlock for ${slot.requirement.cost}◆` }}
          </button>
          <div v-else class="slot-card__unlock-hint">
            Unlock earlier slots first
          </div>
        </template>

        <!-- locked: vip -->
        <template v-else>
          <div class="slot-card__locked">
            <div class="slot-card__locked-icon">🔒</div>
            <div class="slot-card__locked-label">Slot {{ slot.number }}</div>
            <div class="slot-card__locked-vip">{{ slot.requirement.tier }} VIP</div>
          </div>
          <router-link
            to="/vip"
            class="slot-card__secondary-btn"
          >
            View VIP
          </router-link>
        </template>
      </div>
    </div>

    <div
      v-if="deleteTarget"
      class="delete-modal-overlay"
      @click.self="cancelDelete"
    >
      <div class="delete-modal">
        <div class="ox delete-modal__title">Delete Character</div>
        <p class="delete-modal__text">
          You are about to delete
          <strong class="delete-modal__name">{{ deleteTarget.name }}</strong>.
          This action cannot be undone.
        </p>
        <div class="delete-modal__actions">
          <button
            @click="cancelDelete"
            :disabled="busyId === `delete:${deleteTarget.id}`"
            class="delete-modal__cancel-btn"
          >
            Cancel
          </button>
          <button
            @click="removeCharacter"
            :disabled="busyId === `delete:${deleteTarget.id}`"
            class="delete-modal__delete-btn"
          >
            {{ busyId === `delete:${deleteTarget.id}` ? 'Deleting…' : 'Delete Character' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style lang="scss" src="./CharacterSelectPage.scss" scoped></style>
