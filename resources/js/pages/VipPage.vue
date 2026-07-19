<script setup>
import { ref, onMounted } from 'vue';
import api from '../api/client';

const info = ref(null);
const message = ref('');

const PERKS = {
  bronze: ['Ad-free experience', 'Bronze VIP name badge'],
  gold: ['Everything in Bronze', 'Gold VIP name badge'],
  diamond: ['Everything in Gold', 'Diamond VIP name badge'],
};

function monthlyGemsPerk(tier) {
  return `+${tier.monthly_gems} gems every month, free`;
}

function slotPerk(tier) {
  return `+${tier.slots} character slot${tier.slots > 1 ? 's' : ''} (up to ${1 + tier.slots} total with subscription)`;
}

function luckPerk(tier) {
  return `+${tier.luck_bonus} base Luck`;
}

function goldXpPerk(tier) {
  return `+${tier.gold_xp_pct_bonus}% gold & XP from battles`;
}

function regenPerk(tier) {
  return `+${tier.regen_flat_bonus} flat and +${tier.regen_pct_bonus}% HP/mana regen rate`;
}

function craftSpeedPerk(tier) {
  return `${tier.craft_speed_pct_bonus}% faster crafting`;
}

function energyPerk(tier) {
  return `+${tier.energy_flat_bonus} flat and +${tier.energy_pct_bonus}% Energy regen rate`;
}

function craftQueuePerk(tier) {
  return `+${tier.craft_queue_bonus} crafting queue slot${tier.craft_queue_bonus > 1 ? 's' : ''}`;
}

function petSlotsPerk(tier) {
  return `${tier.pet_slots} active companion pet slot${tier.pet_slots > 1 ? 's' : ''}`;
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
    <div class="vip-header">
      <div class="ox vip-header__title">Solyx VIP</div>
      <p class="vip-header__subtitle">Monthly membership. Boosts, convenience, and cosmetics — cancel anytime.</p>
      <p v-if="info" class="vip-header__current-tier">
        Current tier: <strong>{{ info.vip_tier }}</strong>
      </p>
    </div>

    <p v-if="message" class="vip-message">{{ message }}</p>

    <div v-if="info" class="vip-tiers-grid">
      <div
        v-for="(tier, key) in info.tiers"
        :key="key"
        class="vip-tier-card"
        :class="{ 'is-featured': key === 'gold' }"
      >
        <div v-if="key === 'gold'" class="vip-tier-card__badge">MOST POPULAR</div>
        <div class="ox vip-tier-card__label">{{ tier.label }}</div>
        <div class="vip-tier-card__price">
          <span class="ox vip-tier-card__price-amount">${{ (tier.price_cents / 100).toFixed(2) }}</span>
          <span class="vip-tier-card__price-period">/mo</span>
        </div>
        <div class="vip-tier-card__perks">
          <div class="vip-tier-card__perk">
            <span class="vip-tier-card__perk-check">✔</span>{{ slotPerk(tier) }}
          </div>
          <div class="vip-tier-card__perk">
            <span class="vip-tier-card__perk-check">✔</span>{{ luckPerk(tier) }}
          </div>
          <div class="vip-tier-card__perk">
            <span class="vip-tier-card__perk-check">✔</span>{{ goldXpPerk(tier) }}
          </div>
          <div class="vip-tier-card__perk">
            <span class="vip-tier-card__perk-check">✔</span>{{ regenPerk(tier) }}
          </div>
          <div class="vip-tier-card__perk">
            <span class="vip-tier-card__perk-check">✔</span>{{ craftQueuePerk(tier) }}
          </div>
          <div class="vip-tier-card__perk">
            <span class="vip-tier-card__perk-check">✔</span>{{ petSlotsPerk(tier) }}
          </div>
          <div class="vip-tier-card__perk">
            <span class="vip-tier-card__perk-check">✔</span>{{ craftSpeedPerk(tier) }}
          </div>
          <div class="vip-tier-card__perk">
            <span class="vip-tier-card__perk-check">✔</span>{{ energyPerk(tier) }}
          </div>
          <div class="vip-tier-card__perk">
            <span class="vip-tier-card__perk-check">✔</span>{{ monthlyGemsPerk(tier) }}
          </div>
          <div v-for="perk in PERKS[key] || []" :key="perk" class="vip-tier-card__perk">
            <span class="vip-tier-card__perk-check">✔</span>{{ perk }}
          </div>
        </div>
        <button
          @click="subscribe(key)"
          class="vip-subscribe-btn"
        >
          Subscribe
        </button>
      </div>
    </div>

    <div class="vip-footer">
      Ad-free is included in every VIP tier.
      <router-link to="/gem-store" class="vip-footer__link">Or remove ads only — a one-time gem-store purchase</router-link>
    </div>
  </div>
</template>

<style lang="scss" src="./VipPage.scss" scoped></style>
