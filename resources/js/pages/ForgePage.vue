<script setup>
import { ref, onMounted, computed, watch } from 'vue';
import api from '../api/client';
import { useCharacterStore } from '../stores/character';
import { RARITY_COLORS, RARITY_LABELS, formatStats } from '../rarity';
import Toast from '../components/Toast.vue';

const store = useCharacterStore();
const inventory = ref([]);
const loading = ref(false);
const message = ref('');
let errorToastTimer = null;
watch(message, (val) => {
  clearTimeout(errorToastTimer);
  if (val) errorToastTimer = setTimeout(() => { message.value = ''; }, 5000);
});

// Mirrors ReforgeService's own gates/costs — the server is authoritative and re-validates on every
// request, this is just the client-side preview so a Reforge button can show its real price upfront.
const REFORGE_UNLOCK_LEVEL = 200;
const REFORGE_MAX_LEVEL = 5;
const REFORGEABLE_TYPES = ['weapon', 'armor', 'shield', 'quiver', 'trinket'];
const REFORGE_COST_GOLD = { 1: 5000, 2: 20000, 3: 55000, 4: 95000, 5: 150000 };
const REFORGE_COST_GEMS = { 1: 10, 2: 20, 3: 35, 4: 45, 5: 60 };
const REFORGE_COST_MATERIAL = { 1: 1, 2: 2, 3: 3, 4: 4, 5: 5 };

function nextLevelCost(row) {
  const level = row.enchant_level + 1;
  return { level, gold: REFORGE_COST_GOLD[level] ?? 0, gems: REFORGE_COST_GEMS[level] ?? 0, material: REFORGE_COST_MATERIAL[level] ?? 0 };
}

const catalystQty = computed(() => inventory.value.find((r) => r.item.key === 'reforging_catalyst')?.qty ?? 0);
const gearRows = computed(() =>
  [...inventory.value]
    .filter((r) => REFORGEABLE_TYPES.includes(r.item.type))
    .sort((a, b) => Number(b.equipped) - Number(a.equipped))
);
const unlocked = computed(() => (store.character?.level ?? 0) >= REFORGE_UNLOCK_LEVEL);

function canAffordNext(row) {
  const cost = nextLevelCost(row);
  return (store.character?.gold ?? 0) >= cost.gold && (store.user?.gems ?? 0) >= cost.gems && catalystQty.value >= cost.material;
}

function rarityChipStyle(rarity) {
  const color = RARITY_COLORS[rarity] || RARITY_COLORS.common;
  return { color, background: `${color}22` };
}

async function load() {
  const { data } = await api.get('/inventory');
  inventory.value = data.inventory;
}

async function reforge(row) {
  loading.value = true;
  message.value = '';
  try {
    const { data } = await api.post(`/inventory/${row.id}/reforge`);
    if (data.character) store.character = data.character;
    await load();
    message.value = `${row.item.name} reforged to level ${data.inventory_row.enchant_level}!`;
  } catch (e) {
    message.value = e.response?.data?.message || 'Could not reforge that item.';
  } finally {
    loading.value = false;
  }
}

onMounted(() => {
  load();
});
</script>

<template>
  <div>
    <div class="inventory-header">
      <div class="inventory-header__icon">🔥</div>
      <h1 class="ox inventory-title">Forge</h1>
    </div>

    <Toast :message="message" type="error" />

    <div v-if="!unlocked" class="forge-locked-banner">
      Reforging unlocks at character level {{ REFORGE_UNLOCK_LEVEL }}.
    </div>

    <div class="forge-wallet-row">
      <div class="forge-wallet-chip">💰 <span class="ox">{{ store.character?.gold ?? 0 }}</span> Gold</div>
      <div class="forge-wallet-chip">💎 <span class="ox">{{ store.user?.gems ?? 0 }}</span> Gems</div>
      <div class="forge-wallet-chip">🔮 <span class="ox">{{ catalystQty }}</span> Reforging Catalyst</div>
    </div>

    <div v-if="!gearRows.length" class="forge-empty">You have no reforgeable gear yet. Equip or craft a weapon, armor, shield, or quiver first.</div>

    <div class="forge-grid">
      <div v-for="row in gearRows" :key="row.id" class="forge-card">
        <div class="forge-card__header">
          <span class="forge-card__glyph">{{ row.item.glyph }}</span>
          <div class="forge-card__title">
            <span class="ox forge-card__name">{{ row.item.name }}</span>
            <span v-if="row.equipped" class="forge-card__equipped-tag">Equipped</span>
          </div>
        </div>
        <div class="forge-card__chips">
          <span class="inventory-rarity-chip inventory-rarity-chip--inline" :style="rarityChipStyle(row.item.rarity)">{{ RARITY_LABELS[row.item.rarity] }}</span>
          <span v-if="row.item.roll_pct != null" class="roll-chip" :class="{ 'is-good': row.item.roll_pct > 0, 'is-bad': row.item.roll_pct < 0 }">{{ row.item.roll_pct > 0 ? '+' : '' }}{{ row.item.roll_pct }}% roll</span>
        </div>
        <div v-if="formatStats(row.item.stat_json).length" class="inventory-card__stats">{{ formatStats(row.item.stat_json).join(' · ') }}</div>

        <div class="forge-pips">
          <span v-for="n in REFORGE_MAX_LEVEL" :key="n" class="forge-pip" :class="{ 'is-lit': n <= row.enchant_level }"></span>
          <span class="forge-pips__label">{{ row.enchant_level }}/{{ REFORGE_MAX_LEVEL }}</span>
        </div>

        <div v-if="row.enchant_level < REFORGE_MAX_LEVEL" class="forge-cost-row">
          <span class="forge-cost-row__item">💰 {{ nextLevelCost(row).gold }}</span>
          <span class="forge-cost-row__item">💎 {{ nextLevelCost(row).gems }}</span>
          <span class="forge-cost-row__item">🔮 {{ nextLevelCost(row).material }}</span>
        </div>
        <div v-else class="forge-cost-row forge-cost-row--maxed">Max reforge level</div>

        <button
          v-if="row.enchant_level < REFORGE_MAX_LEVEL"
          @click="reforge(row)"
          :disabled="loading || !unlocked || !canAffordNext(row)"
          class="inventory-bag-card__equip-btn forge-card__btn"
        >
          Reforge to {{ nextLevelCost(row).level }}
        </button>
      </div>
    </div>
  </div>
</template>

<style lang="scss" src="./ForgePage.scss" scoped></style>
