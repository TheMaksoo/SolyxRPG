<script setup>
import { ref, onMounted, computed } from 'vue';
import api from '../api/client';
import { useAuthStore } from '../stores/auth';
import AdBanner from '../components/AdBanner.vue';
import { formatStats, RARITY_COLORS, RARITY_LABELS } from '../rarity';

const auth = useAuthStore();
const items = ref([]);
const tab = ref('weapon');
const message = ref('');
const loading = ref(false);

const tabs = [
  { key: 'weapon', label: 'Weapons', glyph: '⚔' },
  { key: 'armor', label: 'Armor', glyph: '🛡' },
  { key: 'quiver', label: 'Quivers', glyph: '🎯' },
  { key: 'pickaxe', label: 'Pickaxes', glyph: '⛏' },
  { key: 'axe', label: 'Axes', glyph: '🪓' },
  { key: 'sickle', label: 'Sickles', glyph: '🔪' },
  { key: 'hammer', label: 'Hammers', glyph: '🔨' },
  { key: 'consumable', label: 'Consumables', glyph: '🧪' },
  { key: 'repair_pack', label: 'Repair Packs', glyph: '🧰' },
  { key: 'material', label: 'Materials', glyph: '🪨' },
  { key: 'cosmetic', label: 'Cosmetics', glyph: '👑' },
];

// Higher rarities are gated by level (see items.min_level) — below that level the item is a mystery
// rather than a fully-revealed thing you simply can't afford yet, so the rarer tiers stay something to
// look forward to instead of a wall of unaffordable-but-fully-spoiled gear.
const filtered = computed(() =>
  items.value
    .filter((i) => i.type === tab.value)
    .map((i) => ({ ...i, mystery: (auth.user?.character?.level ?? 0) < i.min_level }))
);

function canAfford(item) {
  const character = auth.user?.character;
  if (!character) return true;
  if (item.price_gold) return character.gold >= item.price_gold;
  if (item.price_gems) return character.gems >= item.price_gems;
  return false;
}

async function load() {
  const { data } = await api.get('/shop');
  items.value = data.items;
}

async function buy(item) {
  message.value = '';
  loading.value = true;
  try {
    const { data } = await api.post('/shop/buy', { item_id: item.id });
    auth.user.character.gold = data.character.gold;
    auth.user.character.gems = data.character.gems;
    message.value = `Bought ${item.name}.`;
  } catch (e) {
    message.value = e.response?.data?.message || 'Purchase failed.';
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>

<template>
  <div>
    <div class="shop-header">
      <div class="shop-header__left">
        <div class="shop-header__icon">🛒</div>
        <h1 class="ox shop-title">Shop</h1>
      </div>
      <div v-if="auth.user?.character" class="shop-header__currency">
        {{ auth.user.character.gold }}g · {{ auth.user.character.gems }}◆
      </div>
    </div>

    <div class="shop-tabs">
      <button
        v-for="t in tabs"
        :key="t.key"
        @click="tab = t.key"
        class="shop-tab-btn"
        :class="{ 'is-active': tab === t.key }"
      >
        <span class="shop-tab-btn__glyph">{{ t.glyph }}</span> {{ t.label }}
      </button>
    </div>

    <p v-if="message" class="shop-message">{{ message }}</p>

    <AdBanner variant="inline" />

    <div class="shop-grid">
      <div
        v-for="item in filtered"
        :key="item.id"
        class="shop-item-card"
        :class="{ 'shop-item-card--mystery': item.mystery }"
      >
        <template v-if="item.mystery">
          <div class="shop-item__header">
            <span class="shop-item__glyph">❔</span>
            <span class="ox shop-item__name">???</span>
            <span class="shop-item__rarity" :style="{ color: RARITY_COLORS[item.rarity] }">{{ RARITY_LABELS[item.rarity] }}</span>
          </div>
          <div class="shop-item__desc">🔒 Unlocks at level {{ item.min_level }}</div>
        </template>
        <template v-else>
          <div class="shop-item__header">
            <span class="shop-item__glyph">{{ item.glyph }}</span>
            <span class="ox shop-item__name">{{ item.name }}</span>
            <span class="shop-item__rarity" :style="{ color: RARITY_COLORS[item.rarity] }">{{ RARITY_LABELS[item.rarity] }}</span>
          </div>
          <div v-if="formatStats(item.stat_json).length" class="shop-item__stats">
            <span v-for="stat in formatStats(item.stat_json)" :key="stat" class="shop-item__stat">{{ stat }}</span>
          </div>
          <div class="shop-item__desc">{{ item.description }}</div>
          <button
            v-if="item.price_gold || item.price_gems"
            @click="buy(item)"
            :disabled="loading || !canAfford(item)"
            class="shop-buy-btn"
            :class="{ 'shop-buy-btn--gems': !item.price_gold }"
          >
            Buy — {{ item.price_gold ? `🪙 ${item.price_gold}` : `◆ ${item.price_gems}` }}
          </button>
          <div v-else class="shop-item__craft-only">🔨 Crafting only</div>
        </template>
      </div>
      <div v-if="!filtered.length" class="shop-empty">Nothing here yet.</div>
    </div>
  </div>
</template>

<style lang="scss" src="./ShopPage.scss" scoped></style>
