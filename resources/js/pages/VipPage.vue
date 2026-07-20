<script setup>
import { ref, onMounted } from 'vue';
import api from '../api/client';
import { formatCents } from '../currency';

const info = ref(null);
const message = ref('');

const TIER_RANK = { none: 0, bronze: 1, gold: 2, diamond: 3 };

function tierAction(key) {
  const current = info.value?.vip_tier ?? 'none';
  if (key === current) return 'current';
  if (current === 'none') return 'subscribe';
  return TIER_RANK[key] > TIER_RANK[current] ? 'upgrade' : 'downgrade';
}

function tierButtonLabel(key) {
  const action = tierAction(key);
  return { current: 'Current Plan', subscribe: 'Subscribe', upgrade: 'Upgrade', downgrade: 'Downgrade' }[action];
}

// Cosmetic/QoL perks that aren't part of the numeric tiers payload — every VIP tier grants both,
// confirmed against AdBanner.vue (gates on vip_tier !== 'none') and VipBadge.vue (renders per tier).
const COSMETIC_PERKS = {
  bronze: ['Ad-free experience', 'Bronze VIP name badge'],
  gold: ['Ad-free experience', 'Gold VIP name badge'],
  diamond: ['Ad-free experience', 'Diamond VIP name badge'],
};

// Grouped, ordered perk sections. Each entry maps a tiers[key] field to a display line.
// Grouping mirrors the real mechanical categories in User.php's VIP_TIER_* consts so this list
// stays a 1:1 mirror of what's actually implemented server-side, not aspirational copy.
const PERK_SECTIONS = [
  {
    title: 'Daily Limits',
    perks: [
      (tier) => `+${tier.pvp_bonus_attempts} daily PvP battle attempts`,
      (tier) => `+${tier.dungeon_bonus_attempts} daily dungeon raid attempt${tier.dungeon_bonus_attempts > 1 ? 's' : ''}`,
    ],
  },
  {
    title: 'Economy',
    perks: [
      (tier) => `+${tier.gold_xp_pct_bonus}% gold & XP from battles`,
      (tier) => `+${tier.monthly_gems} gems every month, free`,
    ],
  },
  {
    title: 'Character Power',
    perks: [
      (tier) => `+${tier.luck_bonus} base Luck`,
      (tier) => `+${tier.regen_flat_bonus} flat and +${tier.regen_pct_bonus}% HP/mana regen rate`,
      (tier) => `+${tier.energy_flat_bonus} flat and +${tier.energy_pct_bonus}% Energy regen rate`,
    ],
  },
  {
    title: 'Crafting',
    perks: [
      (tier) => `${tier.craft_speed_pct_bonus}% faster crafting`,
      (tier) => `+${tier.craft_queue_bonus} crafting queue slot${tier.craft_queue_bonus > 1 ? 's' : ''}`,
    ],
  },
  {
    title: 'Roster & Companions',
    perks: [
      (tier) => `+${tier.slots} character slot${tier.slots > 1 ? 's' : ''} (up to ${1 + tier.slots} total with subscription)`,
      (tier) => `${tier.pet_slots} active companion pet slot${tier.pet_slots > 1 ? 's' : ''}`,
    ],
  },
];

async function load() {
  const { data } = await api.get('/vip');
  info.value = data;
}

async function subscribe(tier) {
  message.value = '';
  try {
    const { data } = await api.post('/vip/subscribe', { tier });
    if (data.checkout_url) {
      window.location.href = data.checkout_url;
      return;
    }
    // In-place tier switch — no checkout redirect, the existing subscription was updated directly.
    message.value = `Switched to ${tier} VIP — your next bill reflects the prorated difference.`;
    await load();
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
          <span class="ox vip-tier-card__price-amount">{{ formatCents(tier.price_cents) }}</span>
          <span class="vip-tier-card__price-period">/mo</span>
        </div>
        <div class="vip-tier-card__perks">
          <div v-for="section in PERK_SECTIONS" :key="section.title" class="vip-perk-section">
            <div class="vip-perk-section__title">{{ section.title }}</div>
            <div v-for="(perkFn, i) in section.perks" :key="i" class="vip-tier-card__perk">
              <span class="vip-tier-card__perk-check">✔</span>{{ perkFn(tier) }}
            </div>
          </div>
          <div class="vip-perk-section">
            <div class="vip-perk-section__title">Quality of Life</div>
            <div v-for="perk in COSMETIC_PERKS[key] || []" :key="perk" class="vip-tier-card__perk">
              <span class="vip-tier-card__perk-check">✔</span>{{ perk }}
            </div>
          </div>
        </div>
        <button
          @click="subscribe(key)"
          class="vip-subscribe-btn"
          :class="{ 'vip-subscribe-btn--current': tierAction(key) === 'current' }"
          :disabled="tierAction(key) === 'current'"
        >
          {{ tierButtonLabel(key) }}
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
