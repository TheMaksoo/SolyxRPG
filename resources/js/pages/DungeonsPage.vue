<script setup>
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import api from '../api/client';

const router = useRouter();
const dungeons = ref([]);
const error = ref('');

async function load() {
  const { data } = await api.get('/dungeons');
  dungeons.value = data.dungeons;
}

async function enter(row) {
  error.value = '';
  try {
    await api.post(`/dungeons/${row.dungeon.id}/enter`);
    router.push('/battle');
  } catch (e) {
    error.value = e.response?.data?.message || 'Cannot enter yet.';
  }
}

onMounted(load);
</script>

<template>
  <div>
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px">
      <div style="font-size:28px">🏰</div>
      <h1 class="ox" style="font-size:28px;font-weight:800;margin:0">Dungeons</h1>
    </div>

    <p v-if="error" style="color:#ff6a4d;font-size:13px;margin-bottom:14px">{{ error }}</p>

    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px">
      <div
        v-for="row in dungeons"
        :key="row.dungeon.id"
        style="background:#151517;border:1px solid rgba(255,255,255,.07);border-radius:13px;padding:18px"
      >
        <div style="font-size:24px;margin-bottom:8px">{{ row.dungeon.glyph }}</div>
        <div class="ox" style="font-weight:700;font-size:15px;margin-bottom:4px">{{ row.dungeon.name }}</div>
        <div style="font-size:11.5px;color:rgba(255,255,255,.45);margin-bottom:12px;text-transform:capitalize">
          {{ row.dungeon.difficulty }} · Lv.{{ row.dungeon.min_level }}+
          <span v-if="row.dungeon.party_size > 1"> · Party {{ row.dungeon.party_size }}</span>
        </div>
        <button
          @click="enter(row)"
          :disabled="!row.unlocked"
          :style="{
            width: '100%',
            padding: '9px',
            borderRadius: '8px',
            border: 'none',
            background: row.unlocked ? '#e8482f' : 'rgba(255,255,255,.08)',
            color: '#fff',
            fontWeight: 700,
            fontSize: '12.5px',
            cursor: row.unlocked ? 'pointer' : 'not-allowed',
          }"
        >
          {{ row.unlocked ? 'Enter' : `Requires Lv.${row.dungeon.min_level}` }}
        </button>
      </div>
    </div>
  </div>
</template>
