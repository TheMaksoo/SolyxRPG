<script setup>
import { ref, onMounted } from 'vue';
import api from '../api/client';

const packs = ref({});
const message = ref('');

async function load() {
  const { data } = await api.get('/store/gems');
  packs.value = data.packs;
}

async function checkout(sku) {
  message.value = '';
  try {
    const { data } = await api.post('/store/checkout', { sku });
    window.location.href = data.checkout_url;
  } catch (e) {
    message.value = e.response?.data?.message || 'Checkout unavailable.';
  }
}

onMounted(load);
</script>

<template>
  <div>
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px">
      <div style="font-size:28px">💎</div>
      <h1 class="ox" style="font-size:28px;font-weight:800;margin:0">Gem Store</h1>
    </div>

    <p v-if="message" style="font-size:13px;color:#ff6a4d;max-width:480px;margin-bottom:16px">{{ message }}</p>

    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px">
      <div
        v-for="(pack, sku) in packs"
        :key="sku"
        style="background:#151517;border:1px solid rgba(255,255,255,.07);border-radius:13px;padding:18px;text-align:center"
      >
        <div style="font-size:26px;margin-bottom:8px">◆</div>
        <div class="ox" style="font-weight:700;font-size:15px;margin-bottom:12px">{{ pack.gems }} Gems</div>
        <button
          @click="checkout(sku)"
          style="width:100%;padding:9px;border-radius:8px;border:none;background:#e8482f;color:#fff;font-weight:700;font-size:13px;cursor:pointer"
        >
          ${{ (pack.price_cents / 100).toFixed(2) }}
        </button>
      </div>
    </div>
  </div>
</template>
