<script setup>
import { onMounted } from 'vue';
import { useCharacterStore } from '../stores/character';

const store = useCharacterStore();
onMounted(() => {
  if (!store.character) store.fetch();
});
</script>

<template>
  <div v-if="store.character">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px">
      <div style="font-size:28px">👤</div>
      <h1 class="ox" style="font-size:28px;font-weight:800;margin:0">{{ store.character.name }}</h1>
    </div>

    <div style="max-width:420px;background:#151517;border:1px solid rgba(255,255,255,.07);border-radius:13px;padding:22px">
      <div style="display:flex;justify-content:space-between;margin-bottom:10px">
        <span style="color:rgba(255,255,255,.4);font-size:13px">Class</span>
        <span style="font-size:13px;text-transform:capitalize">{{ store.character.spec_class || store.character.base_class }}</span>
      </div>
      <div style="display:flex;justify-content:space-between;margin-bottom:10px">
        <span style="color:rgba(255,255,255,.4);font-size:13px">Level</span>
        <span style="font-size:13px">{{ store.character.level }}</span>
      </div>
      <div v-if="store.stats" style="display:flex;justify-content:space-between;margin-bottom:10px">
        <span style="color:rgba(255,255,255,.4);font-size:13px">Power</span>
        <span style="font-size:13px;color:#eab308;font-weight:700">{{ store.stats.power }}</span>
      </div>
      <div style="display:flex;justify-content:space-between">
        <span style="color:rgba(255,255,255,.4);font-size:13px">Gold / Gems</span>
        <span style="font-size:13px">{{ store.character.gold }}g · {{ store.character.gems }}◆</span>
      </div>
    </div>
  </div>
</template>
