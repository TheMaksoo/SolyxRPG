<script setup>
import { ref, computed } from 'vue';

defineOptions({ inheritAttrs: false });

const props = defineProps({
  modelValue: { type: String, default: '' },
  // [{ id, name }] — whoever should be offered as an @mention suggestion for this chat.
  candidates: { type: Array, default: () => [] },
});
const emit = defineEmits(['update:modelValue', 'enter']);

const inputEl = ref(null);
const activeIndex = ref(0);

// The @word currently being typed, if the caret sits right after one — recomputed on every
// keystroke since selectionStart moves with the caret.
const trigger = computed(() => {
  const el = inputEl.value;
  const pos = el ? el.selectionStart ?? props.modelValue.length : props.modelValue.length;
  const before = props.modelValue.slice(0, pos);
  const match = before.match(/(?:^|\s)@([A-Za-z0-9_]*)$/);
  return match ? { partial: match[1], start: pos - match[1].length - 1 } : null;
});

const suggestions = computed(() => {
  if (!trigger.value) return [];
  const partial = trigger.value.partial.toLowerCase();
  return props.candidates.filter((c) => c.name.toLowerCase().startsWith(partial)).slice(0, 6);
});

function onInput(e) {
  emit('update:modelValue', e.target.value);
  activeIndex.value = 0;
}

function pick(candidate) {
  const t = trigger.value;
  if (!t) return;
  const before = props.modelValue.slice(0, t.start);
  const after = props.modelValue.slice(t.start + 1 + t.partial.length);
  emit('update:modelValue', `${before}@${candidate.name} ${after}`);
  activeIndex.value = 0;
  inputEl.value?.focus();
}

function onKeydown(e) {
  if (suggestions.value.length) {
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      activeIndex.value = (activeIndex.value + 1) % suggestions.value.length;
      return;
    }
    if (e.key === 'ArrowUp') {
      e.preventDefault();
      activeIndex.value = (activeIndex.value - 1 + suggestions.value.length) % suggestions.value.length;
      return;
    }
    if (e.key === 'Enter' || e.key === 'Tab') {
      e.preventDefault();
      pick(suggestions.value[activeIndex.value]);
      return;
    }
    if (e.key === 'Escape') {
      activeIndex.value = 0;
      return;
    }
  }
  if (e.key === 'Enter') emit('enter');
}
</script>

<template>
  <div class="mention-input">
    <input
      ref="inputEl"
      v-bind="$attrs"
      :value="modelValue"
      @input="onInput"
      @keydown="onKeydown"
    />
    <div v-if="suggestions.length" class="mention-input__suggestions">
      <button
        v-for="(s, i) in suggestions"
        :key="s.id"
        type="button"
        class="mention-input__suggestion"
        :class="{ 'is-active': i === activeIndex }"
        @mousedown.prevent="pick(s)"
      >
        @{{ s.name }}
      </button>
    </div>
  </div>
</template>

<style lang="scss" scoped>
.mention-input {
  position: relative;
  flex: 1;
  min-width: 0;

  input {
    width: 100%;
    box-sizing: border-box;
  }
}

.mention-input__suggestions {
  position: absolute;
  bottom: 100%;
  left: 0;
  margin-bottom: 4px;
  background: #1b1b1f;
  border: 1px solid rgba(255, 255, 255, 0.12);
  border-radius: 8px;
  overflow: hidden;
  z-index: 20;
  min-width: 140px;
  box-shadow: 0 6px 18px rgba(0, 0, 0, 0.35);
}

.mention-input__suggestion {
  display: block;
  width: 100%;
  text-align: left;
  padding: 7px 12px;
  background: none;
  border: none;
  color: rgba(255, 255, 255, 0.85);
  font-size: 12.5px;
  cursor: pointer;

  &.is-active,
  &:hover {
    background: rgba(232, 72, 47, 0.16);
  }
}
</style>
