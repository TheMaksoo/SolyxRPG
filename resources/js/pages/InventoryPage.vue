<script setup>
import { ref, onMounted, computed, reactive, watch } from 'vue';
import api from '../api/client';
import { useCharacterStore } from '../stores/character';
import { RARITY_COLORS, RARITY_LABELS, formatStats } from '../rarity';
import { NAV } from '../navigation';
import Toast from '../components/Toast.vue';

const store = useCharacterStore();

// Loot crates are opened on the Crates tab (see CratesPage.vue), not Forge — mirrors NAV's own
// unlockLevel for that tab so a still-locked character sees the real reason they can't open one yet
// instead of a link that just bounces them to LevelRequiredPage.
const crateUnlockLevel = computed(() => NAV.find((n) => n.path === '/crates')?.unlockLevel ?? null);
const crateTabLocked = computed(() => !!crateUnlockLevel.value && (store.character?.level ?? 0) < crateUnlockLevel.value);
const inventory = ref([]);
const loading = ref(false);
const message = ref('');
let errorToastTimer = null;
watch(message, (val) => {
  clearTimeout(errorToastTimer);
  if (val) errorToastTimer = setTimeout(() => { message.value = ''; }, 5000);
});
const selectedPack = reactive({});
const scrapTarget = ref(null);
const scrapResult = ref(null);

// 'quiver' is the ranger's/rogue's 2nd combat slot (alongside their bow/dagger); 'shield' is the
// warrior's (alongside their chest armor); 'trinket' is a 3rd, universal accessory slot every class
// gets — unlike shield/quiver it carries no `classes` filter below, so it always shows. See
// ItemSeeder/InventoryController::equip() for the class-restriction rules on each type.
const EQUIPPABLE_TYPES = ['weapon', 'armor', 'shield', 'quiver', 'trinket', 'pickaxe', 'axe', 'sickle', 'hammer'];
const SLOT_DEFS = [
  { type: 'weapon', label: 'Weapon', glyph: '⚔' },
  { type: 'armor', label: 'Armor', glyph: '🥋' },
  { type: 'shield', label: 'Shield', glyph: '🛡', classes: ['warrior'] },
  { type: 'quiver', label: 'Quiver', glyph: '🎯', classes: ['ranger', 'rogue'] },
  { type: 'trinket', label: 'Trinket', glyph: '📿' },
  { type: 'pickaxe', label: 'Pickaxe', glyph: '⛏' },
  { type: 'axe', label: 'Axe', glyph: '🪓' },
  { type: 'sickle', label: 'Sickle', glyph: '🔪' },
  { type: 'hammer', label: 'Hammer', glyph: '🔨' },
];
const DOLL_LEFT_TYPES = new Set(['weapon', 'armor', 'shield', 'quiver', 'trinket']);

// Shown in the detail panel when the selected slot is empty.
const EMPTY_HINTS = {
  shield: "Your class's 2nd defensive slot — craft or buy one at the Shop or Forge.",
  quiver: "Your class's 2nd combat slot — craft or buy one at the Shop or Forge.",
  trinket: 'Any class can wear a Trinket — craft or buy one at the Shop or Forge.',
};

const equipped = computed(() => inventory.value.filter((i) => i.equipped));

const bagQuery = ref('');
const bagFilter = ref('all');
const repairPacks = computed(() => inventory.value.filter((i) => i.item.type === 'repair_pack'));

// Same category scheme Shop and Crafting already use, in the same order — what your class can actually
// equip (weapons/armor/quiver/trinkets/tools) surfaces first, then things you consume, then everything else.
const BAG_SECTIONS = [
  { key: 'weapon', label: 'Weapons', glyph: '⚔', types: ['weapon'] },
  { key: 'armor', label: 'Armor', glyph: '🛡', types: ['armor', 'shield'] },
  { key: 'quiver', label: 'Quivers', glyph: '🎯', types: ['quiver'] },
  { key: 'trinket', label: 'Trinkets', glyph: '📿', types: ['trinket'] },
  { key: 'tools', label: 'Tools', glyph: '⛏', types: ['pickaxe', 'axe', 'sickle', 'hammer'] },
  { key: 'consumable', label: 'Consumables', glyph: '🧪', types: ['consumable', 'pet_food'] },
  { key: 'repair_pack', label: 'Repair Packs', glyph: '🧰', types: ['repair_pack'] },
  { key: 'cosmetic', label: 'Cosmetics', glyph: '👑', types: ['cosmetic'] },
  { key: 'crate', label: 'Loot Crates', glyph: '📦', types: ['loot_crate'] },
  { key: 'material', label: 'Materials', glyph: '🪨', types: ['material', 'reforge_material'] },
];
const GEAR_SECTIONS = new Set(['weapon', 'armor', 'quiver', 'trinket', 'tools']);

const unequippedInventory = computed(() => inventory.value.filter((i) => !i.equipped));

// The bag has no slot cap in this game (unlike the mockup's fixed "60 slots") — the count pill below
// is informational only, never a fabricated capacity limit.
const bagFilters = computed(() => {
  const counts = {};
  for (const row of unequippedInventory.value) {
    const section = BAG_SECTIONS.find((s) => s.types.includes(row.item.type));
    if (section) counts[section.key] = (counts[section.key] ?? 0) + 1;
  }
  return [
    { key: 'all', label: 'All', glyph: '🎒', count: unequippedInventory.value.length },
    ...BAG_SECTIONS.filter((s) => counts[s.key]).map((s) => ({
      ...s,
      // The 'quiver' filter chip is flavored per class the same way the equip slot already is — a
      // rogue sees "Knife Holders", not the ranger-flavored "Quivers" label. Falls back to the
      // default label for a warrior/mage who merely owns one to resell (see isMyClass()).
      ...(s.key === 'quiver' && QUIVER_LABELS[store.character?.base_class] ? { label: QUIVER_LABELS[store.character.base_class].label + 's' } : null),
      count: counts[s.key],
    })),
  ];
});

const visibleBagRows = computed(() => {
  const q = bagQuery.value.trim().toLowerCase();
  const activeSection = BAG_SECTIONS.find((s) => s.key === bagFilter.value);
  const rows = unequippedInventory.value
    .filter((i) => !q || i.item.name.toLowerCase().includes(q) || i.item.type.toLowerCase().includes(q))
    .filter((i) => !activeSection || activeSection.types.includes(i.item.type));

  // Gear your class can actually wear sorts ahead of gear made for another class — that other-class
  // gear still shows up (for repairing or listing on the Marketplace), it just sinks to the back.
  return [...rows].sort((a, b) => Number(isMyClass(b.item)) - Number(isMyClass(a.item)));
});

// The 'quiver' slot is shared but flavored per class — a ranger's arrows vs. a rogue's throwing
// knives (see ItemSeeder) — so its empty-slot label/glyph switch to match whoever's viewing it.
const QUIVER_LABELS = {
  rogue: { label: 'Knife Holder', glyph: '🔪' },
  ranger: { label: 'Quiver', glyph: '🎯' },
};

// Mages have no shield/quiver-equivalent 2nd combat slot of their own, so their Trinket line gets a
// 2nd slot instead — matches InventoryController::equipSlotCapacity() exactly. Each slot is pinned to
// its own Inventory::slot value ('trinket_1'/'trinket_2') so the two stay stable across reloads instead
// of being an arbitrary, order-dependent pair.
function trinketCapacity() {
  return store.character?.base_class === 'mage' ? 2 : 1;
}

const slots = computed(() => {
  const capacity = trinketCapacity();

  return SLOT_DEFS.filter((slot) => !slot.classes || slot.classes.includes(store.character?.base_class))
    .flatMap((slot) => {
      if (slot.type !== 'trinket' || capacity <= 1) {
        return [{
          ...slot,
          key: slot.type,
          ...(slot.type === 'quiver' ? QUIVER_LABELS[store.character?.base_class] : null),
          row: equipped.value.find((row) => row.item.type === slot.type) ?? null,
        }];
      }

      return Array.from({ length: capacity }, (_, i) => {
        const slotKey = `trinket_${i + 1}`;
        return {
          ...slot,
          key: slotKey,
          slotKey,
          label: `${slot.label} ${i + 1}`,
          row: equipped.value.find((row) => row.item.type === 'trinket' && row.slot === slotKey) ?? null,
        };
      });
    });
});

const dollLeft = computed(() => slots.value.filter((s) => DOLL_LEFT_TYPES.has(s.type)));
const dollRight = computed(() => slots.value.filter((s) => !DOLL_LEFT_TYPES.has(s.type)));

function rarityChipStyle(rarity) {
  const color = RARITY_COLORS[rarity] || RARITY_COLORS.common;
  return { color, background: `${color}22` };
}

function rarityRailStyle(rarity) {
  return { background: RARITY_COLORS[rarity] || RARITY_COLORS.common };
}

/** Lower rarity = cheaper repair pack in this game's pricing, so rarity order doubles as a price order. */
const REPAIR_PACK_RARITY_ORDER = ['common', 'rare', 'epic', 'legendary', 'mythic'];
const cheapestRepairPack = computed(() => {
  if (!repairPacks.value.length) return null;
  return [...repairPacks.value].sort(
    (a, b) => REPAIR_PACK_RARITY_ORDER.indexOf(a.item.rarity) - REPAIR_PACK_RARITY_ORDER.indexOf(b.item.rarity)
  )[0];
});

// Display-only — mirrors ReforgeService::maxLevel()'s default; the actual reforge action now lives
// on the Forge tab (see ForgePage.vue).
const REFORGE_MAX_LEVEL = 5;

function isUsable(item) {
  return !!(item.stat_json?.hp_regen_pct_buff || item.stat_json?.mana_regen_pct_buff);
}

// Any class can craft/buy/sell another class's gear, but only that class can wear it — matches
// InventoryController::equip()'s server-side check. Quivers are shared between Ranger and Rogue;
// a null class_key (the universal Charm trinket line) is wearable by everyone.
function isMyClass(item) {
  if (!item.class_key) return true;
  if (item.type === 'quiver') return ['ranger', 'rogue'].includes(store.character?.base_class);
  return item.class_key === store.character?.base_class;
}

function hasDurability(row) {
  return row.durability_max !== null && row.durability_max !== undefined;
}

function durabilityPct(row) {
  return Math.max(0, Math.min(100, Math.round((row.durability / (row.durability_max || 1)) * 100)));
}

// ---- Selection: one shared detail panel driven by whichever slot or bag cell was last clicked ----
const selectedSlotKey = ref('weapon');
const selectedBagId = ref(null);

function selectSlot(slot) {
  selectedSlotKey.value = slot.key;
  selectedBagId.value = null;
}

function selectBagRow(row) {
  selectedBagId.value = row.id;
  selectedSlotKey.value = null;
}

const selectedBagRow = computed(() => inventory.value.find((r) => r.id === selectedBagId.value) ?? null);
const selectedSlot = computed(() => slots.value.find((s) => s.key === selectedSlotKey.value) ?? null);

const detail = computed(() => {
  if (selectedBagRow.value) {
    return { kind: 'bag', row: selectedBagRow.value, item: selectedBagRow.value.item, label: selectedBagRow.value.item.name };
  }
  if (selectedSlot.value) {
    return { kind: 'slot', slot: selectedSlot.value, row: selectedSlot.value.row, item: selectedSlot.value.row?.item ?? null, label: selectedSlot.value.row?.item.name ?? selectedSlot.value.label };
  }
  return null;
});

async function load() {
  const { data } = await api.get('/inventory');
  inventory.value = data.inventory;

  // Default every repairable row to the cheapest owned pack, so players don't have to pick one manually.
  const cheapestId = cheapestRepairPack.value?.item_id;
  if (cheapestId) {
    for (const row of inventory.value) {
      if (hasDurability(row) && row.durability < row.durability_max && !selectedPack[row.id]) {
        selectedPack[row.id] = cheapestId;
      }
    }
  }
}

async function equip(row) {
  if (!EQUIPPABLE_TYPES.includes(row.item.type)) return;
  loading.value = true;
  message.value = '';
  try {
    const { data } = await api.post('/inventory/equip', { item_id: row.item_id });
    inventory.value = data.inventory;
    // Select whichever slot the item actually landed in — for mage's 2 trinket slots the server (not
    // this page) decides which of the two was free, so look the equipped row back up by id rather than
    // assuming a single slot keyed just by type.
    const equippedRow = inventory.value.find((r) => r.item_id === row.item_id && r.equipped);
    const landedSlot = slots.value.find((s) => s.row?.id === equippedRow?.id);
    if (landedSlot) selectSlot(landedSlot);
  } catch (e) {
    message.value = e.response?.data?.message || 'Could not equip that item.';
  } finally {
    loading.value = false;
  }
}

async function use(row) {
  loading.value = true;
  message.value = '';
  try {
    const applied = await store.usePotion(row.item_id);
    message.value = `${row.item.name}: ${applied.join(', ')}`;
    await load();
  } catch (e) {
    message.value = e.response?.data?.message || 'Could not use that item.';
  } finally {
    loading.value = false;
  }
}

async function unequip(row) {
  loading.value = true;
  try {
    const { data } = await api.post('/inventory/unequip', { inventory_id: row.id });
    inventory.value = data.inventory;
  } finally {
    loading.value = false;
  }
}

const scrapQty = ref(1);

function askScrap(row) {
  scrapTarget.value = row;
  scrapQty.value = row.qty;
}

function cancelScrap() {
  scrapTarget.value = null;
}

async function confirmScrap() {
  const row = scrapTarget.value;
  if (!row) return;
  const qty = Math.max(1, Math.min(scrapQty.value, row.qty));
  scrapTarget.value = null;
  loading.value = true;
  message.value = '';
  scrapResult.value = null;
  try {
    const { data } = await api.post('/inventory/scrap', { inventory_id: row.id, qty });
    inventory.value = data.inventory;
    if (selectedBagId.value === row.id && qty >= row.qty) selectedBagId.value = null;
    scrapResult.value = {
      itemName: row.item.name,
      qty,
      chips: (data.refunded ?? []).map((r) => {
        const matched = data.inventory.find((i) => i.item_id === r.item_id);
        return { qty: r.qty, name: matched?.item?.name ?? 'materials', glyph: matched?.item?.glyph ?? '📦' };
      }),
    };
  } catch (e) {
    message.value = e.response?.data?.message || 'Could not scrap that item.';
  } finally {
    loading.value = false;
  }
}

async function repair(row) {
  const packItemId = selectedPack[row.id] || cheapestRepairPack.value?.item_id;
  if (!packItemId) {
    message.value = 'You have no repair packs — craft or buy one first.';
    return;
  }
  loading.value = true;
  message.value = '';
  try {
    const { data } = await api.post('/inventory/repair', { inventory_id: row.id, pack_item_id: packItemId });
    if (data.success) {
      let rollNote = '';
      if (data.rolled_pct > data.base_repair_pct) rollNote = ' (lucky roll!)';
      else if (data.rolled_pct < data.base_repair_pct) rollNote = ' (unlucky roll)';
      message.value = `Repaired ${row.item.name} — restored ${data.restored} durability (${data.rolled_pct}% roll)${rollNote}.`;
    } else {
      message.value = `Repair failed (${data.chance_pct}% chance) — the pack was consumed.`;
    }
    await load();
  } catch (e) {
    message.value = e.response?.data?.message || 'Could not repair that item.';
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
      <div class="inventory-header__icon">🎒</div>
      <h1 class="ox inventory-title">Inventory</h1>
    </div>

    <div class="inventory-forge-hints">
      <router-link to="/forge" class="inventory-forge-hint">🔥 Reforge gear on the Forge tab</router-link>
      <router-link to="/crates" class="inventory-forge-hint">📦 Open loot crates on the Crates tab</router-link>
    </div>

    <Toast :message="message" type="error" />

    <div v-if="scrapResult" class="scrap-result-card">
      <div class="scrap-result-card__header">
        <span class="ox scrap-result-card__title">♻ Scrapped {{ scrapResult.qty > 1 ? `${scrapResult.qty}× ` : '' }}{{ scrapResult.itemName }}</span>
        <button class="scrap-result-card__close" @click="scrapResult = null">✕</button>
      </div>
      <div v-if="scrapResult.chips.length" class="scrap-result-card__chips">
        <span v-for="c in scrapResult.chips" :key="c.name" class="scrap-result-card__chip">
          {{ c.glyph }} +{{ c.qty }} {{ c.name }}
        </span>
      </div>
      <div v-else class="scrap-result-card__empty">Nothing recoverable from that item.</div>
    </div>

    <div class="inventory-layout">
      <!-- Paper doll -->
      <div class="doll-card">
        <div class="doll-card__head">
          <div>
            <div class="ox doll-card__name">{{ store.character?.name }}</div>
            <div class="doll-card__sub">{{ store.character?.base_class }} · Lv.{{ store.character?.level }}</div>
          </div>
          <div v-if="store.stats" class="doll-card__stats">
            <div class="doll-stat">
              <div class="ox doll-stat__value doll-stat__value--atk">{{ store.stats.eff_atk }}</div>
              <div class="doll-stat__label">ATK</div>
            </div>
            <div class="doll-stat">
              <div class="ox doll-stat__value doll-stat__value--def">{{ store.stats.eff_def }}</div>
              <div class="doll-stat__label">DEF</div>
            </div>
            <div class="doll-stat">
              <div class="ox doll-stat__value doll-stat__value--pwr">{{ store.stats.power }}</div>
              <div class="doll-stat__label">PWR</div>
            </div>
          </div>
        </div>

        <div class="doll-grid">
          <div class="doll-col">
            <button
              v-for="slot in dollLeft"
              :key="slot.key"
              type="button"
              class="doll-slot"
              :class="{ 'doll-slot--filled': slot.row, 'doll-slot--selected': selectedSlotKey === slot.key && !selectedBagId }"
              :style="slot.row ? { borderColor: `${RARITY_COLORS[slot.row.item.rarity]}55`, background: `${RARITY_COLORS[slot.row.item.rarity]}12` } : null"
              @click="selectSlot(slot)"
            >
              <span class="doll-slot__glyph">{{ slot.row ? slot.row.item.glyph : slot.glyph }}</span>
              <span class="doll-slot__label">{{ slot.label }}</span>
              <span v-if="hasDurability(slot.row ?? {}) && slot.row?.durability <= 0" class="doll-slot__broken">!</span>
            </button>
          </div>

          <div class="doll-portrait">
            <span class="doll-portrait__glyph">{{ dollLeft[0]?.row?.item.glyph || '🙂' }}</span>
          </div>

          <div class="doll-col">
            <button
              v-for="slot in dollRight"
              :key="slot.key"
              type="button"
              class="doll-slot"
              :class="{ 'doll-slot--filled': slot.row, 'doll-slot--selected': selectedSlotKey === slot.key && !selectedBagId }"
              :style="slot.row ? { borderColor: `${RARITY_COLORS[slot.row.item.rarity]}55`, background: `${RARITY_COLORS[slot.row.item.rarity]}12` } : null"
              @click="selectSlot(slot)"
            >
              <span class="doll-slot__glyph">{{ slot.row ? slot.row.item.glyph : slot.glyph }}</span>
              <span class="doll-slot__label">{{ slot.label }}</span>
              <span v-if="hasDurability(slot.row ?? {}) && slot.row?.durability <= 0" class="doll-slot__broken">!</span>
            </button>
          </div>
        </div>

        <!-- Shared detail panel: whatever slot or bag cell was last clicked -->
        <div v-if="detail" class="detail-panel" :style="detail.item ? { borderColor: `${RARITY_COLORS[detail.item.rarity]}3d`, background: `${RARITY_COLORS[detail.item.rarity]}0d` } : null">
          <template v-if="detail.item">
            <div class="detail-panel__head">
              <span class="detail-panel__art" :style="{ background: `${RARITY_COLORS[detail.item.rarity]}18`, borderColor: `${RARITY_COLORS[detail.item.rarity]}44` }">{{ detail.item.glyph }}</span>
              <div class="detail-panel__title">
                <div class="ox detail-panel__name">{{ detail.item.name }}</div>
                <div class="detail-panel__type">{{ detail.kind === 'slot' ? detail.slot.label + ' · equipped' : detail.item.type }}</div>
                <div class="detail-panel__chips">
                  <span class="inventory-rarity-chip" :style="rarityChipStyle(detail.item.rarity)">{{ RARITY_LABELS[detail.item.rarity] }}</span>
                  <span v-if="detail.item.roll_pct != null" class="roll-chip" :class="{ 'is-good': detail.item.roll_pct > 0, 'is-bad': detail.item.roll_pct < 0 }">{{ detail.item.roll_pct > 0 ? '+' : '' }}{{ detail.item.roll_pct }}% roll</span>
                  <span v-if="detail.row.enchant_level > 0" class="roll-chip is-good">✦ Reforge {{ detail.row.enchant_level }}/{{ REFORGE_MAX_LEVEL }}</span>
                  <span v-if="EQUIPPABLE_TYPES.includes(detail.item.type) && !isMyClass(detail.item)" class="roll-chip is-bad">{{ detail.item.class_key }} only</span>
                </div>
              </div>
            </div>

            <div v-if="formatStats(detail.item.stat_json).length" class="detail-panel__stats">
              {{ formatStats(detail.item.stat_json).join(' · ') }}
            </div>
            <div v-if="detail.item.min_level" class="detail-panel__requires" :class="{ 'is-locked': detail.item.min_level > (store.character?.level ?? 0) }">
              Requires Level {{ detail.item.min_level }}
            </div>

            <div v-if="hasDurability(detail.row)" class="durability">
              <div class="durability__track">
                <div class="durability__fill" :class="{ 'is-broken': detail.row.durability <= 0 }" :style="{ width: durabilityPct(detail.row) + '%' }"></div>
              </div>
              <div class="durability__label">
                {{ detail.row.durability <= 0 ? 'Broken — repair to use its stats again' : `${detail.row.durability} / ${detail.row.durability_max} durability (${durabilityPct(detail.row)}%)` }}
              </div>
              <div v-if="detail.row.durability < detail.row.durability_max" class="repair-row">
                <select v-model="selectedPack[detail.row.id]" class="repair-row__select" aria-label="Repair pack">
                  <option :value="null" disabled selected>Repair pack…</option>
                  <option v-for="pack in repairPacks" :key="pack.item_id" :value="pack.item_id">
                    {{ pack.item.name }} ×{{ pack.qty }}
                  </option>
                </select>
                <button @click="repair(detail.row)" :disabled="loading || !repairPacks.length" class="repair-row__btn">Repair</button>
              </div>
            </div>

            <div class="detail-panel__actions">
              <button v-if="detail.kind === 'slot'" @click="unequip(detail.row)" :disabled="loading" class="detail-panel__btn">Unequip</button>
              <template v-else>
                <button
                  v-if="isUsable(detail.item)"
                  @click="use(detail.row)"
                  :disabled="loading"
                  class="detail-panel__btn"
                >
                  Use
                </button>
                <router-link
                  v-else-if="detail.item.type === 'loot_crate' && crateTabLocked"
                  to="/crates"
                  class="detail-panel__btn detail-panel__btn--locked"
                >
                  🔒 Open at LVL {{ crateUnlockLevel }}
                </router-link>
                <router-link
                  v-else-if="detail.item.type === 'loot_crate'"
                  to="/crates"
                  class="detail-panel__btn"
                >
                  Open on Crates tab
                </router-link>
                <button
                  v-else-if="EQUIPPABLE_TYPES.includes(detail.item.type) && isMyClass(detail.item)"
                  @click="equip(detail.row)"
                  :disabled="loading"
                  class="detail-panel__btn"
                >
                  Equip
                </button>
                <router-link
                  v-else-if="EQUIPPABLE_TYPES.includes(detail.item.type) && !isMyClass(detail.item)"
                  :to="{ path: '/market', query: { tab: 'sell', list_item: detail.row.id } }"
                  class="detail-panel__btn detail-panel__btn--sell"
                >
                  Sell on Market
                </router-link>
              </template>
              <button
                v-if="detail.kind === 'bag' || (hasDurability(detail.row) && detail.row.durability <= 0)"
                @click="askScrap(detail.row)"
                :disabled="loading"
                class="detail-panel__btn detail-panel__btn--danger"
              >
                ♻ Scrap
              </button>
            </div>
          </template>
          <template v-else>
            <div class="detail-panel__empty">
              <div class="ox detail-panel__empty-title">{{ detail.label }} is empty</div>
              <div class="detail-panel__empty-hint">{{ EMPTY_HINTS[detail.slot.type] || 'Nothing equipped in this slot yet.' }}</div>
            </div>
          </template>
        </div>
      </div>

      <!-- Bag -->
      <div class="bag-card">
        <div class="bag-card__head">
          <div class="bag-card__title-row">
            <span class="ox bag-card__title">Bag</span>
            <span class="bag-card__count">{{ unequippedInventory.length }} item{{ unequippedInventory.length === 1 ? '' : 's' }}</span>
          </div>
          <input
            v-model="bagQuery"
            type="text"
            placeholder="🔍 Search your bag…"
            class="bag-card__search"
          />
        </div>

        <div class="bag-card__filters">
          <button
            v-for="f in bagFilters"
            :key="f.key"
            type="button"
            class="bag-filter-chip"
            :class="{ 'bag-filter-chip--active': bagFilter === f.key }"
            @click="bagFilter = f.key"
          >
            {{ f.glyph }} {{ f.label }} <span class="bag-filter-chip__count">{{ f.count }}</span>
          </button>
        </div>

        <div v-if="!visibleBagRows.length" class="inventory-bag-empty">
          {{ bagQuery ? `No items match "${bagQuery}".` : 'Nothing here.' }}
        </div>

        <div v-else class="bag-cell-grid">
          <button
            v-for="row in visibleBagRows"
            :key="row.id"
            type="button"
            class="bag-cell"
            :class="{ 'bag-cell--selected': selectedBagId === row.id }"
            :style="{ borderColor: `${RARITY_COLORS[row.item.rarity]}40`, background: `${RARITY_COLORS[row.item.rarity]}0f` }"
            @click="selectBagRow(row)"
          >
            <span class="bag-cell__rail" :style="rarityRailStyle(row.item.rarity)"></span>
            <span class="bag-cell__glyph">{{ row.item.glyph }}</span>
            <span v-if="row.qty > 1" class="bag-cell__qty">×{{ row.qty }}</span>
            <span
              v-if="hasDurability(row)"
              class="bag-cell__dur"
              :style="{ background: `linear-gradient(90deg, ${row.durability <= 0 ? '#ff6a4d' : durabilityPct(row) < 30 ? '#eab308' : '#4ade80'} ${Math.max(4, durabilityPct(row))}%, rgba(255,255,255,.12) ${Math.max(4, durabilityPct(row))}%)` }"
            ></span>
          </button>
        </div>

        <div class="bag-card__legend">
          <span class="bag-card__legend-label">RARITY</span>
          <span v-for="(color, key) in RARITY_COLORS" :key="key" class="bag-card__legend-item">
            <span class="bag-card__legend-dot" :style="{ background: color }"></span>
            {{ RARITY_LABELS[key] }}
          </span>
        </div>
      </div>
    </div>

    <div
      v-if="scrapTarget"
      class="scrap-modal-overlay"
      @click.self="cancelScrap"
    >
      <div class="scrap-modal">
        <div class="ox scrap-modal__title">Scrap Item</div>
        <p class="scrap-modal__text">
          You are about to scrap
          <strong class="scrap-modal__name">{{ scrapTarget.item.name }}{{ scrapTarget.qty > 1 ? ` ×${scrapTarget.qty}` : '' }}</strong>.
          You'll recover 3% of its crafting materials back — repairing is almost always the better deal. This action cannot be undone.
        </p>
        <div v-if="scrapTarget.qty > 1" class="scrap-modal__qty-row">
          <label class="scrap-modal__qty-label" for="scrap-qty">Scrap how many?</label>
          <input
            id="scrap-qty"
            v-model.number="scrapQty"
            type="number"
            min="1"
            :max="scrapTarget.qty"
            class="scrap-modal__qty-input"
          />
          <button type="button" class="scrap-modal__qty-max" @click="scrapQty = scrapTarget.qty">Max ({{ scrapTarget.qty }})</button>
        </div>
        <div class="scrap-modal__actions">
          <button @click="cancelScrap" :disabled="loading" class="scrap-modal__cancel-btn">Cancel</button>
          <button @click="confirmScrap" :disabled="loading" class="scrap-modal__scrap-btn">
            {{ loading ? 'Scrapping…' : `Scrap ${scrapTarget.qty > 1 ? `×${Math.max(1, Math.min(scrapQty, scrapTarget.qty))}` : 'Item'}` }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style lang="scss" src="./InventoryPage.scss" scoped></style>
