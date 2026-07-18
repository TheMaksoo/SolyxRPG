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
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;margin-bottom:20px">
      <div style="display:flex;align-items:center;gap:12px">
        <div style="font-size:28px">🛒</div>
        <h1 class="ox" style="font-size:28px;font-weight:800;margin:0">Shop</h1>
      </div>
      <div v-if="auth.user?.character" style="font-size:13px;color:rgba(255,255,255,.6)">
        {{ auth.user.character.gold }}g · {{ auth.user.character.gems }}◆
      </div>
    </div>

    <div style="display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap">
      <button
        v-for="t in tabs"
        :key="t.key"
        @click="tab = t.key"
        :style="{
          padding: '8px 16px',
          borderRadius: '20px',
          border: '1px solid rgba(255,255,255,.1)',
          background: tab === t.key ? '#e8482f' : '#151517',
          color: '#fff',
          fontSize: '13px',
          fontWeight: 600,
          cursor: 'pointer',
        }"
      >
        {{ t.label }}
      </button>
    </div>

    <p v-if="message" style="font-size:13px;color:rgba(255,255,255,.6);margin-bottom:14px">{{ message }}</p>

    <AdBanner variant="inline" />

    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px">
      <div
        v-for="item in filtered"
        :key="item.id"
        style="background:#151517;border:1px solid rgba(255,255,255,.07);border-radius:13px;padding:16px"
      >
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
          <span style="font-size:22px">{{ item.glyph }}</span>
          <span class="ox" style="font-weight:700;font-size:14.5px">{{ item.name }}</span>
        </div>
        <div style="font-size:12px;color:rgba(255,255,255,.5);margin-bottom:12px;line-height:1.5">{{ item.description }}</div>
        <button
          @click="buy(item)"
          :disabled="loading"
          style="width:100%;padding:9px;border-radius:8px;border:none;background:#e8482f;color:#fff;font-weight:700;font-size:13px;cursor:pointer"
        >
          Buy — {{ item.price_gold ? `${item.price_gold}g` : `${item.price_gems}◆` }}
        </button>
      </div>
    </div>
  </div>
</template>
