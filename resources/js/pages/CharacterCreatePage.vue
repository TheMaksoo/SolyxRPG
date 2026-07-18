<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { useCharacterStore } from '../stores/character';

const store = useCharacterStore();
const router = useRouter();

const classes = [
  { key: 'warrior', icon: '⚔', name: 'Warrior', blurb: 'High HP and defense. Tank.' },
  { key: 'mage', icon: '✷', name: 'Mage', blurb: 'Fragile burst caster.' },
  { key: 'rogue', icon: '🗡', name: 'Rogue', blurb: 'Fast, evasive, crit-focused.' },
  { key: 'ranger', icon: '🏹', name: 'Ranger', blurb: 'Precise ranged DPS.' },
];

const selected = ref(null);
const name = ref('');
const error = ref('');
const loading = ref(false);

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
  <div
    style="min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:24px;gap:22px"
  >
    <h1 class="ox" style="font-size:26px;font-weight:800;margin:0">Choose your class</h1>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;max-width:640px;width:100%">
      <button
        v-for="c in classes"
        :key="c.key"
        type="button"
        @click="selected = c.key"
        :style="{
          background: selected === c.key ? 'rgba(232,72,47,.13)' : '#151517',
          border: `1px solid ${selected === c.key ? '#e8482f' : 'rgba(255,255,255,.08)'}`,
          borderRadius: '13px',
          padding: '20px 14px',
          color: '#fff',
          cursor: 'pointer',
          textAlign: 'center',
        }"
      >
        <div style="font-size:30px;margin-bottom:8px">{{ c.icon }}</div>
        <div class="ox" style="font-weight:700;font-size:15px;margin-bottom:4px">{{ c.name }}</div>
        <div style="font-size:11.5px;color:rgba(255,255,255,.5)">{{ c.blurb }}</div>
      </button>
    </div>

    <input
      v-model="name"
      placeholder="Character name"
      maxlength="30"
      style="width:280px;max-width:100%;padding:11px 14px;border-radius:10px;border:1px solid rgba(255,255,255,.12);background:#151517;color:#fff;font-size:14px;text-align:center"
    />

    <p v-if="error" style="color:#ff6a4d;font-size:12.5px;margin:0">{{ error }}</p>

    <button
      @click="submit"
      :disabled="loading"
      style="padding:12px 28px;border-radius:10px;border:none;background:#e8482f;color:#fff;font-size:14px;font-weight:700;cursor:pointer"
    >
      {{ loading ? 'Creating…' : 'Begin your journey' }}
    </button>
  </div>
</template>
