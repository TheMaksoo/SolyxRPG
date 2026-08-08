<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { useCharacterStore } from '../stores/character';
import api from '../api/client';
import { RARITY_COLORS, RARITY_LABELS, formatStats } from '../rarity';

const route = useRoute();
const characterStore = useCharacterStore();
const tab = ref(route.query.tab === 'sell' ? 'sell' : 'browse');

// --- browse state ---
const listings = ref([]);
const total = ref(0);
const page = ref(1);
const lastPage = ref(1);
const browseLoading = ref(false);

const filters = ref({
  search: '',
  seller: '',
  item_type: 'all',
  rarity: 'all',
  currency: 'all',
  sort: 'newest',
});

// --- sell / mine state ---
const mine = ref([]);
const listingCap = ref(10);
const cancelFeePct = ref(10);
const inventory = ref([]);
const loading = ref(false);
const message = ref(null);

// Gear is listed one piece at a time (each copy has its own durability) — mirrors
// CraftingController::GEAR_TYPES / MarketplaceController::GEAR_TYPES.
const GEAR_TYPES = ['weapon', 'armor', 'shield', 'pickaxe', 'axe', 'sickle', 'hammer', 'quiver'];
const GEM_RARITIES = ['legendary', 'mythic'];

const ITEM_TYPES = [
  { value: 'all', label: 'All types' },
  { value: 'weapon', label: 'Weapons' },
  { value: 'armor', label: 'Armor' },
  { value: 'shield', label: 'Shields' },
  { value: 'pickaxe', label: 'Pickaxes' },
  { value: 'axe', label: 'Axes' },
  { value: 'sickle', label: 'Sickles' },
  { value: 'hammer', label: 'Hammers' },
  { value: 'quiver', label: 'Quivers' },
  { value: 'material', label: 'Materials' },
  { value: 'consumable', label: 'Consumables' },
  { value: 'gem', label: 'Gems' },
];

const RARITIES = [
  { value: 'all', label: 'All rarities' },
  { value: 'common', label: 'Common' },
  { value: 'rare', label: 'Rare' },
  { value: 'epic', label: 'Epic' },
  { value: 'legendary', label: 'Legendary' },
  { value: 'mythic', label: 'Mythic' },
];

const SORTS = [
  { value: 'newest', label: 'Newest first' },
  { value: 'oldest', label: 'Oldest first' },
  { value: 'price_asc', label: 'Price: low → high' },
  { value: 'price_desc', label: 'Price: high → low' },
];

const CURRENCIES = [
  { value: 'all', label: 'All' },
  { value: 'gold', label: '🪙 Gold' },
  { value: 'gems', label: '◆ Gems' },
];

const listForm = ref(null);
const listQty = ref(1);
const listPrice = ref(1);
const listGemPrice = ref(null);
const listUseGems = ref(false);

function showMessage(text, tone = 'error') {
  message.value = { text, tone };
  setTimeout(() => { if (message.value?.text === text) message.value = null; }, 5000);
}

// Debounce timer for search/seller inputs
let searchTimer = null;
function onSearchInput() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => { page.value = 1; loadBrowse(); }, 350);
}

function onFilterChange() {
  page.value = 1;
  loadBrowse();
}

async function loadBrowse() {
  browseLoading.value = true;
  try {
    const params = {
      page: page.value,
      sort: filters.value.sort,
    };
    if (filters.value.search) params.search = filters.value.search;
    if (filters.value.seller) params.seller = filters.value.seller;
    if (filters.value.item_type !== 'all') params.item_type = filters.value.item_type;
    if (filters.value.rarity !== 'all') params.rarity = filters.value.rarity;
    if (filters.value.currency !== 'all') params.currency = filters.value.currency;

    const { data } = await api.get('/market', { params });
    listings.value = data.listings;
    total.value = data.total;
    lastPage.value = data.last_page;
  } finally {
    browseLoading.value = false;
  }
}

async function loadMine() {
  const { data } = await api.get('/market/mine');
  mine.value = data.listings;
  listingCap.value = data.listing_cap;
  cancelFeePct.value = data.cancel_fee_pct ?? 10;
}

async function loadInventory() {
  const { data } = await api.get('/inventory');
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
  listGemPrice.value = null;
  listUseGems.value = false;
}

async function submitListing() {
  try {
    const payload = {
      inventory_id: listForm.value.id,
      qty: listQty.value,
      price_gold: listPrice.value,
    };
    if (listUseGems.value && listGemPrice.value) {
      payload.price_gems = listGemPrice.value;
    }
    await api.post('/market', payload);
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
    const price = listing.price_gems != null
      ? `${listing.price_gems}◆`
      : `${listing.price_gold}g`;
    showMessage(`Bought ${listing.item.name} for ${price}.`, 'success');
    if (listing.price_gems != null) {
      await characterStore.fetch();
    }
    await Promise.all([loadBrowse(), loadInventory()]);
  } catch (e) {
    showMessage(e.response?.data?.message || 'Could not buy that listing.');
  }
}

async function cancel(listing) {
  try {
    const { data } = await api.post(`/market/${listing.id}/cancel`);
    const feeUnit = data.cancel_fee_currency === 'gems' ? '◆' : 'g';
    const feeText = data.cancel_fee > 0 ? ` (${cancelFeePct.value}% cancel fee: -${data.cancel_fee}${feeUnit})` : '';
    showMessage(`Cancelled — ${listing.item.name} returned to your bag.${feeText}`, 'success');
    if (listing.price_gems != null) {
      await characterStore.fetch();
    }
    await Promise.all([loadMine(), loadInventory()]);
  } catch (e) {
    showMessage(e.response?.data?.message || 'Could not cancel that listing.');
  }
}

function cancelFeeTitle(listing) {
  if (listing.price_gems != null) {
    return `Cancel fee: ${cancelFeePct.value}% (${Math.ceil(listing.price_gems * cancelFeePct.value / 100)}◆)`;
  }

  return `Cancel fee: ${cancelFeePct.value}% (${Math.ceil(listing.price_gold * cancelFeePct.value / 100)}g)`;
}

function isGear(item) { return GEAR_TYPES.includes(item.type); }
function canListForGems(item) { return GEM_RARITIES.includes(item.rarity); }

function timeLeft(isoString) {
  const seconds = Math.round((new Date(isoString).getTime() - Date.now()) / 1000);
  if (seconds <= 0) return 'expired';
  if (seconds < 3600) return `${Math.round(seconds / 60)}m left`;
  if (seconds < 86400) return `${Math.round(seconds / 3600)}h left`;
  return `${Math.round(seconds / 86400)}d left`;
}

const activeMine = computed(() => mine.value.filter((l) => l.status === 'active'));
const historyMine = computed(() => mine.value.filter((l) => l.status !== 'active'));

function changePage(p) {
  if (p < 1 || p > lastPage.value) return;
  page.value = p;
  loadBrowse();
}

function resetFilters() {
  filters.value = { search: '', seller: '', item_type: 'all', rarity: 'all', currency: 'all', sort: 'newest' };
  page.value = 1;
  loadBrowse();
}

onMounted(async () => {
  await load();
  const listItemId = Number(route.query.list_item);
  if (listItemId) {
    const row = inventory.value.find((r) => r.id === listItemId);
    if (row) openListForm(row);
  }
});
</script>

<template>
  <div class="mp">
    <!-- Header -->
    <div class="mp-header">
      <div class="mp-header__icon">🏪</div>
      <div class="mp-header__text">
        <h1 class="ox mp-header__title">Marketplace</h1>
        <p class="mp-header__sub">Buy and sell gear and materials with other players.</p>
      </div>
      <div v-if="total && tab === 'browse'" class="mp-header__count">{{ total.toLocaleString() }} listing{{ total !== 1 ? 's' : '' }}</div>
    </div>

    <!-- Tabs -->
    <div class="mp-tabs">
      <button class="mp-tab" :class="{ 'mp-tab--active': tab === 'browse' }" @click="tab = 'browse'">Browse</button>
      <button class="mp-tab" :class="{ 'mp-tab--active': tab === 'sell' }" @click="tab = 'sell'">Sell</button>
      <button class="mp-tab" :class="{ 'mp-tab--active': tab === 'mine' }" @click="tab = 'mine'">
        My Listings
        <span v-if="activeMine.length" class="mp-tab__badge">{{ activeMine.length }}/{{ listingCap }}</span>
      </button>
    </div>

    <!-- Toast message -->
    <transition name="mp-fade">
      <div v-if="message" class="mp-toast" :class="`mp-toast--${message.tone}`">{{ message.text }}</div>
    </transition>

    <!-- ===================== BROWSE ===================== -->
    <template v-if="tab === 'browse'">
      <!-- Filter bar -->
      <div class="mp-filters">
        <div class="mp-filters__row">
          <div class="mp-filters__search-wrap">
            <span class="mp-filters__search-icon">🔍</span>
            <input
              v-model="filters.search"
              type="text"
              placeholder="Search items…"
              class="mp-filters__search"
              @input="onSearchInput"
            />
          </div>
          <div class="mp-filters__search-wrap">
            <span class="mp-filters__search-icon">👤</span>
            <input
              v-model="filters.seller"
              type="text"
              placeholder="Seller name…"
              class="mp-filters__search"
              @input="onSearchInput"
            />
          </div>
        </div>
        <div class="mp-filters__row">
          <select v-model="filters.item_type" class="mp-select" @change="onFilterChange">
            <option v-for="t in ITEM_TYPES" :key="t.value" :value="t.value">{{ t.label }}</option>
          </select>
          <select v-model="filters.rarity" class="mp-select" @change="onFilterChange">
            <option v-for="r in RARITIES" :key="r.value" :value="r.value">{{ r.label }}</option>
          </select>
          <select v-model="filters.currency" class="mp-select" @change="onFilterChange">
            <option v-for="c in CURRENCIES" :key="c.value" :value="c.value">{{ c.label }}</option>
          </select>
          <select v-model="filters.sort" class="mp-select" @change="onFilterChange">
            <option v-for="s in SORTS" :key="s.value" :value="s.value">{{ s.label }}</option>
          </select>
          <button class="mp-filters__reset" title="Reset filters" @click="resetFilters">✕ Reset</button>
        </div>
      </div>

      <!-- Loading skeleton -->
      <div v-if="browseLoading" class="mp-grid">
        <div v-for="i in 8" :key="i" class="mp-card mp-card--skeleton"></div>
      </div>

      <!-- Listings grid -->
      <div v-else-if="listings.length" class="mp-grid">
        <div v-for="l in listings" :key="l.id" class="mp-card">
          <div class="mp-card__rarity-bar" :style="{ background: RARITY_COLORS[l.item.rarity] }"></div>
          <div class="mp-card__body">
            <div class="mp-card__head">
              <span class="mp-card__glyph">{{ l.item.glyph }}</span>
              <div class="mp-card__meta">
                <span class="ox mp-card__name" :style="{ color: RARITY_COLORS[l.item.rarity] }">{{ l.item.name }}</span>
                <span class="mp-card__rarity-label" :style="{ color: RARITY_COLORS[l.item.rarity] }">{{ RARITY_LABELS[l.item.rarity] }}</span>
              </div>
              <div class="mp-card__chips">
                <span v-if="l.item.roll_pct != null" class="mp-chip" :class="{ 'mp-chip--good': l.item.roll_pct > 0, 'mp-chip--bad': l.item.roll_pct < 0 }">
                  {{ l.item.roll_pct > 0 ? '+' : '' }}{{ l.item.roll_pct }}%
                </span>
                <span v-if="l.qty > 1" class="mp-chip mp-chip--qty">×{{ l.qty }}</span>
              </div>
            </div>
            <div v-if="formatStats(l.item.stat_json).length" class="mp-card__stats">
              <span v-for="stat in formatStats(l.item.stat_json)" :key="stat" class="mp-stat">{{ stat }}</span>
            </div>
            <div v-if="isGear(l.item) && l.durability_max" class="mp-card__durability">
              <span class="mp-durability-bar" :style="{ '--pct': Math.round((l.durability / l.durability_max) * 100) + '%' }"></span>
              {{ l.durability }}/{{ l.durability_max }} durability
            </div>
            <div class="mp-card__foot">
              <div class="mp-card__seller">
                <span class="mp-card__seller-name">{{ l.seller_name }}</span>
                <span class="mp-card__ttl">· {{ timeLeft(l.expires_at) }}</span>
              </div>
              <button
                v-if="l.price_gems != null"
                class="mp-buy-btn mp-buy-btn--gem"
                @click="buy(l)"
              >◆ {{ l.price_gems }}</button>
              <button
                v-else
                class="mp-buy-btn mp-buy-btn--gold"
                @click="buy(l)"
              >🪙 {{ l.price_gold }}</button>
            </div>
          </div>
        </div>
      </div>
      <div v-else class="mp-empty">
        <span class="mp-empty__icon">🏷️</span>
        <p>No listings match your filters — try widening your search or <button class="mp-link" @click="resetFilters">reset filters</button>.</p>
      </div>

      <!-- Pagination -->
      <div v-if="lastPage > 1" class="mp-pagination">
        <button class="mp-page-btn" :disabled="page === 1" @click="changePage(page - 1)">‹ Prev</button>
        <template v-for="p in lastPage" :key="p">
          <button
            v-if="p === 1 || p === lastPage || Math.abs(p - page) <= 2"
            class="mp-page-btn"
            :class="{ 'mp-page-btn--active': p === page }"
            @click="changePage(p)"
          >{{ p }}</button>
          <span v-else-if="p === 2 || p === lastPage - 1" class="mp-page-ellipsis">…</span>
        </template>
        <button class="mp-page-btn" :disabled="page === lastPage" @click="changePage(page + 1)">Next ›</button>
      </div>
    </template>

    <!-- ===================== SELL ===================== -->
    <template v-else-if="tab === 'sell'">
      <div v-if="!listForm">
        <div v-if="inventory.length" class="mp-grid">
          <div
            v-for="row in inventory"
            :key="row.id"
            class="mp-card mp-card--pickable"
            @click="openListForm(row)"
          >
            <div class="mp-card__rarity-bar" :style="{ background: RARITY_COLORS[row.item.rarity] }"></div>
            <div class="mp-card__body">
              <div class="mp-card__head">
                <span class="mp-card__glyph">{{ row.item.glyph }}</span>
                <div class="mp-card__meta">
                  <span class="ox mp-card__name" :style="{ color: RARITY_COLORS[row.item.rarity] }">{{ row.item.name }}</span>
                  <span class="mp-card__rarity-label" :style="{ color: RARITY_COLORS[row.item.rarity] }">{{ RARITY_LABELS[row.item.rarity] }}</span>
                </div>
                <span v-if="row.qty > 1" class="mp-chip mp-chip--qty">×{{ row.qty }}</span>
              </div>
              <div v-if="formatStats(row.item.stat_json).length" class="mp-card__stats">
                <span v-for="stat in formatStats(row.item.stat_json)" :key="stat" class="mp-stat">{{ stat }}</span>
              </div>
              <div v-if="canListForGems(row.item)" class="mp-gem-badge">◆ Gem listing available</div>
            </div>
          </div>
        </div>
        <div v-else class="mp-empty">
          <span class="mp-empty__icon">🎒</span>
          <p>Nothing listable in your bag — unequip gear or gather/craft something first.</p>
        </div>
      </div>

      <!-- Listing form -->
      <div v-else class="mp-list-form">
        <button class="mp-list-form__back" @click="listForm = null">← Back</button>
        <div class="mp-list-form__item">
          <span class="mp-list-form__glyph">{{ listForm.item.glyph }}</span>
          <div>
            <div class="ox mp-list-form__name" :style="{ color: RARITY_COLORS[listForm.item.rarity] }">{{ listForm.item.name }}</div>
            <div class="mp-list-form__rarity" :style="{ color: RARITY_COLORS[listForm.item.rarity] }">{{ RARITY_LABELS[listForm.item.rarity] }}</div>
          </div>
        </div>

        <label class="mp-field">
          <span class="mp-field__label">Quantity</span>
          <input
            type="number" min="1"
            :max="isGear(listForm.item) ? 1 : listForm.qty"
            :disabled="isGear(listForm.item)"
            v-model.number="listQty"
            class="mp-field__input"
          />
        </label>

        <label class="mp-field">
          <span class="mp-field__label">Price (gold 🪙)</span>
          <input type="number" min="1" v-model.number="listPrice" class="mp-field__input" />
        </label>

        <template v-if="canListForGems(listForm.item)">
          <label class="mp-gem-toggle">
            <input type="checkbox" v-model="listUseGems" />
            <span class="mp-gem-toggle__label">◆ Also list for gems <span class="mp-gem-toggle__hint">(legendary/mythic only)</span></span>
          </label>
          <label v-if="listUseGems" class="mp-field">
            <span class="mp-field__label">Gem price ◆</span>
            <input type="number" min="1" v-model.number="listGemPrice" class="mp-field__input" />
          </label>
        </template>

        <div class="mp-list-form__actions">
          <button class="mp-list-form__submit" @click="submitListing">
            List for 🪙{{ listPrice }}<template v-if="listUseGems && listGemPrice"> · ◆{{ listGemPrice }}</template>
          </button>
        </div>
      </div>
    </template>

    <!-- ===================== MINE ===================== -->
    <template v-else>
      <div class="mp-mine">
        <div class="mp-mine__head">
          <h3 class="ox mp-mine__title">Active Listings</h3>
          <span class="mp-mine__cap">{{ activeMine.length }} / {{ listingCap }}</span>
        </div>

        <div v-if="activeMine.length" class="mp-grid">
          <div v-for="l in activeMine" :key="l.id" class="mp-card">
            <div class="mp-card__rarity-bar" :style="{ background: RARITY_COLORS[l.item.rarity] }"></div>
            <div class="mp-card__body">
              <div class="mp-card__head">
                <span class="mp-card__glyph">{{ l.item.glyph }}</span>
                <div class="mp-card__meta">
                  <span class="ox mp-card__name" :style="{ color: RARITY_COLORS[l.item.rarity] }">{{ l.item.name }}</span>
                  <span class="mp-card__rarity-label" :style="{ color: RARITY_COLORS[l.item.rarity] }">{{ RARITY_LABELS[l.item.rarity] }}</span>
                </div>
                <span v-if="l.qty > 1" class="mp-chip mp-chip--qty">×{{ l.qty }}</span>
              </div>
              <div class="mp-card__foot">
                <div class="mp-card__seller">
                  <span v-if="l.price_gems != null" class="mp-card__price-gem">◆ {{ l.price_gems }}</span>
                  <span v-else class="mp-card__price-gold">🪙 {{ l.price_gold }}</span>
                  <span class="mp-card__ttl">· {{ timeLeft(l.expires_at) }}</span>
                </div>
                <button
                  class="mp-cancel-btn"
                  :title="cancelFeeTitle(l)"
                  @click="cancel(l)"
                >Cancel</button>
              </div>
            </div>
          </div>
        </div>
        <div v-else class="mp-empty">
          <span class="mp-empty__icon">📋</span>
          <p>You have no active listings.</p>
        </div>

        <div class="mp-mine__head" style="margin-top: 28px;">
          <h3 class="ox mp-mine__title">History</h3>
        </div>
        <div v-if="historyMine.length" class="mp-history">
          <div v-for="l in historyMine" :key="l.id" class="mp-history__row">
            <span class="mp-history__glyph">{{ l.item.glyph }}</span>
            <span class="ox mp-history__name">{{ l.item.name }}</span>
            <span class="mp-history__status" :class="`mp-history__status--${l.status}`">{{ l.status }}</span>
            <span class="mp-history__price">
              <template v-if="l.price_gems != null">◆ {{ l.price_gems }}</template>
              <template v-else>🪙 {{ l.price_gold }}</template>
            </span>
          </div>
        </div>
        <div v-else class="mp-empty"><p>No past listings yet.</p></div>
      </div>
    </template>
  </div>
</template>

<style lang="scss" src="./MarketplacePage.scss" scoped></style>
