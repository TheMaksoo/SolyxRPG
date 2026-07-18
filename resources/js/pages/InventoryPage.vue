<script setup>
import { ref, onMounted, computed } from 'vue';
import api from '../api/client';
import { useCharacterStore } from '../stores/character';

const store = useCharacterStore();
const inventory = ref([]);
const loading = ref(false);
const message = ref('');

const equipped = computed(() => inventory.value.filter((i) => i.equipped));
const bag = computed(() => inventory.value.filter((i) => !i.equipped));

function isUsable(item) {
  return !!(item.stat_json?.hp_regen_pct_buff || item.stat_json?.mana_regen_pct_buff);
}

async function load() {
  const { data } = await api.get('/inventory');
  inventory.value = data.inventory;
}

async function equip(row) {
  if (!['weapon', 'armor'].includes(row.item.type)) return;
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

onMounted(load);
</script>

<template>
  <div>
    <div class="inventory-header">
      <div class="inventory-header__icon">🎒</div>
      <h1 class="ox inventory-title">Inventory</h1>
    </div>

    <p v-if="message" class="inventory-message">{{ message }}</p>

    <h3 class="ox inventory-section-heading">Equipped</h3>
    <div class="inventory-equipped-grid">
      <div
        v-for="row in equipped"
        :key="row.id"
        class="inventory-equipped-card"
      >
        <span class="inventory-equipped-card__icon">{{ row.item.glyph }}</span>
        <span class="ox inventory-equipped-card__name">{{ row.item.name }}</span>
      </div>
      <div v-if="equipped.length === 0" class="inventory-empty">Nothing equipped yet.</div>
    </div>

    <h3 class="ox inventory-section-heading">Bag</h3>
    <div class="inventory-bag-grid">
      <div
        v-for="row in bag"
        :key="row.id"
        class="inventory-bag-card"
      >
        <div class="inventory-bag-card__header">
          <span class="inventory-bag-card__icon">{{ row.item.glyph }}</span>
          <span class="ox inventory-bag-card__name">{{ row.item.name }}</span>
          <span v-if="row.qty > 1" class="inventory-bag-card__qty">×{{ row.qty }}</span>
        </div>
        <button
          v-if="['weapon', 'armor'].includes(row.item.type)"
          @click="equip(row)"
          :disabled="loading"
          class="inventory-bag-card__equip-btn"
        >
          Equip
        </button>
        <button
          v-if="isUsable(row.item)"
          @click="use(row)"
          :disabled="loading"
          class="inventory-bag-card__equip-btn"
        >
          Use
        </button>
      </div>
    </div>
  </div>
</template>

<style lang="scss" src="./InventoryPage.scss" scoped></style>
