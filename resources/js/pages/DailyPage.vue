<script setup>
import { ref, onMounted } from 'vue';
import api from '../api/client';

const info = ref(null);
const message = ref('');

async function load() {
  const { data } = await api.get('/daily');
  info.value = data;
}

async function claim() {
  message.value = '';
  try {
    const { data } = await api.post('/daily/claim');
    message.value = `+${data.gold}g${data.gems ? ` +${data.gems}◆` : ''} — streak ${data.streak}`;
    await load();
  } catch (e) {
    message.value = e.response?.data?.message || 'Already claimed.';
  }
}

onMounted(load);
</script>

<template>
  <div>
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px">
      <div style="font-size:28px">🎁</div>
      <h1 class="ox" style="font-size:28px;font-weight:800;margin:0">Daily</h1>
    </div>

    <div v-if="info" style="max-width:360px;background:#151517;border:1px solid rgba(255,255,255,.07);border-radius:13px;padding:24px;text-align:center">
      <div class="ox" style="font-size:32px;font-weight:800;margin-bottom:6px">{{ info.streak }}</div>
      <div style="font-size:12px;color:rgba(255,255,255,.4);margin-bottom:18px">day streak</div>
      <p v-if="message" style="font-size:13px;color:rgba(255,255,255,.6);margin-bottom:14px">{{ message }}</p>
      <button
        @click="claim"
        :disabled="!info.can_claim"
        :style="{
          padding: '11px 28px',
          borderRadius: '10px',
          border: 'none',
          background: info.can_claim ? '#e8482f' : 'rgba(255,255,255,.08)',
          color: '#fff',
          fontWeight: 700,
          cursor: info.can_claim ? 'pointer' : 'not-allowed',
        }"
      >
        {{ info.can_claim ? 'Claim today’s reward' : 'Already claimed' }}
      </button>
    </div>
  </div>
</template>
