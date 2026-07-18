<script setup>
import { ref, onMounted } from 'vue';
import api from '../api/client';

const info = ref(null);
const message = ref('');

async function load() {
  const { data } = await api.get('/vip');
  info.value = data;
}

async function subscribe(tier) {
  message.value = '';
  try {
    const { data } = await api.post('/vip/subscribe', { tier });
    window.location.href = data.checkout_url;
  } catch (e) {
    message.value = e.response?.data?.message || 'Subscriptions unavailable.';
  }
}

onMounted(load);
</script>

<template>
  <div>
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px">
      <div style="font-size:28px">👑</div>
      <h1 class="ox" style="font-size:28px;font-weight:800;margin:0">VIP</h1>
    </div>

    <p v-if="info" style="font-size:13px;color:rgba(255,255,255,.5);margin-bottom:16px">
      Current tier: <strong style="color:#eab308;text-transform:capitalize">{{ info.vip_tier }}</strong>
    </p>
    <p v-if="message" style="font-size:13px;color:#ff6a4d;max-width:480px;margin-bottom:16px">{{ message }}</p>

    <div v-if="info" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px">
      <div
        v-for="(tier, key) in info.tiers"
        :key="key"
        style="background:#151517;border:1px solid rgba(255,255,255,.07);border-radius:13px;padding:18px;text-align:center"
      >
        <div class="ox" style="font-weight:700;font-size:15px;margin-bottom:12px">{{ tier.label }}</div>
        <button
          @click="subscribe(key)"
          style="width:100%;padding:9px;border-radius:8px;border:none;background:#e8482f;color:#fff;font-weight:700;font-size:13px;cursor:pointer"
        >
          ${{ (tier.price_cents / 100).toFixed(2) }}/mo
        </button>
      </div>
    </div>
  </div>
</template>
