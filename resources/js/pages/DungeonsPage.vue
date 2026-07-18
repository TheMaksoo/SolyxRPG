<script setup>
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import api from '../api/client';
import AdBanner from '../components/AdBanner.vue';

const router = useRouter();
const dungeons = ref([]);
const error = ref('');

const DIFF = {
  normal: { color: '#4ade80', bg: 'rgba(74,222,128,.13)' },
  hard: { color: '#eab308', bg: 'rgba(234,179,8,.13)' },
  raid: { color: '#ff8163', bg: 'rgba(255,129,99,.13)' },
  mythic: { color: '#a78bfa', bg: 'rgba(167,139,250,.13)' },
};

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

    <p style="color:rgba(255,255,255,.5);margin:0 0 18px">Boss raids with dedicated drop tables. Higher difficulty, better rewards.</p>
    <p v-if="error" style="color:#ff6a4d;font-size:13px;margin-bottom:14px">{{ error }}</p>

    <AdBanner variant="inline" />

    <div style="display:flex;flex-direction:column;gap:14px;max-width:900px">
      <div
        v-for="row in dungeons"
        :key="row.dungeon.id"
        style="background:#151517;border:1px solid rgba(255,255,255,.07);border-radius:14px;padding:18px;display:flex;gap:18px;flex-wrap:wrap;align-items:center"
      >
        <div
          style="width:120px;height:90px;flex:none;border-radius:11px;background:repeating-linear-gradient(45deg,#1c1114,#1c1114 9px,#170e11 9px,#170e11 18px);display:grid;place-items:center;font-size:36px"
        >
          {{ row.dungeon.glyph }}
        </div>
        <div style="flex:1;min-width:200px">
          <div style="display:flex;align-items:center;gap:10px">
            <div class="ox" style="font-weight:700;font-size:17px">{{ row.dungeon.name }}</div>
            <span
              :style="{ fontSize: '11px', padding: '3px 9px', borderRadius: '6px', background: DIFF[row.dungeon.difficulty]?.bg, color: DIFF[row.dungeon.difficulty]?.color, fontWeight: 600, textTransform: 'capitalize' }"
              >{{ row.dungeon.difficulty }}</span
            >
          </div>
          <div style="font-size:13px;color:rgba(255,255,255,.5);margin:5px 0 8px">
            Boss: {{ row.dungeon.boss_monster?.name ?? 'Unknown' }}
            <span v-if="row.dungeon.party_size > 1"> · Party of {{ row.dungeon.party_size }}</span>
          </div>
          <div v-if="row.dungeon.drops_json" style="font-size:12px;color:#eab308">
            Drops:
            <span v-if="row.dungeon.drops_json.gold">{{ row.dungeon.drops_json.gold }}g</span>
            <span v-if="row.dungeon.drops_json.gems"> · {{ row.dungeon.drops_json.gems }}◆</span>
          </div>
        </div>
        <button
          @click="enter(row)"
          :disabled="!row.unlocked"
          :style="{
            padding: '11px 22px',
            borderRadius: '10px',
            border: 'none',
            background: row.unlocked ? '#e8482f' : 'rgba(255,255,255,.08)',
            color: '#fff',
            fontWeight: 700,
            fontSize: '13px',
            cursor: row.unlocked ? 'pointer' : 'not-allowed',
          }"
        >
          {{ row.unlocked ? 'Enter' : `Requires Lv.${row.dungeon.min_level}` }}
        </button>
      </div>
    </div>
  </div>
</template>
