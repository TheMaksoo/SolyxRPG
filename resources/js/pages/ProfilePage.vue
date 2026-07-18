<script setup>
import { ref, onMounted } from 'vue';
import { useCharacterStore } from '../stores/character';
import api from '../api/client';

const store = useCharacterStore();
const achievements = ref([]);

async function loadAchievements() {
  const { data } = await api.get('/achievements');
  achievements.value = data.achievements;
}

onMounted(() => {
  if (!store.character) store.fetch();
  loadAchievements();
});
</script>

<template>
  <div v-if="store.character">
    <div
      style="background:linear-gradient(150deg,#1a1013,#151517);border:1px solid rgba(255,255,255,.08);border-radius:16px;padding:26px;margin-bottom:20px;display:flex;gap:20px;flex-wrap:wrap;align-items:center"
    >
      <div class="ox" style="width:80px;height:80px;border-radius:18px;background:#e8482f;display:grid;place-items:center;font-size:30px;font-weight:800">
        {{ store.character.name.slice(0, 2).toUpperCase() }}
      </div>
      <div style="flex:1;min-width:200px">
        <div class="ox" style="font-size:26px;font-weight:800">{{ store.character.name }}</div>
        <div style="color:#e8482f;font-weight:600;text-transform:capitalize">
          {{ store.character.spec_class || store.character.base_class }} · Level {{ store.character.level }}
        </div>
        <div style="font-size:13px;color:rgba(255,255,255,.45);margin-top:4px">
          {{ store.character.battles_won }} battles won · {{ store.character.bosses_slain }} bosses slain
        </div>
      </div>
      <div v-if="store.stats" style="display:flex;gap:20px">
        <div style="text-align:center">
          <div class="ox" style="font-size:22px;font-weight:800;color:#eab308">{{ store.stats.power }}</div>
          <div style="font-size:11px;color:rgba(255,255,255,.4)">Power</div>
        </div>
        <div style="text-align:center">
          <div class="ox" style="font-size:22px;font-weight:800;color:#a78bfa">{{ store.character.gold }}g</div>
          <div style="font-size:11px;color:rgba(255,255,255,.4)">Gold</div>
        </div>
      </div>
    </div>

    <div style="font-size:12px;letter-spacing:.15em;color:rgba(255,255,255,.4);font-weight:600;margin-bottom:12px">ACHIEVEMENTS</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px">
      <div
        v-for="row in achievements"
        :key="row.achievement.id"
        :style="{
          background: '#151517',
          border: `1px solid ${row.earned ? 'rgba(234,179,8,.35)' : 'rgba(255,255,255,.07)'}`,
          borderRadius: '12px',
          padding: '16px',
          textAlign: 'center',
          opacity: row.earned ? 1 : 0.45,
        }"
      >
        <div style="font-size:30px;margin-bottom:8px">{{ row.achievement.glyph }}</div>
        <div style="font-weight:700;font-size:13px">{{ row.achievement.name }}</div>
        <div style="font-size:11px;color:rgba(255,255,255,.45);margin-top:3px">{{ row.achievement.description }}</div>
      </div>
    </div>
  </div>
</template>
