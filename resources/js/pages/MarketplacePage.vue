<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import api from '../api/client';
import { RARITY_COLORS, formatStats } from '../rarity';

const route = useRoute();
const tab = ref(route.query.tab === 'sell' ? 'sell' : 'browse');
const listings = ref([]);
const mine = ref([]);
const inventory = ref([]);
const loading = ref(false);
const message = ref('');

// Gear is listed one piece at a time (each copy has its own durability) — mirrors
// CraftingController::GEAR_TYPES / MarketplaceController::GEAR_TYPES.
const GEAR_TYPES = ['weapon', 'armor', 'shield', 'pickaxe', 'axe', 'sickle', 'hammer', 'quiver'];

const browseQuery = ref('');
const filteredListings = computed(() => {
  const q = browseQuery.value.trim().toLowerCase();
  return !q ? listings.value : listings.value.filter((l) => l.item.name.toLowerCase().includes(q));
});

const listForm = ref(null); // the inventory row currently being listed, or null
const listQty = ref(1);
const listPrice = ref(1);

function showMessage(text, tone = 'error') {
  message.value = { text, tone };
  setTimeout(() => { if (message.value?.text === text) message.value = null; }, 4000);
}

async function loadBrowse() {
  const { data } = await api.get('/market');
  listings.value = data.listings;
}

async function loadMine() {
  const { data } = await api.get('/market/mine');
  mine.value = data.listings;
}

async function loadInventory() {
  const { data } = await api.get('/inventory');
  // Equipped gear can't be listed (unequip first), and this is a sell-what-you-have picker, not a
  // full inventory view, so filter down to what's actually listable.
  inventory.value = data.inventory.filter((row) => !row.equipped);
}

async function load() {
  loading.value = true;
  try {
    await Promise.all([loadBrowse(), loadMine(), loadInventory()]);
  } finally {
    loading.value = false;
  }
}

function openListForm(row) {
  listForm.value = row;
  listQty.value = GEAR_TYPES.includes(row.item.type) ? 1 : row.qty;
  listPrice.value = row.item.price_gold || 10;
}

async function submitListing() {
  try {
    await api.post('/market', {
      inventory_id: listForm.value.id,
      qty: listQty.value,
      price_gold: listPrice.value,
    });
    showMessage(`Listed ${listForm.value.item.name}.`, 'success');
    listForm.value = null;
    await Promise.all([loadMine(), loadInventory()]);
  } catch (e) {
    showMessage(e.response?.data?.message || 'Could not list that item.');
  }
}

async function buy(listing) {
  try {
    await api.post(`/market/${listing.id}/buy`);
    showMessage(`Bought ${listing.item.name} for ${listing.price_gold}g.`, 'success');
    await Promise.all([loadBrowse(), loadInventory()]);
  } catch (e) {
    showMessage(e.response?.data?.message || 'Could not buy that listing.');
  }
}

async function cancel(listing) {
  try {
    const { data } = await api.post(`/market/${listing.id}/cancel`);
    const feeText = data.cancel_fee > 0 ? ` (10% cancel fee: -${data.cancel_fee}g)` : '';
    showMessage(`Cancelled — ${listing.item.name} returned to your bag.${feeText}`, 'success');
    await Promise.all([loadMine(), loadInventory()]);
  } catch (e) {
    showMessage(e.response?.data?.message || 'Could not cancel that listing.');
  }
}

function isGear(item) {
  return GEAR_TYPES.includes(item.type);
}

function timeLeft(isoString) {
  const seconds = Math.round((new Date(isoString).getTime() - Date.now()) / 1000);
  if (seconds <= 0) return 'expired';
  if (seconds < 3600) return `${Math.round(seconds / 60)}m left`;
  if (seconds < 86400) return `${Math.round(seconds / 3600)}h left`;
  return `${Math.round(seconds / 86400)}d left`;
}

const activeMine = computed(() => mine.value.filter((l) => l.status === 'active'));
const historyMine = computed(() => mine.value.filter((l) => l.status !== 'active'));

onMounted(async () => {
  await load();
  // Deep-link from Inventory's "Sell on Market" shortcut (for gear the character can't equip) —
  // jumps straight to that specific row's listing form instead of leaving the player to find it
  // again in the sell picker.
  const listItemId = Number(route.query.list_item);
  if (listItemId) {
    const row = inventory.value.find((r) => r.id === listItemId);
    if (row) openListForm(row);
  }
});
</script>

<template>
  <div>
    <div class="market-header">
      <div class="market-header__icon">🏪</div>
      <h1 class="ox market-title">Marketplace</h1>
      <p class="market-header__subtitle">Buy and sell gear and materials with other players.</p>
    </div>

    <div class="market-tabs">
      <button class="market-tab" :class="{ 'market-tab--active': tab === 'browse' }" @click="tab = 'browse'">Browse</button>
      <button class="market-tab" :class="{ 'market-tab--active': tab === 'sell' }" @click="tab = 'sell'">Sell an item</button>
      <button class="market-tab" :class="{ 'market-tab--active': tab === 'mine' }" @click="tab = 'mine'">
        My listings
        <span v-if="activeMine.length" class="market-tab__badge">{{ activeMine.length }}</span>
      </button>
    </div>

    <p v-if="message" class="market-message" :class="`market-message--${message.tone}`">{{ message.text }}</p>

    <!-- BROWSE -->
    <input
      v-if="tab === 'browse'"
      v-model="browseQuery"
      type="text"
      placeholder="🔍 Search listings…"
      class="market-search"
    />
    <div v-if="tab === 'browse' && browseQuery && !filteredListings.length" class="market-empty">No listings match "{{ browseQuery }}".</div>
    <div v-if="tab === 'browse'" class="market-grid">
      <div v-for="l in filteredListings" :key="l.id" class="market-card">
        <div class="market-card__head">
          <span class="market-card__glyph">{{ l.item.glyph }}</span>
          <span class="ox market-card__name" :style="{ color: RARITY_COLORS[l.item.rarity] }">{{ l.item.name }}</span>
          <span v-if="l.item.roll_pct != null" class="roll-chip" :class="{ 'is-good': l.item.roll_pct > 0, 'is-bad': l.item.roll_pct < 0 }">{{ l.item.roll_pct > 0 ? '+' : '' }}{{ l.item.roll_pct }}% roll</span>
          <span v-if="l.qty > 1" class="market-card__qty">×{{ l.qty }}</span>
        </div>
        <div v-if="formatStats(l.item.stat_json).length" class="market-card__stats">
          <span v-for="stat in formatStats(l.item.stat_json)" :key="stat" class="market-card__stat">{{ stat }}</span>
        </div>
        <div v-if="isGear(l.item) && l.durability_max" class="market-card__durability">
          Durability {{ l.durability }}/{{ l.durability_max }} ({{ Math.round((l.durability / l.durability_max) * 100) }}%)
        </div>
        <div class="market-card__foot">
          <div class="market-card__seller">Sold by {{ l.seller_name }} · {{ timeLeft(l.expires_at) }}</div>
          <button class="market-card__buy-btn" @click="buy(l)">
            <span class="ox">🪙 {{ l.price_gold }}</span>
          </button>
        </div>
      </div>
      <div v-if="!loading && !listings.length" class="market-empty">No listings right now — check back later, or list something yourself.</div>
    </div>

    <!-- SELL -->
    <div v-else-if="tab === 'sell'" class="market-sell">
      <div v-if="!listForm" class="market-grid">
        <div v-for="row in inventory" :key="row.id" class="market-card market-card--pickable" @click="openListForm(row)">
          <div class="market-card__head">
            <span class="market-card__glyph">{{ row.item.glyph }}</span>
            <span class="ox market-card__name" :style="{ color: RARITY_COLORS[row.item.rarity] }">{{ row.item.name }}</span>
            <span v-if="row.qty > 1" class="market-card__qty">×{{ row.qty }}</span>
          </div>
          <div v-if="formatStats(row.item.stat_json).length" class="market-card__stats">
            <span v-for="stat in formatStats(row.item.stat_json)" :key="stat" class="market-card__stat">{{ stat }}</span>
          </div>
        </div>
        <div v-if="!inventory.length" class="market-empty">Nothing listable in your bag — unequip gear or gather/craft something first.</div>
      </div>

      <div v-else class="market-list-form">
        <div class="market-list-form__item">
          <span class="market-card__glyph">{{ listForm.item.glyph }}</span>
          <span class="ox">{{ listForm.item.name }}</span>
        </div>
        <label class="market-list-form__field">
          Quantity
          <input
            type="number"
            min="1"
            :max="isGear(listForm.item) ? 1 : listForm.qty"
            :disabled="isGear(listForm.item)"
            v-model.number="listQty"
          />
        </label>
        <label class="market-list-form__field">
          Price (gold)
          <input type="number" min="1" v-model.number="listPrice" />
        </label>
        <div class="market-list-form__actions">
          <button class="market-list-form__cancel" @click="listForm = null">Back</button>
          <button class="market-list-form__submit" @click="submitListing">List for 🪙{{ listPrice }}</button>
        </div>
      </div>
    </div>

    <!-- MINE -->
    <div v-else class="market-mine">
      <h3 class="ox market-mine__section-title">Active</h3>
      <div class="market-grid">
        <div v-for="l in activeMine" :key="l.id" class="market-card">
          <div class="market-card__head">
            <span class="market-card__glyph">{{ l.item.glyph }}</span>
            <span class="ox market-card__name" :style="{ color: RARITY_COLORS[l.item.rarity] }">{{ l.item.name }}</span>
            <span v-if="l.qty > 1" class="market-card__qty">×{{ l.qty }}</span>
          </div>
          <div class="market-card__foot">
            <div class="market-card__seller">🪙 {{ l.price_gold }} · {{ timeLeft(l.expires_at) }}</div>
            <button class="market-card__cancel-btn" :title="`Cancelling costs a 10% fee (${Math.ceil(l.price_gold * 0.1)}g)`" @click="cancel(l)">Cancel</button>
          </div>
        </div>
        <div v-if="!activeMine.length" class="market-empty">You have no active listings.</div>
      </div>

      <h3 class="ox market-mine__section-title">History</h3>
      <div class="market-history">
        <div v-for="l in historyMine" :key="l.id" class="market-history__row">
          <span class="market-history__glyph">{{ l.item.glyph }}</span>
          <span class="ox market-history__name">{{ l.item.name }}</span>
          <span class="market-history__status" :class="`market-history__status--${l.status}`">{{ l.status }}</span>
          <span class="market-history__price">🪙 {{ l.price_gold }}</span>
        </div>
        <div v-if="!historyMine.length" class="market-empty">No past listings yet.</div>
      </div>
    </div>
  </div>
</template>

<style lang="scss" src="./MarketplacePage.scss" scoped></style>
