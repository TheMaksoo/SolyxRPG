<script setup>
import { ref, onMounted, computed } from 'vue';
import api from '../api/client';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const packs = ref({});
const removeAds = ref(null);
const message = ref('');

const adsVisible = computed(() => !auth.user?.ads_removed && (auth.user?.vip_tier ?? 'none') === 'none');

async function load() {
  const { data } = await api.get('/store/gems');
  packs.value = data.packs;
  removeAds.value = data.remove_ads;
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

    <p style="font-size:13px;color:rgba(255,255,255,.5);max-width:560px;margin-bottom:16px">
      Premium currency for cosmetics, revives, and Battle Pass tiers. Gems never affect combat stats — no pay-to-win.
    </p>

    <p v-if="message" style="font-size:13px;color:#ff6a4d;max-width:480px;margin-bottom:16px">{{ message }}</p>

    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px;max-width:1000px">
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

    <div
      v-if="adsVisible && removeAds"
      style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;background:#151517;border:1px solid rgba(255,255,255,.07);border-radius:12px;padding:16px 18px;margin-top:20px;max-width:1000px"
    >
      <div style="display:flex;align-items:center;gap:12px">
        <div style="font-size:24px">🚫</div>
        <div>
          <div style="font-weight:700;font-size:14px">Remove Ads</div>
          <div style="font-size:12px;color:rgba(255,255,255,.5)">One-time purchase — removes ads permanently (also included with any VIP tier).</div>
        </div>
      </div>
      <button
        @click="checkout('remove_ads')"
        style="background:#e8482f;color:#fff;border:none;border-radius:9px;padding:10px 18px;font-weight:700;font-size:13px;cursor:pointer"
      >
        ${{ (removeAds.price_cents / 100).toFixed(2) }}
      </button>
    </div>

    <div
      v-if="adsVisible"
      style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;background:linear-gradient(100deg,rgba(56,189,248,.1),rgba(56,189,248,.02));border:1px solid rgba(56,189,248,.25);border-radius:12px;padding:16px 18px;margin-top:24px;max-width:1000px"
    >
      <div style="display:flex;align-items:center;gap:12px">
        <div style="font-size:24px">🎬</div>
        <div>
          <div style="font-weight:700;font-size:14px">Free gems — watch a short ad</div>
          <div style="font-size:12px;color:rgba(255,255,255,.5)">Not wired up yet — needs a real ad-network SDK (e.g. AdSense rewarded ads).</div>
        </div>
      </div>
      <router-link
        to="/vip"
        style="background:#38bdf8;color:#04252e;border:none;border-radius:9px;padding:10px 18px;font-weight:700;font-size:13px"
        >Go ad-free with VIP</router-link
      >
    </div>
  </div>
</template>
