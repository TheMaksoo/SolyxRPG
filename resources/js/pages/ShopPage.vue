<script setup>
import { ref, onMounted, computed } from 'vue';
import api from '../api/client';
import { useAuthStore } from '../stores/auth';
import AdBanner from '../components/AdBanner.vue';

const auth = useAuthStore();
const items = ref([]);
const tab = ref('weapon');
const message = ref('');
const loading = ref(false);

const tabs = [
  { key: 'weapon', label: 'Weapons' },
  { key: 'armor', label: 'Armor' },
  { key: 'consumable', label: 'Consumables' },
  { key: 'cosmetic', label: 'Cosmetics' },
];

const filtered = computed(() => items.value.filter((i) => i.type === tab.value));

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
        {{ t.label }}
      </button>
    </div>

    <p v-if="message" class="shop-message">{{ message }}</p>

    <AdBanner variant="inline" />

    <div class="shop-grid">
      <div
        v-for="item in filtered"
        :key="item.id"
        class="shop-item-card"
      >
        <div class="shop-item__header">
          <span class="shop-item__glyph">{{ item.glyph }}</span>
          <span class="ox shop-item__name">{{ item.name }}</span>
        </div>
        <div class="shop-item__desc">{{ item.description }}</div>
        <button
          @click="buy(item)"
          :disabled="loading"
          class="shop-buy-btn"
        >
          Buy — {{ item.price_gold ? `${item.price_gold}g` : `${item.price_gems}◆` }}
        </button>
      </div>
    </div>
  </div>
</template>

<style lang="scss" src="./ShopPage.scss" scoped></style>
