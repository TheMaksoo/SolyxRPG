<script setup>
import { ref, onMounted, computed } from 'vue';
import api from '../api/client';
import { useAuthStore } from '../stores/auth';
import { useCharacterStore } from '../stores/character';
import AdBanner from '../components/AdBanner.vue';
import Toast from '../components/Toast.vue';
import { formatStats, RARITY_COLORS, RARITY_LABELS } from '../rarity';

const RARITY_ORDER = ['common', 'rare', 'epic', 'legendary', 'mythic'];

const auth = useAuthStore();
const characterStore = useCharacterStore();
const items = ref([]);
const tab = ref('weapon');
const message = ref('');
const messageType = ref('success');
const loading = ref(false);
let messageTimer = null;

function showMessage(text, type = 'success') {
  clearTimeout(messageTimer);
  message.value = text;
  messageType.value = type;
  messageTimer = setTimeout(() => { message.value = ''; }, 3000);
}

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
  const character = characterStore.character;
  if (!character) return true;
  if (item.price_gold) return character.gold >= item.price_gold;
  if (item.price_gems) return character.gems >= item.price_gems;
  return false;
}

async function load() {
  const { data } = await api.get('/shop');
  items.value = data.items;
}

// Grouped by rarity within the active type tab (common -> mythic) so higher-tier gear reads as a
// clear progression instead of one flat unsorted grid — mirrors CraftingPage's section treatment.
const groupedByRarity = computed(() => {
  const groups = RARITY_ORDER.map((rarity) => ({
    rarity,
    label: RARITY_LABELS[rarity],
    color: RARITY_COLORS[rarity],
    items: filtered.value.filter((i) => i.rarity === rarity),
  })).filter((g) => g.items.length);

  // Anything with an unrecognized/missing rarity still shows up rather than silently vanishing.
  const known = new Set(RARITY_ORDER);
  const leftover = filtered.value.filter((i) => !known.has(i.rarity));
  if (leftover.length) groups.push({ rarity: 'other', label: 'Other', color: null, items: leftover });

  return groups;
});

async function buy(item) {
  loading.value = true;
  try {
    const { data } = await api.post('/shop/buy', { item_id: item.id });
    characterStore.character = data.character;
    if (auth.user) auth.user.character = data.character;
    showMessage(`Bought ${item.name} — added to your inventory.`, 'success');
  } catch (e) {
    showMessage(e.response?.data?.message || 'Purchase failed.', 'error');
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
      <div v-if="characterStore.character" class="shop-header__currency">
        {{ characterStore.character.gold }}g · {{ characterStore.character.gems }}◆
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

    <Toast :message="message" :type="messageType" />

    <AdBanner variant="inline" />

    <div v-for="group in groupedByRarity" :key="group.rarity" class="shop-rarity-group">
      <div class="shop-rarity-eyebrow" :style="group.color ? { color: group.color } : {}">{{ group.label }}</div>
      <div class="shop-grid">
        <div
          v-for="item in group.items"
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
      </div>
    </div>
    <div v-if="!groupedByRarity.length" class="shop-empty">Nothing here yet.</div>
  </div>
</template>

<style lang="scss" src="./ShopPage.scss" scoped></style>
