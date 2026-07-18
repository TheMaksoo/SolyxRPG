<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../api/client';
import AdBanner from '../components/AdBanner.vue';

const pass = ref(null);
const gemCost = ref(0);
const message = ref('');
const TOTAL_TIERS = ref(50);

async function load() {
  const { data } = await api.get('/battlepass');
  pass.value = data.battle_pass;
  gemCost.value = data.premium_gem_cost;
  TOTAL_TIERS.value = data.total_tiers;
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

const track = computed(() =>
  Array.from({ length: TOTAL_TIERS.value }, (_, i) => {
    const n = i + 1;
    const milestone = n % 5 === 0;
    return {
      n,
      reached: pass.value && n <= pass.value.tier,
      freeGlyph: milestone ? '🎁' : '🪙',
      freeReward: milestone ? `${n * 20}g` : `+${n * 5}g`,
      premGlyph: milestone ? '💎' : '✨',
      premReward: milestone ? `${n}◆` : `+${n * 10}g`,
    };
  })
);

onMounted(load);
</script>

<template>
  <div>
    <div class="battlepass-header">
      <div>
        <div class="ox battlepass-header__title">Ashfall Season Pass</div>
        <div v-if="pass" class="battlepass-header__tier">Tier {{ pass.tier }} / {{ TOTAL_TIERS }}</div>
        <div v-if="pass" class="battlepass-header__xp">{{ pass.xp }} / 100 xp to next tier</div>
      </div>
      <button
        v-if="pass && !pass.premium"
        @click="unlock"
        class="battlepass-unlock-btn"
      >
        Unlock Premium — {{ gemCost }}◆
      </button>
      <span v-else-if="pass" class="battlepass-header__premium-badge">✨ Premium unlocked</span>
    </div>

    <p v-if="message" class="battlepass-message">{{ message }}</p>

    <AdBanner variant="inline" />

    <div class="battlepass-track">
      <div class="battlepass-row">
        <div class="battlepass-row__label">FREE</div>
        <div
          v-for="t in track"
          :key="'f' + t.n"
          class="tier-tile"
          :class="{ 'tier-tile--reached': t.reached }"
        >
          <div class="tier-tile__glyph">{{ t.freeGlyph }}</div>
          <div class="tier-tile__reward">{{ t.freeReward }}</div>
        </div>
      </div>
      <div class="battlepass-row">
        <div class="battlepass-row__label battlepass-row__label--tier">TIER</div>
        <div v-for="t in track" :key="'n' + t.n" class="tier-number-cell">
          <div class="ox tier-number" :class="{ 'tier-number--reached': t.reached }">{{ t.n }}</div>
        </div>
      </div>
      <div class="battlepass-row">
        <div class="battlepass-row__label battlepass-row__label--premium">PREMIUM</div>
        <div
          v-for="t in track"
          :key="'p' + t.n"
          class="tier-tile"
          :class="{ 'tier-tile--premium-reached': pass?.premium && t.reached, 'tier-tile--dim': !pass?.premium }"
        >
          <div class="tier-tile__glyph">{{ t.premGlyph }}</div>
          <div class="tier-tile__reward tier-tile__reward--premium">{{ t.premReward }}</div>
        </div>
      </div>
    </div>
  </div>
</template>

<style lang="scss" src="./BattlePassPage.scss" scoped></style>
