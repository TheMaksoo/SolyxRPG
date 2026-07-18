<script setup>
import { ref, onMounted, computed } from 'vue';
import api from '../api/client';

const inventory = ref([]);
const loading = ref(false);

const equipped = computed(() => inventory.value.filter((i) => i.equipped));
const bag = computed(() => inventory.value.filter((i) => !i.equipped));

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

onMounted(load);
</script>

<template>
  <div>
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px">
      <div style="font-size:28px">🎒</div>
      <h1 class="ox" style="font-size:28px;font-weight:800;margin:0">Inventory</h1>
    </div>

    <h3 class="ox" style="font-size:13px;color:rgba(255,255,255,.4);text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px">Equipped</h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-bottom:24px">
      <div
        v-for="row in equipped"
        :key="row.id"
        style="background:#151517;border:1px solid #e8482f;border-radius:11px;padding:14px"
      >
        <span style="font-size:20px;margin-right:8px">{{ row.item.glyph }}</span>
        <span class="ox" style="font-weight:700;font-size:13.5px">{{ row.item.name }}</span>
      </div>
      <div v-if="equipped.length === 0" style="color:rgba(255,255,255,.35);font-size:13px">Nothing equipped yet.</div>
    </div>

    <h3 class="ox" style="font-size:13px;color:rgba(255,255,255,.4);text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px">Bag</h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px">
      <div
        v-for="row in bag"
        :key="row.id"
        style="background:#151517;border:1px solid rgba(255,255,255,.07);border-radius:11px;padding:14px"
      >
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
          <span style="font-size:20px">{{ row.item.glyph }}</span>
          <span class="ox" style="font-weight:700;font-size:13.5px">{{ row.item.name }}</span>
          <span v-if="row.qty > 1" style="margin-left:auto;font-size:12px;color:rgba(255,255,255,.4)">×{{ row.qty }}</span>
        </div>
        <button
          v-if="['weapon', 'armor'].includes(row.item.type)"
          @click="equip(row)"
          :disabled="loading"
          style="width:100%;padding:7px;border-radius:7px;border:1px solid rgba(255,255,255,.12);background:transparent;color:#fff;font-size:12px;cursor:pointer"
        >
          Equip
        </button>
      </div>
    </div>
  </div>
</template>
