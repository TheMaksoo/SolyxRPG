<script setup>
import { ref, onMounted } from 'vue';
import api from '../api/client';
import AdBanner from '../components/AdBanner.vue';

const pass = ref(null);
const gemCost = ref(0);
const message = ref('');

async function load() {
  const { data } = await api.get('/battlepass');
  pass.value = data.battle_pass;
  gemCost.value = data.premium_gem_cost;
}

async function unlock() {
  message.value = '';
  try {
    const { data } = await api.post('/battlepass/unlock');
    pass.value = data.battle_pass;
  } catch (e) {
    message.value = e.response?.data?.message || 'Not enough gems.';
  }
}

onMounted(load);
</script>

<template>
  <div>
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px">
      <div style="font-size:28px">🎫</div>
      <h1 class="ox" style="font-size:28px;font-weight:800;margin:0">Ashfall Season Battle Pass</h1>
    </div>

    <div style="max-width:420px">
      <AdBanner variant="inline" />
    </div>

    <div v-if="pass" style="max-width:420px;background:#151517;border:1px solid rgba(255,255,255,.07);border-radius:13px;padding:22px">
      <div class="ox" style="font-size:22px;font-weight:800;margin-bottom:4px">Tier {{ pass.tier }}</div>
      <div style="font-size:12px;color:rgba(255,255,255,.4);margin-bottom:18px">{{ pass.xp }} XP toward next tier</div>

      <p v-if="message" style="font-size:13px;color:#ff6a4d;margin-bottom:14px">{{ message }}</p>

      <div v-if="pass.premium" style="color:#eab308;font-weight:700;font-size:13.5px">✨ Premium unlocked</div>
      <button
        v-else
        @click="unlock"
        style="width:100%;padding:11px;border-radius:10px;border:none;background:#e8482f;color:#fff;font-weight:700;cursor:pointer"
      >
        Unlock Premium — {{ gemCost }}◆
      </button>
    </div>
  </div>
</template>
