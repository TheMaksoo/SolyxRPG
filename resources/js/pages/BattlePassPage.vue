<script setup>
import { ref, computed, onMounted, nextTick } from 'vue';
import api from '../api/client';
import AdBanner from '../components/AdBanner.vue';

const pass = ref(null);
const gemCost = ref(0);
const totalTiers = ref(100);
const track = ref([]);
const message = ref('');
const trackScrollEl = ref(null);

async function load({ scrollToCurrent = false } = {}) {
  const { data } = await api.get('/battlepass');
  pass.value = data.battle_pass;
  gemCost.value = data.premium_gem_cost;
  totalTiers.value = data.total_tiers;
  track.value = data.track;

  if (scrollToCurrent) {
    nextTick(() => scrollToCurrentTier());
  }
}

function scrollToCurrentTier() {
  const container = trackScrollEl.value;
  if (!container || !pass.value) return;
  const tileWidth = 96; // keep in sync with .tier-tile width + gap in the scss
  const currentTier = Math.max(1, pass.value.tier);
  const target = tileWidth * (currentTier - 1) - container.clientWidth / 2 + tileWidth / 2;
  container.scrollTo({ left: Math.max(0, target), behavior: 'smooth' });
}

function showMessage(text) {
  message.value = text;
  setTimeout(() => {
    if (message.value === text) message.value = '';
  }, 4000);
}

async function unlockWithGems() {
  try {
    const { data } = await api.post('/battlepass/unlock');
    pass.value = data.battle_pass;
    showMessage('Premium unlocked!');
  } catch (e) {
    showMessage(e.response?.data?.message || 'Not enough gems.');
  }
}

async function unlockWithCash() {
  try {
    const { data } = await api.post('/store/checkout', { sku: 'pass_ashfall' });
    window.location.href = data.checkout_url;
  } catch (e) {
    showMessage(e.response?.data?.message || 'Checkout unavailable.');
  }
}

const claimSummary = ref(null);

/** Merges duplicate item names (Claim All can grant the same repair pack on several tiers) into one line. */
function mergeItems(items) {
  const byName = {};
  for (const item of items) {
    if (!byName[item.name]) byName[item.name] = { ...item };
    else byName[item.name].qty += item.qty;
  }
  return Object.values(byName);
}

async function claim(tier, trackKey) {
  try {
    const { data } = await api.post('/battlepass/claim', { tier, track: trackKey });
    pass.value = data.battle_pass;
    claimSummary.value = {
      title: `Tier ${tier} claimed (${trackKey})`,
      gold: data.reward.gold,
      gems: data.reward.gems,
      items: mergeItems(data.reward.items),
    };
  } catch (e) {
    showMessage(e.response?.data?.message || 'Could not claim that.');
  }
}

async function claimAll() {
  try {
    const { data } = await api.post('/battlepass/claim-all');
    pass.value = data.battle_pass;
    if (data.totals.tiers_claimed === 0) {
      showMessage('Nothing ready to claim.');
      return;
    }
    claimSummary.value = {
      title: `${data.totals.tiers_claimed} tier${data.totals.tiers_claimed > 1 ? 's' : ''} claimed`,
      gold: data.totals.gold,
      gems: data.totals.gems,
      items: mergeItems(data.totals.items),
    };
  } catch (e) {
    showMessage(e.response?.data?.message || 'Could not claim rewards.');
  }
}

const hasClaimable = computed(() => {
  if (!pass.value) return false;
  const freeClaimed = pass.value.claimed_free_tiers || [];
  const premClaimed = pass.value.claimed_premium_tiers || [];
  for (let tier = 1; tier <= pass.value.tier; tier++) {
    if (!freeClaimed.includes(tier)) return true;
    if (pass.value.premium && !premClaimed.includes(tier)) return true;
  }
  return false;
});

function rewardLabel(reward) {
  const parts = [];
  if (reward.gold) parts.push(`${reward.gold}g`);
  if (reward.gems) parts.push(`${reward.gems}◆`);
  for (const item of reward.items) {
    const qtySuffix = item.qty > 1 ? `×${item.qty}` : '';
    parts.push(`${item.glyph}${qtySuffix}`);
  }
  return parts.join(' ');
}

function tileGlyph(row, isPremium) {
  const reward = isPremium ? row.premium_reward : row.free_reward;
  if (reward.items.length) return reward.items[reward.items.length - 1].glyph;
  return reward.gems ? '💎' : '🪙';
}

function tileState(tier, trackKey) {
  if (!pass.value) return 'locked';
  if (tier > pass.value.tier) return 'locked';
  const claimedList = trackKey === 'premium' ? pass.value.claimed_premium_tiers : pass.value.claimed_free_tiers;
  return (claimedList || []).includes(tier) ? 'claimed' : 'ready';
}

/** Sum of every premium-track reward across the whole season — the pitch for buying premium. */
const premiumTotals = computed(() => {
  const totals = { gold: 0, gems: 0, items: {} };
  for (const row of track.value) {
    totals.gold += row.premium_reward.gold;
    totals.gems += row.premium_reward.gems;
    for (const item of row.premium_reward.items) {
      totals.items[item.name] = (totals.items[item.name] || 0) + item.qty;
    }
  }
  return totals;
});

const xpIntoTier = computed(() => pass.value?.xp ?? 0);
const xpNeededForNextTier = computed(() => {
  const row = track.value.find((r) => r.tier === (pass.value?.tier ?? 0) + 1);
  return row?.xp_required ?? null;
});

onMounted(() => load({ scrollToCurrent: true }));
</script>

<template>
  <div>
    <div class="battlepass-header">
      <div>
        <div class="ox battlepass-header__title">Ashfall Season Pass</div>
        <div v-if="pass" class="battlepass-header__tier">Tier {{ pass.tier }} / {{ totalTiers }}</div>
        <div v-if="pass && xpNeededForNextTier" class="battlepass-header__xp">
          {{ xpIntoTier }} / {{ xpNeededForNextTier }} xp to next tier
        </div>
        <div v-else-if="pass" class="battlepass-header__xp">Max tier reached!</div>
      </div>
      <div class="battlepass-header__actions">
        <span v-if="pass?.premium" class="battlepass-header__premium-badge">✨ Premium unlocked</span>
        <button v-if="hasClaimable" @click="claimAll" class="battlepass-claim-all-btn">Claim All</button>
      </div>
    </div>

    <p v-if="message" class="battlepass-message">{{ message }}</p>

    <div v-if="claimSummary" class="claim-summary">
      <div class="claim-summary__header">
        <span class="ox claim-summary__title">🎉 {{ claimSummary.title }}</span>
        <button class="claim-summary__close" @click="claimSummary = null">✕</button>
      </div>
      <div class="claim-summary__rows">
        <span v-if="claimSummary.gold" class="claim-summary__chip">🪙 +{{ claimSummary.gold }} gold</span>
        <span v-if="claimSummary.gems" class="claim-summary__chip">💎 +{{ claimSummary.gems }} gems</span>
        <span v-for="item in claimSummary.items" :key="item.name" class="claim-summary__chip">
          {{ item.glyph }} {{ item.name }}<template v-if="item.qty > 1"> ×{{ item.qty }}</template>
        </span>
      </div>
    </div>

    <AdBanner variant="inline" />

    <div v-if="pass && !pass.premium" class="battlepass-premium-pitch">
      <div class="battlepass-premium-pitch__header">
        <div class="ox battlepass-premium-pitch__title">Unlock Premium</div>
        <div class="battlepass-premium-pitch__subtitle">
          Everything below, for the whole season, on top of the free track:
        </div>
      </div>
      <div class="battlepass-premium-pitch__totals">
        <span class="battlepass-premium-pitch__total-chip">🪙 {{ premiumTotals.gold }}g total</span>
        <span class="battlepass-premium-pitch__total-chip">💎 {{ premiumTotals.gems }}◆ total</span>
        <span v-for="(qty, name) in premiumTotals.items" :key="name" class="battlepass-premium-pitch__total-chip">
          {{ qty }}× {{ name }}
        </span>
      </div>
      <div class="battlepass-premium-pitch__actions">
        <button @click="unlockWithGems" class="battlepass-unlock-btn">Unlock — {{ gemCost }}◆</button>
        <button @click="unlockWithCash" class="battlepass-unlock-btn battlepass-unlock-btn--cash">Unlock — $5.99</button>
      </div>
    </div>

    <div class="battlepass-track" ref="trackScrollEl">
      <div class="battlepass-row">
        <div class="battlepass-row__label">FREE</div>
        <div
          v-for="row in track"
          :key="'f' + row.tier"
          class="tier-tile"
          :class="{
            'tier-tile--reached': tileState(row.tier, 'free') !== 'locked',
            'tier-tile--claimed': tileState(row.tier, 'free') === 'claimed',
          }"
        >
          <div class="tier-tile__glyph">{{ tileGlyph(row, false) }}</div>
          <div class="tier-tile__reward">{{ rewardLabel(row.free_reward) }}</div>
          <button
            v-if="tileState(row.tier, 'free') === 'ready'"
            @click="claim(row.tier, 'free')"
            class="tier-tile__claim-btn"
          >
            Claim
          </button>
          <div v-else-if="tileState(row.tier, 'free') === 'claimed'" class="tier-tile__claimed-tag">✓</div>
        </div>
      </div>
      <div class="battlepass-row">
        <div class="battlepass-row__label battlepass-row__label--tier">TIER</div>
        <div v-for="row in track" :key="'n' + row.tier" class="tier-number-cell">
          <div class="ox tier-number" :class="{ 'tier-number--reached': row.tier <= (pass?.tier ?? 0) }">{{ row.tier }}</div>
        </div>
      </div>
      <div class="battlepass-row">
        <div class="battlepass-row__label battlepass-row__label--premium">PREMIUM</div>
        <div
          v-for="row in track"
          :key="'p' + row.tier"
          class="tier-tile"
          :class="{
            'tier-tile--premium-reached': pass?.premium && tileState(row.tier, 'premium') !== 'locked',
            'tier-tile--claimed': tileState(row.tier, 'premium') === 'claimed',
            'tier-tile--dim': !pass?.premium,
          }"
        >
          <div class="tier-tile__glyph">{{ tileGlyph(row, true) }}</div>
          <div class="tier-tile__reward tier-tile__reward--premium">{{ rewardLabel(row.premium_reward) }}</div>
          <button
            v-if="pass?.premium && tileState(row.tier, 'premium') === 'ready'"
            @click="claim(row.tier, 'premium')"
            class="tier-tile__claim-btn tier-tile__claim-btn--premium"
          >
            Claim
          </button>
          <div v-else-if="pass?.premium && tileState(row.tier, 'premium') === 'claimed'" class="tier-tile__claimed-tag">✓</div>
        </div>
      </div>
    </div>
  </div>
</template>

<style lang="scss" src="./BattlePassPage.scss" scoped></style>
