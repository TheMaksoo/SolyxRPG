<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../api/client';
import AdBanner from '../components/AdBanner.vue';

const pass = ref(null);
const gemCost = ref(0);
const message = ref('');
const TOTAL_TIERS = 50;

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

const track = computed(() =>
  Array.from({ length: TOTAL_TIERS }, (_, i) => {
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
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:14px;margin-bottom:20px">
      <div>
        <div class="ox" style="font-size:22px;font-weight:800">Ashfall Season Pass</div>
        <div v-if="pass" style="font-size:13px;color:rgba(255,255,255,.5)">Tier {{ pass.tier }} / {{ TOTAL_TIERS }}</div>
      </div>
      <button
        v-if="pass && !pass.premium"
        @click="unlock"
        style="background:linear-gradient(135deg,#e8482f,#eab308);color:#fff;border:none;border-radius:10px;padding:12px 22px;font-weight:700;cursor:pointer"
      >
        Unlock Premium — {{ gemCost }}◆
      </button>
      <span v-else-if="pass" style="color:#eab308;font-weight:700;font-size:13.5px">✨ Premium unlocked</span>
    </div>

    <p v-if="message" style="font-size:13px;color:#ff6a4d;margin-bottom:14px">{{ message }}</p>

    <AdBanner variant="inline" />

    <div style="display:flex;flex-direction:column;gap:10px;overflow-x:auto;padding-bottom:8px">
      <div style="display:flex;gap:10px;min-width:max-content;align-items:center">
        <div style="width:70px;flex:none;font-size:11px;color:rgba(255,255,255,.4);text-align:right;padding-right:6px">FREE</div>
        <div
          v-for="t in track"
          :key="'f' + t.n"
          :style="{
            width: '64px', flex: 'none', textAlign: 'center', padding: '8px 4px', borderRadius: '8px',
            background: t.reached ? 'rgba(74,222,128,.1)' : '#151517',
            border: `1px solid ${t.reached ? 'rgba(74,222,128,.3)' : 'rgba(255,255,255,.06)'}`,
          }"
        >
          <div style="font-size:20px">{{ t.freeGlyph }}</div>
          <div style="font-size:9px;color:rgba(255,255,255,.5);margin-top:2px">{{ t.freeReward }}</div>
        </div>
      </div>
      <div style="display:flex;gap:10px;min-width:max-content;align-items:center">
        <div style="width:70px;flex:none;font-size:11px;color:#eab308;text-align:right;padding-right:6px;font-weight:700">TIER</div>
        <div v-for="t in track" :key="'n' + t.n" style="width:64px;flex:none;text-align:center">
          <div class="ox" :style="{ fontSize: '13px', fontWeight: 700, color: t.reached ? '#e8482f' : 'rgba(255,255,255,.3)' }">{{ t.n }}</div>
        </div>
      </div>
      <div style="display:flex;gap:10px;min-width:max-content;align-items:center">
        <div style="width:70px;flex:none;font-size:11px;color:#e8482f;text-align:right;padding-right:6px;font-weight:700">PREMIUM</div>
        <div
          v-for="t in track"
          :key="'p' + t.n"
          :style="{
            width: '64px', flex: 'none', textAlign: 'center', padding: '8px 4px', borderRadius: '8px',
            background: pass?.premium && t.reached ? 'rgba(232,72,47,.12)' : '#151517',
            border: `1px solid ${pass?.premium && t.reached ? 'rgba(232,72,47,.3)' : 'rgba(255,255,255,.06)'}`,
            opacity: pass?.premium ? 1 : 0.5,
          }"
        >
          <div style="font-size:20px">{{ t.premGlyph }}</div>
          <div style="font-size:9px;color:#ff8163;margin-top:2px">{{ t.premReward }}</div>
        </div>
      </div>
    </div>
  </div>
</template>
