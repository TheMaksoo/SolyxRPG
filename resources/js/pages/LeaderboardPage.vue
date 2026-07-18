<script setup>
import { ref, onMounted } from 'vue';
import api from '../api/client';
import AdBanner from '../components/AdBanner.vue';

const rows = ref([]);

onMounted(async () => {
  const { data } = await api.get('/leaderboard');
  rows.value = data.leaderboard;
});
</script>

<template>
  <div>
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px">
      <div style="font-size:28px">🏆</div>
      <h1 class="ox" style="font-size:28px;font-weight:800;margin:0">Leaderboard</h1>
    </div>

    <div style="max-width:600px">
      <AdBanner variant="inline" />
    </div>

    <div style="max-width:600px;background:#151517;border:1px solid rgba(255,255,255,.07);border-radius:13px;overflow:hidden">
      <div
        v-for="row in rows"
        :key="row.character_id"
        style="display:flex;align-items:center;gap:14px;padding:12px 18px;border-bottom:1px solid rgba(255,255,255,.06)"
      >
        <span class="ox" style="width:28px;font-weight:800;color:rgba(255,255,255,.4)">#{{ row.rank }}</span>
        <span style="flex:1;font-size:13.5px" class="ox">{{ row.name }}</span>
        <span style="font-size:11.5px;color:rgba(255,255,255,.4);text-transform:capitalize">{{ row.base_class }} · Lv.{{ row.level }}</span>
        <span class="ox" style="font-weight:700;font-size:13px;color:#eab308">{{ row.power }}</span>
      </div>
      <div v-if="rows.length === 0" style="padding:20px;color:rgba(255,255,255,.35);font-size:13px">No ranked characters yet.</div>
    </div>
  </div>
</template>
