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
    <div class="gem-store-header">
      <div class="gem-store-header__icon">💎</div>
      <h1 class="ox gem-store-title">Gem Store</h1>
    </div>

    <p class="gem-store-intro">
      Premium currency for cosmetics, revives, and Battle Pass tiers. Gems never affect combat stats — no pay-to-win.
    </p>

    <p v-if="message" class="gem-store-error">{{ message }}</p>

    <div class="gem-packs">
      <div v-for="(pack, sku) in packs" :key="sku" class="gem-pack">
        <div class="gem-pack__icon">◆</div>
        <div class="ox gem-pack__label">{{ pack.gems }} Gems</div>
        <button @click="checkout(sku)" class="gem-pack__buy">
          ${{ (pack.price_cents / 100).toFixed(2) }}
        </button>
      </div>
    </div>

    <div v-if="adsVisible && removeAds" class="remove-ads-card">
      <div class="remove-ads-card__info">
        <div class="remove-ads-card__icon">🚫</div>
        <div>
          <div class="remove-ads-card__title">Remove Ads</div>
          <div class="remove-ads-card__desc">One-time purchase — removes ads permanently (also included with any VIP tier).</div>
        </div>
      </div>
      <button @click="checkout('remove_ads')" class="remove-ads-card__buy">
        ${{ (removeAds.price_cents / 100).toFixed(2) }}
      </button>
    </div>

    <div v-if="adsVisible" class="ad-free-card">
      <div class="ad-free-card__info">
        <div class="ad-free-card__icon">🎬</div>
        <div>
          <div class="ad-free-card__title">Free gems — watch a short ad</div>
          <div class="ad-free-card__desc">Not wired up yet — needs a real ad-network SDK (e.g. AdSense rewarded ads).</div>
        </div>
      </div>
      <router-link to="/vip" class="ad-free-card__cta">Go ad-free with VIP</router-link>
    </div>
  </div>
</template>

<style lang="scss" src="./GemStorePage.scss" scoped></style>
