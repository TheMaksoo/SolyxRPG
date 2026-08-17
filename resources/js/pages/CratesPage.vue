<script setup>
import { ref, onMounted, onUnmounted, computed } from 'vue';
import api from '../api/client';
import { useCharacterStore } from '../stores/character';
import { RARITY_COLORS, RARITY_LABELS } from '../rarity';
import Toast from '../components/Toast.vue';

const store = useCharacterStore();
const inventory = ref([]);
const crateOpenings = ref([]);
const maxConcurrent = ref(1);
const loading = ref(false);
const message = ref('');
const messageType = ref('success');
const claimSummary = ref(null);
const now = ref(Date.now());
const selectedStashId = ref(null);
let messageTimer = null;
let clockTimer = null;

function showMessage(text, type = 'success') {
  clearTimeout(messageTimer);
  message.value = text;
  messageType.value = type;
  messageTimer = setTimeout(() => { message.value = ''; }, 3000);
}

const activeUnlocking = computed(() => crateOpenings.value.filter((o) => !o.is_ready).length);
const unopenedCrates = computed(() => inventory.value.filter((r) => r.item.type === 'loot_crate'));
const atSlotCap = computed(() => activeUnlocking.value >= maxConcurrent.value);
const selectedStashRow = computed(() => unopenedCrates.value.find((r) => r.id === selectedStashId.value) ?? null);

function rarityColor(rarity) {
  return RARITY_COLORS[rarity] || RARITY_COLORS.common;
}

function rarityChipStyle(rarity) {
  const color = rarityColor(rarity);
  return { color, background: `${color}22` };
}

function rarityRailStyle(rarity) {
  return { background: rarityColor(rarity) };
}

function formatCountdown(seconds) {
  if (seconds <= 0) return 'Ready!';
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  const s = Math.floor(seconds % 60);
  if (h > 0) return `${h}h ${m}m`;
  if (m > 0) return `${m}m ${s}s`;
  return `${s}s`;
}

function secondsRemaining(opening) {
  return Math.max(0, Math.floor((new Date(opening.completes_at).getTime() - now.value) / 1000));
}

// One bay card per real unlock slot — sized to the actual `max_concurrent` (base 1 + VIP bonus, no
// fixed ceiling), never a fabricated fixed count. An opening in flight always gets a bay even if it
// somehow exceeds the current max (e.g. a VIP tier just expired).
const bays = computed(() => {
  const slotCount = Math.max(maxConcurrent.value, crateOpenings.value.length);
  return Array.from({ length: slotCount }, (_, i) => {
    const opening = crateOpenings.value[i] ?? null;
    if (!opening) return { state: 'free', opening: null };
    return { state: secondsRemaining(opening) <= 0 ? 'ready' : 'timer', opening };
  });
});

async function load() {
  const { data } = await api.get('/inventory');
  inventory.value = data.inventory;
}

async function loadCrates() {
  const { data } = await api.get('/crates');
  crateOpenings.value = data.openings;
  maxConcurrent.value = data.max_concurrent;
}

async function openCrate(row) {
  loading.value = true;
  try {
    const { data } = await api.post(`/crates/${row.id}/open`);
    inventory.value = data.inventory;
    selectedStashId.value = null;
    await loadCrates();
    showMessage(`${row.item.name} opened, unlocking now.`);
  } catch (e) {
    showMessage(e.response?.data?.message || 'Could not open that crate.', 'error');
  } finally {
    loading.value = false;
  }
}

async function collectCrate(opening) {
  loading.value = true;
  try {
    const { data } = await api.post(`/crates/${opening.id}/collect`);
    inventory.value = data.inventory;
    if (data.character) store.character = data.character;
    await loadCrates();
    claimSummary.value = {
      rarity: opening.rarity,
      gold: data.rewards.find((r) => r.type === 'gold')?.amount ?? 0,
      gems: data.rewards.find((r) => r.type === 'gems')?.amount ?? 0,
      items: data.rewards.filter((r) => r.type === 'item'),
    };
  } catch (e) {
    showMessage(e.response?.data?.message || 'Could not collect that crate.', 'error');
  } finally {
    loading.value = false;
  }
}

onMounted(() => {
  load();
  loadCrates();
  clockTimer = setInterval(() => { now.value = Date.now(); }, 1000);
});
onUnmounted(() => { if (clockTimer) clearInterval(clockTimer); });
</script>

<template>
  <div>
    <div class="inventory-header">
      <div class="inventory-header__icon">📦</div>
      <h1 class="ox inventory-title">Crates</h1>
    </div>

    <Toast :message="message" :type="messageType" />

    <div v-if="claimSummary" class="claim-summary">
      <div class="claim-summary__header">
        <span class="ox claim-summary__title">🎉 {{ RARITY_LABELS[claimSummary.rarity] || 'Crate' }} opened!</span>
        <button type="button" class="claim-summary__close" @click="claimSummary = null">✕</button>
      </div>
      <div class="claim-summary__rows">
        <span v-if="claimSummary.gold" class="claim-summary__chip">🪙 +{{ claimSummary.gold }} gold</span>
        <span v-if="claimSummary.gems" class="claim-summary__chip">💎 +{{ claimSummary.gems }} gems</span>
        <span v-for="item in claimSummary.items" :key="item.item_key" class="claim-summary__chip">{{ item.glyph }} +{{ item.qty }} {{ item.name }}</span>
      </div>
    </div>

    <div class="crates-panel">
      <div class="ox crates-panel__title-row">
        <span class="crates-panel__title">Unlock bays</span>
        <span class="crates-slot-chip">🔓 {{ activeUnlocking }} / {{ maxConcurrent }} unlocking</span>
      </div>
      <router-link v-if="maxConcurrent < 4" to="/vip" class="crates-slot-hint">Premium unlocks more slots + faster timers →</router-link>

      <div class="bay-grid">
        <div
          v-for="(bay, i) in bays"
          :key="bay.opening?.id ?? `free-${i}`"
          class="bay-card"
          :class="`bay-card--${bay.state}`"
          :style="bay.opening ? { borderColor: `${rarityColor(bay.opening.rarity)}${bay.state === 'ready' ? '' : '4d'}`, background: `${rarityColor(bay.opening.rarity)}${bay.state === 'ready' ? '14' : '0d'}` } : null"
        >
          <span class="bay-card__glyph">{{ bay.state === 'free' ? '➕' : '📦' }}</span>
          <div v-if="bay.opening" class="ox bay-card__title" :style="{ color: rarityColor(bay.opening.rarity) }">{{ RARITY_LABELS[bay.opening.rarity] || bay.opening.rarity }}</div>
          <div v-else class="ox bay-card__title bay-card__title--muted">Bay free</div>
          <div class="bay-card__sub">
            {{ bay.state === 'ready' ? 'Ready to collect' : bay.state === 'timer' ? formatCountdown(secondsRemaining(bay.opening)) + ' left' : 'Open a crate below' }}
          </div>
          <button
            v-if="bay.state === 'ready'"
            type="button"
            class="bay-card__btn bay-card__btn--ready"
            :style="{ background: rarityColor(bay.opening.rarity) }"
            :disabled="loading"
            @click="collectCrate(bay.opening)"
          >
            Collect
          </button>
        </div>
      </div>
    </div>

    <div class="crates-panel">
      <div class="ox crates-panel__title-row">
        <span class="crates-panel__title">Crate stash</span>
        <span class="crates-slot-chip">{{ unopenedCrates.length }} unopened</span>
      </div>

      <div v-if="!unopenedCrates.length" class="forge-empty">No unopened crates in your bag right now. Earn them from battles, dungeons, and quests.</div>

      <template v-else>
        <div class="stash-grid">
          <button
            v-for="row in unopenedCrates"
            :key="row.id"
            type="button"
            class="stash-cell"
            :class="{ 'stash-cell--selected': selectedStashId === row.id }"
            :style="{ borderColor: `${rarityColor(row.item.rarity)}40`, background: `${rarityColor(row.item.rarity)}0f` }"
            @click="selectedStashId = row.id"
          >
            <span class="stash-cell__rail" :style="rarityRailStyle(row.item.rarity)"></span>
            <span class="stash-cell__glyph">{{ row.item.glyph }}</span>
            <span v-if="row.qty > 1" class="stash-cell__qty">×{{ row.qty }}</span>
          </button>
        </div>

        <div v-if="selectedStashRow" class="stash-detail">
          <div class="stash-detail__info">
            <span class="stash-detail__glyph">{{ selectedStashRow.item.glyph }}</span>
            <div>
              <div class="ox stash-detail__name">{{ selectedStashRow.item.name }}</div>
              <div class="inventory-rarity-chip inventory-rarity-chip--inline" :style="rarityChipStyle(selectedStashRow.item.rarity)">{{ RARITY_LABELS[selectedStashRow.item.rarity] }}</div>
            </div>
          </div>
          <button
            type="button"
            @click="openCrate(selectedStashRow)"
            :disabled="loading || atSlotCap"
            :title="atSlotCap ? 'All your lootbox slots are busy unlocking, collect one first' : ''"
            class="stash-detail__btn"
          >
            {{ atSlotCap ? 'Slots full' : 'Open' }}
          </button>
        </div>
      </template>
    </div>
  </div>
</template>

<style lang="scss" src="./CratesPage.scss" scoped></style>
