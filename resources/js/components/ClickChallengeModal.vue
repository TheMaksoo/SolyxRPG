<script setup>
import { ref, watch } from 'vue';

// A short "click it a few times" breather used by Gathering/Crafting while a character is under the
// minigame level cap (see GmConfig's minigame_level_cap) — the point isn't difficulty, it's pacing: a
// low-intensity beat between fights, distinct from the instant click-and-queue/timer flow those pages
// fall back to once the cap is passed.
const props = defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, default: 'Gather' },
  glyph: { type: String, default: '🌿' },
  noun: { type: String, default: 'it' },
  clicksRequired: { type: Number, default: 3 },
});

const emit = defineEmits(['complete', 'cancel']);

const clicks = ref(0);
const shake = ref(false);
let shakeTimer = null;

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) clicks.value = 0;
  }
);

function registerClick() {
  if (clicks.value >= props.clicksRequired) return;
  clicks.value += 1;
  shake.value = true;
  clearTimeout(shakeTimer);
  shakeTimer = setTimeout(() => { shake.value = false; }, 140);
  if (clicks.value >= props.clicksRequired) {
    emit('complete');
  }
}
</script>

<template>
  <div v-if="open" class="click-challenge-overlay" @click.self="emit('cancel')">
    <div class="click-challenge">
      <div class="ox click-challenge__title">{{ title }}</div>
      <button
        type="button"
        class="click-challenge__target"
        :class="{ 'is-shaking': shake }"
        @click="registerClick"
      >
        <span class="click-challenge__glyph">{{ glyph }}</span>
      </button>
      <div class="click-challenge__pips">
        <span
          v-for="i in clicksRequired"
          :key="i"
          class="click-challenge__pip"
          :class="{ 'is-filled': i <= clicks }"
        ></span>
      </div>
      <p class="click-challenge__hint">Click {{ noun }} {{ clicksRequired - clicks }} more time{{ clicksRequired - clicks === 1 ? '' : 's' }}.</p>
      <button type="button" class="click-challenge__skip" @click="emit('cancel')">Cancel</button>
    </div>
  </div>
</template>

<style lang="scss" src="./ClickChallengeModal.scss" scoped></style>
