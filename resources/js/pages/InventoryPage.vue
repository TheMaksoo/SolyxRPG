<script setup>
import { ref, onMounted, computed, reactive } from 'vue';
import api from '../api/client';
import { useCharacterStore } from '../stores/character';
import { RARITY_COLORS, RARITY_LABELS, formatStats } from '../rarity';

const store = useCharacterStore();
const inventory = ref([]);
const loading = ref(false);
const message = ref('');
const selectedPack = reactive({});
const scrapTarget = ref(null);
const scrapResult = ref(null);

// 'quiver' is the ranger's 2nd combat slot (alongside their bow/weapon); 'shield' is the warrior's
// (alongside their chest armor) — see ItemSeeder/CraftingController. Equipping by type means neither
// ever conflicts with the weapon/armor slot. Both are class-restricted (see `classes` below) so other
// classes simply never see an empty slot they could never fill.
const EQUIPPABLE_TYPES = ['weapon', 'armor', 'shield', 'quiver', 'pickaxe', 'axe', 'sickle', 'hammer'];
const SLOT_DEFS = [
  { type: 'weapon', label: 'Weapon', glyph: '⚔' },
  { type: 'armor', label: 'Armor', glyph: '🥋' },
  { type: 'shield', label: 'Shield', glyph: '🛡', classes: ['warrior'] },
  { type: 'quiver', label: 'Quiver', glyph: '🎯', classes: ['ranger', 'rogue'] },
  { type: 'pickaxe', label: 'Pickaxe', glyph: '⛏' },
  { type: 'axe', label: 'Axe', glyph: '🪓' },
  { type: 'sickle', label: 'Sickle', glyph: '🔪' },
  { type: 'hammer', label: 'Hammer', glyph: '🔨' },
];

const equipped = computed(() => inventory.value.filter((i) => i.equipped));

const bagQuery = ref('');
const repairPacks = computed(() => inventory.value.filter((i) => i.item.type === 'repair_pack'));

// Same category scheme Shop and Crafting already use, in the same order — what your class can actually
// equip (weapons/armor/quiver/tools) surfaces first, then things you consume, then everything else.
const BAG_SECTIONS = [
  { key: 'weapon', label: 'Weapons', glyph: '⚔', types: ['weapon'] },
  { key: 'armor', label: 'Armor', glyph: '🛡', types: ['armor', 'shield'] },
  { key: 'quiver', label: 'Quivers', glyph: '🎯', types: ['quiver'] },
  { key: 'tools', label: 'Tools', glyph: '⛏', types: ['pickaxe', 'axe', 'sickle', 'hammer'] },
  { key: 'consumable', label: 'Consumables', glyph: '🧪', types: ['consumable'] },
  { key: 'repair_pack', label: 'Repair Packs', glyph: '🧰', types: ['repair_pack'] },
  { key: 'cosmetic', label: 'Cosmetics', glyph: '👑', types: ['cosmetic'] },
  { key: 'material', label: 'Materials', glyph: '🪨', types: ['material'] },
];
const GEAR_SECTIONS = new Set(['weapon', 'armor', 'quiver', 'tools']);

const bagSections = computed(() => {
  const q = bagQuery.value.trim().toLowerCase();
  const rows = inventory.value
    .filter((i) => !i.equipped)
    .filter((i) => !q || i.item.name.toLowerCase().includes(q) || i.item.type.toLowerCase().includes(q));

  return BAG_SECTIONS.map((section) => {
    const sectionRows = rows.filter((r) => section.types.includes(r.item.type));
    if (!sectionRows.length) return null;

    // Gear your class can actually wear sorts ahead of gear made for another class — that other-class
    // gear still shows up (for repairing or listing on the Marketplace), it just sinks to the back.
    if (GEAR_SECTIONS.has(section.key)) {
      const sorted = [...sectionRows].sort((a, b) => Number(isMyClass(b.item)) - Number(isMyClass(a.item)));
      return { ...section, rows: sectionRows, groups: [{ key: 'all', label: null, rows: sorted }] };
    }

    // Consumables split by heal type (HP/MP/Regen/Other), same grouping Shop and Crafting use.
    if (section.key === 'consumable') {
      const hp = sectionRows.filter((r) => r.item.stat_json?.heal_hp_pct !== undefined || r.item.stat_json?.heal_hp_flat !== undefined);
      const mp = sectionRows.filter((r) =>
        (r.item.stat_json?.heal_mp_pct !== undefined || r.item.stat_json?.heal_mp_flat !== undefined) &&
        r.item.stat_json?.heal_hp_pct === undefined && r.item.stat_json?.heal_hp_flat === undefined
      );
      const regen = sectionRows.filter((r) => r.item.stat_json?.hp_regen_pct_buff !== undefined || r.item.stat_json?.mana_regen_pct_buff !== undefined);
      const other = sectionRows.filter((r) => !hp.includes(r) && !mp.includes(r) && !regen.includes(r));
      const groups = [
        { key: 'hp', label: '❤️ HP Healing', rows: hp },
        { key: 'mp', label: '💧 MP Healing', rows: mp },
        { key: 'regen', label: '🌿 Regen Buffs', rows: regen },
        { key: 'other', label: '✨ Other', rows: other },
      ].filter((g) => g.rows.length);
      return { ...section, rows: sectionRows, groups };
    }

    return { ...section, rows: sectionRows, groups: [{ key: 'all', label: null, rows: sectionRows }] };
  }).filter(Boolean);
});

const slots = computed(() =>
  SLOT_DEFS.filter((slot) => !slot.classes || slot.classes.includes(store.character?.base_class)).map((slot) => ({
    ...slot,
    row: equipped.value.find((row) => row.item.type === slot.type) ?? null,
  }))
);

const SLOT_GROUPS = [
  { key: 'armor', label: 'Armor', types: ['armor', 'shield'] },
  { key: 'weapons', label: 'Weapons', types: ['weapon', 'quiver'] },
  { key: 'tools', label: 'Tools', types: ['pickaxe', 'axe', 'sickle', 'hammer'] },
];

const slotGroups = computed(() =>
  SLOT_GROUPS.map((group) => ({
    ...group,
    slots: slots.value.filter((slot) => group.types.includes(slot.type)),
  }))
);

function rarityChipStyle(rarity) {
  const color = RARITY_COLORS[rarity] || RARITY_COLORS.common;
  return { color, background: `${color}22` };
}

/** Lower rarity = cheaper repair pack in this game's pricing, so rarity order doubles as a price order. */
const REPAIR_PACK_RARITY_ORDER = ['common', 'rare', 'epic', 'legendary', 'mythic'];
const cheapestRepairPack = computed(() => {
  if (!repairPacks.value.length) return null;
  return [...repairPacks.value].sort(
    (a, b) => REPAIR_PACK_RARITY_ORDER.indexOf(a.item.rarity) - REPAIR_PACK_RARITY_ORDER.indexOf(b.item.rarity)
  )[0];
});

function isUsable(item) {
  return !!(item.stat_json?.hp_regen_pct_buff || item.stat_json?.mana_regen_pct_buff);
}

// Any class can craft/buy/sell another class's gear, but only that class can wear it — matches
// InventoryController::equip()'s server-side check. Quivers are shared between Ranger and Rogue.
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
  try {
    const { data } = await api.post('/inventory/equip', { item_id: row.item_id });
    inventory.value = data.inventory;
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
    const { data } = await api.post('/inventory/unequip', { item_id: row.item_id });
    inventory.value = data.inventory;
  } finally {
    loading.value = false;
  }
}

function askScrap(row) {
  scrapTarget.value = row;
}

function cancelScrap() {
  scrapTarget.value = null;
}

async function confirmScrap() {
  const row = scrapTarget.value;
  if (!row) return;
  scrapTarget.value = null;
  loading.value = true;
  message.value = '';
  scrapResult.value = null;
  try {
    const { data } = await api.post('/inventory/scrap', { inventory_id: row.id });
    inventory.value = data.inventory;
    scrapResult.value = {
      itemName: row.item.name,
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

onMounted(load);
</script>

<template>
  <div>
    <div class="inventory-header">
      <div class="inventory-header__icon">🎒</div>
      <h1 class="ox inventory-title">Inventory</h1>
    </div>

    <p v-if="message" class="inventory-message">{{ message }}</p>

    <div v-if="scrapResult" class="scrap-result-card">
      <div class="scrap-result-card__header">
        <span class="ox scrap-result-card__title">♻ Scrapped {{ scrapResult.itemName }}</span>
        <button class="scrap-result-card__close" @click="scrapResult = null">✕</button>
      </div>
      <div v-if="scrapResult.chips.length" class="scrap-result-card__chips">
        <span v-for="c in scrapResult.chips" :key="c.name" class="scrap-result-card__chip">
          {{ c.glyph }} +{{ c.qty }} {{ c.name }}
        </span>
      </div>
      <div v-else class="scrap-result-card__empty">Nothing recoverable from that item.</div>
    </div>

    <div v-if="store.stats" class="inventory-char-summary">
      <div class="inventory-char-stat">
        <div class="ox inventory-char-stat__value">{{ store.stats.eff_atk }}</div>
        <div class="inventory-char-stat__label">ATK</div>
      </div>
      <div class="inventory-char-stat">
        <div class="ox inventory-char-stat__value">{{ store.stats.eff_def }}</div>
        <div class="inventory-char-stat__label">DEF</div>
      </div>
      <div class="inventory-char-stat">
        <div class="ox inventory-char-stat__value">{{ store.stats.power }}</div>
        <div class="inventory-char-stat__label">Power</div>
      </div>
    </div>

    <h3 class="ox inventory-section-heading">Equipment</h3>
    <div class="equipment-groups">
      <div v-for="group in slotGroups" :key="group.key" class="equipment-group">
        <div class="ox equipment-group__eyebrow">{{ group.label }}</div>
        <div class="equipment-group__grid">
          <div v-for="slot in group.slots" :key="slot.type">
            <div
              class="equipment-slot-row"
              :class="{ 'equipment-slot-row--empty': !slot.row }"
            >
              <span class="equipment-slot-row__glyph">{{ slot.row ? slot.row.item.glyph : slot.glyph }}</span>
              <div class="equipment-slot-row__body">
                <div class="equipment-slot-row__label">{{ slot.label }}</div>
                <div v-if="slot.row" class="ox equipment-slot-row__item">{{ slot.row.item.name }}</div>
                <div v-else class="equipment-slot-row__empty">Empty</div>
              </div>
              <div v-if="slot.row" class="equipment-slot-row__chips">
                <span v-if="slot.row.item.roll_pct != null" class="roll-chip" :class="{ 'is-good': slot.row.item.roll_pct > 0, 'is-bad': slot.row.item.roll_pct < 0 }">{{ slot.row.item.roll_pct > 0 ? '+' : '' }}{{ slot.row.item.roll_pct }}%</span>
                <span class="inventory-rarity-chip" :style="rarityChipStyle(slot.row.item.rarity)">{{ RARITY_LABELS[slot.row.item.rarity] }}</span>
              </div>
            </div>
            <div v-if="slot.row" class="equipment-slot-detail">
              <div v-if="formatStats(slot.row.item.stat_json).length" class="inventory-card__stats">
                {{ formatStats(slot.row.item.stat_json).join(' · ') }}
              </div>
              <div v-if="hasDurability(slot.row)" class="durability">
                <div class="durability__track">
                  <div class="durability__fill" :class="{ 'is-broken': slot.row.durability <= 0 }" :style="{ width: durabilityPct(slot.row) + '%' }"></div>
                </div>
                <div class="durability__label">
                  {{ slot.row.durability <= 0 ? 'Broken — repair to use its stats again' : `${slot.row.durability} / ${slot.row.durability_max} durability (${durabilityPct(slot.row)}%)` }}
                </div>
                <div v-if="slot.row.durability < slot.row.durability_max" class="repair-row">
                  <select v-model="selectedPack[slot.row.id]" class="repair-row__select" aria-label="Repair pack" @click.stop>
                    <option :value="null" disabled selected>Repair pack…</option>
                    <option v-for="pack in repairPacks" :key="pack.item_id" :value="pack.item_id">
                      {{ pack.item.name }} ×{{ pack.qty }}
                    </option>
                  </select>
                  <button @click.stop="repair(slot.row)" :disabled="loading || !repairPacks.length" class="repair-row__btn">Repair</button>
                </div>
              </div>
              <div class="equipment-slot__actions">
                <button @click.stop="unequip(slot.row)" :disabled="loading" class="equipment-slot__action-btn">Unequip</button>
                <button
                  v-if="hasDurability(slot.row) && slot.row.durability <= 0"
                  @click.stop="askScrap(slot.row)"
                  :disabled="loading"
                  class="equipment-slot__action-btn equipment-slot__action-btn--danger"
                >
                  Scrap
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="inventory-bag-heading-row">
      <h3 class="ox inventory-section-heading">Bag</h3>
      <input
        v-model="bagQuery"
        type="text"
        placeholder="🔍 Search your bag…"
        class="inventory-bag-search"
      />
    </div>
    <div v-if="bagQuery && !bagSections.length" class="inventory-bag-empty">No items match "{{ bagQuery }}".</div>

    <div v-for="section in bagSections" :key="section.key" class="bag-section">
      <div class="ox bag-section-eyebrow">
        {{ section.glyph }} {{ section.label }}
        <span class="bag-section-eyebrow__count">{{ section.rows.length }}</span>
      </div>

      <div v-for="group in section.groups" :key="group.key">
        <div v-if="group.label" class="bag-section-group-label">{{ group.label }}</div>
        <div class="inventory-bag-grid">
          <div
            v-for="row in group.rows"
            :key="row.id"
            class="inventory-bag-card"
          >
            <div class="inventory-bag-card__header">
              <span class="inventory-bag-card__icon">{{ row.item.glyph }}</span>
              <span class="ox inventory-bag-card__name">{{ row.item.name }}</span>
              <span v-if="row.qty > 1" class="inventory-bag-card__qty">×{{ row.qty }}</span>
            </div>
            <div class="inventory-bag-card__chips">
              <div class="inventory-rarity-chip inventory-rarity-chip--inline" :style="rarityChipStyle(row.item.rarity)">{{ RARITY_LABELS[row.item.rarity] }}</div>
              <span v-if="row.item.roll_pct != null" class="roll-chip" :class="{ 'is-good': row.item.roll_pct > 0, 'is-bad': row.item.roll_pct < 0 }">{{ row.item.roll_pct > 0 ? '+' : '' }}{{ row.item.roll_pct }}% roll</span>
            </div>
            <div v-if="formatStats(row.item.stat_json).length" class="inventory-card__stats">
              {{ formatStats(row.item.stat_json).join(' · ') }}
            </div>
            <div v-if="hasDurability(row)" class="durability">
              <div class="durability__track">
                <div class="durability__fill" :class="{ 'is-broken': row.durability <= 0 }" :style="{ width: durabilityPct(row) + '%' }"></div>
              </div>
              <div class="durability__label">
                {{ row.durability <= 0 ? 'Broken — repair to use its stats again' : `${row.durability} / ${row.durability_max} durability (${durabilityPct(row)}%)` }}
              </div>
            </div>
            <div class="inventory-bag-card__footer">
              <select
                v-if="hasDurability(row) && row.durability < row.durability_max"
                v-model="selectedPack[row.id]"
                class="repair-row__select repair-row__select--full"
              >
                <option :value="null" disabled selected>Repair pack…</option>
                <option v-for="pack in repairPacks" :key="pack.item_id" :value="pack.item_id">
                  {{ pack.item.name }} ×{{ pack.qty }}
                </option>
              </select>
              <button
                v-if="hasDurability(row) && row.durability < row.durability_max"
                @click="repair(row)"
                :disabled="loading || !repairPacks.length"
                class="inventory-bag-card__equip-btn inventory-bag-card__equip-btn--repair"
              >
                Repair
              </button>
              <button
                v-if="isUsable(row.item)"
                @click="use(row)"
                :disabled="loading"
                class="inventory-bag-card__equip-btn"
              >
                Use
              </button>
              <button
                v-if="EQUIPPABLE_TYPES.includes(row.item.type) && isMyClass(row.item)"
                @click="equip(row)"
                :disabled="loading"
                class="inventory-bag-card__equip-btn"
              >
                Equip
              </button>
              <router-link
                v-else-if="EQUIPPABLE_TYPES.includes(row.item.type) && !isMyClass(row.item)"
                :to="{ path: '/market', query: { tab: 'sell', list_item: row.id } }"
                class="inventory-bag-card__equip-btn inventory-bag-card__equip-btn--sell"
              >
                Sell on Market
              </router-link>
            </div>
            <button
              @click="askScrap(row)"
              :disabled="loading"
              class="inventory-bag-card__scrap-btn"
              title="Scrap"
            >
              <span class="inventory-bag-card__scrap-icon">♻</span>
            </button>
          </div>
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
        <div class="scrap-modal__actions">
          <button @click="cancelScrap" :disabled="loading" class="scrap-modal__cancel-btn">Cancel</button>
          <button @click="confirmScrap" :disabled="loading" class="scrap-modal__scrap-btn">
            {{ loading ? 'Scrapping…' : 'Scrap Item' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style lang="scss" src="./InventoryPage.scss" scoped></style>
