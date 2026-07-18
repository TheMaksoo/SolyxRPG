<script setup>
import { ref, onMounted } from 'vue';
import api from '../api/client';

const info = ref(null);
const message = ref('');

const PERKS = {
  bronze: ['Ad-free experience', '+10% gold from battles', 'Bronze VIP name badge'],
  gold: ['Everything in Bronze', '+20% gold & XP from battles', '2 free gem-store rerolls a day', 'Gold VIP name badge'],
  diamond: ['Everything in Gold', '+35% gold & XP from battles', 'Daily bonus gem stipend', 'Diamond VIP name badge & aura'],
};

function slotPerk(tier) {
  return `+${tier.slots} character slot${tier.slots > 1 ? 's' : ''} (up to ${1 + tier.slots} total with subscription)`;
}

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
    <div style="text-align:center;margin-bottom:26px">
      <div class="ox" style="font-size:26px;font-weight:800">Solyx VIP</div>
      <p style="color:rgba(255,255,255,.5);margin:6px 0 0">Monthly membership. Boosts, convenience, and cosmetics — cancel anytime.</p>
      <p v-if="info" style="font-size:13px;color:rgba(255,255,255,.5);margin-top:8px">
        Current tier: <strong style="color:#eab308;text-transform:capitalize">{{ info.vip_tier }}</strong>
      </p>
    </div>

    <p v-if="message" style="font-size:13px;color:#ff6a4d;max-width:480px;margin:0 auto 16px;text-align:center">{{ message }}</p>

    <div v-if="info" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;max-width:900px;margin:0 auto">
      <div
        v-for="(tier, key) in info.tiers"
        :key="key"
        :style="{
          background: key === 'gold' ? 'linear-gradient(160deg,#1a1013,#151517)' : '#151517',
          border: `1px solid ${key === 'gold' ? 'rgba(234,179,8,.35)' : 'rgba(255,255,255,.07)'}`,
          borderRadius: '14px',
          padding: '22px',
        }"
      >
        <div v-if="key === 'gold'" style="text-align:center;font-size:11px;font-weight:700;color:#eab308;margin-bottom:8px">MOST POPULAR</div>
        <div class="ox" style="font-size:18px;font-weight:800;text-align:center">{{ tier.label }}</div>
        <div style="text-align:center;margin:6px 0 16px">
          <span class="ox" style="font-size:32px;font-weight:800">${{ (tier.price_cents / 100).toFixed(2) }}</span>
          <span style="font-size:13px;color:rgba(255,255,255,.4)">/mo</span>
        </div>
        <div style="display:flex;flex-direction:column;gap:9px;margin-bottom:18px">
          <div style="display:flex;gap:9px;font-size:13px;color:rgba(255,255,255,.7)">
            <span style="color:#4ade80">✔</span>{{ slotPerk(tier) }}
          </div>
          <div v-for="perk in PERKS[key] || []" :key="perk" style="display:flex;gap:9px;font-size:13px;color:rgba(255,255,255,.7)">
            <span style="color:#4ade80">✔</span>{{ perk }}
          </div>
        </div>
        <button
          @click="subscribe(key)"
          style="width:100%;padding:11px;border-radius:9px;border:none;background:#e8482f;color:#fff;font-weight:700;font-size:13px;cursor:pointer"
        >
          Subscribe
        </button>
      </div>
    </div>

    <div style="text-align:center;margin-top:24px;font-size:13px;color:rgba(255,255,255,.4)">
      Ad-free is included in every VIP tier.
      <router-link to="/gem-store" style="color:#ff6a4d">Or remove ads only — a one-time gem-store purchase</router-link>
    </div>
  </div>
</template>
