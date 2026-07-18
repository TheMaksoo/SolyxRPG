<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../api/client';

const RARITY_COLOR = {
  Common: '#cbd5e1',
  Rare: '#5cc7f5',
  Epic: '#a78bfa',
  Legendary: '#eab308',
  Mythic: '#e8482f',
};
const TILE_BG = 'repeating-linear-gradient(45deg,#1a1216,#1a1216 8px,#161014 8px,#161014 16px)';

const categories = ref([]);
const entries = ref([]);
const activeCat = ref('items');
const query = ref('');
const loading = ref(true);

onMounted(async () => {
  const { data } = await api.get('/wiki');
  categories.value = data.categories;
  entries.value = data.entries;
  activeCat.value = data.categories[0]?.key ?? 'items';
  loading.value = false;
});

const activeCategory = computed(
  () => categories.value.find((c) => c.key === activeCat.value) ?? { label: '', icon: '', key: '' }
);

const filteredEntries = computed(() => {
  const q = query.value.trim().toLowerCase();
  return entries.value
    .filter((e) => e.category === activeCat.value)
    .filter(
      (e) =>
        !q ||
        e.name.toLowerCase().includes(q) ||
        e.desc.toLowerCase().includes(q) ||
        e.sub.toLowerCase().includes(q)
    );
});

function statBg(stat) {
  return stat.muted ? 'rgba(255,255,255,.06)' : stat.color + '24';
}
</script>

<template>
  <div class="wiki-page">
    <aside class="wiki-sidebar">
      <div class="wiki-sidebar__brand">
        <img src="/images/solyx-icon.png" alt="" class="wiki-sidebar__logo" />
        <div>
          <div class="ox wiki-sidebar__brand-name">SOLYX</div>
          <div class="wiki-sidebar__brand-tag">WIKI</div>
        </div>
      </div>
      <nav>
        <button
          v-for="c in categories"
          :key="c.key"
          @click="activeCat = c.key"
          class="wiki-nav-btn"
          :class="{ 'is-active': activeCat === c.key }"
        >
          <span class="wiki-nav-btn__icon">{{ c.icon }}</span>
          {{ c.label }}
          <span class="wiki-nav-btn__count">{{ c.count }}</span>
        </button>
      </nav>
      <div class="wiki-sidebar__footer">
        <router-link to="/" class="wiki-sidebar__back-link">← Back to game</router-link>
      </div>
    </aside>

    <main class="wiki-main">
      <div class="wiki-breadcrumb">
        solyx.gg / wiki / {{ activeCategory.key }}
      </div>
      <div class="wiki-main-header">
        <div class="wiki-main-header__left">
          <div class="wiki-main-header__icon">{{ activeCategory.icon }}</div>
          <h1 class="ox wiki-main-header__title">{{ activeCategory.label }}</h1>
          <span class="wiki-main-header__count">{{ filteredEntries.length }} entries</span>
        </div>
        <input
          v-model="query"
          placeholder="🔍 Search the wiki…"
          class="wiki-search-input"
        />
      </div>

      <div class="wiki-entries-grid">
        <div
          v-for="e in filteredEntries"
          :key="e.id"
          class="wiki-entry-card"
        >
          <div class="wiki-entry__header">
            <div class="wiki-entry__icon">
              {{ e.g }}
            </div>
            <div class="wiki-entry__body">
              <div class="ox wiki-entry__name" :style="{ color: RARITY_COLOR[e.rarity] }">
                {{ e.name }}
              </div>
              <div class="wiki-entry__sub">{{ e.sub }}</div>
            </div>
          </div>
          <div class="wiki-entry__desc">
            {{ e.desc }}
          </div>
          <div class="wiki-entry__stats">
            <span
              v-for="(st, i) in e.stats"
              :key="i"
              class="wiki-stat-chip"
              :style="{ background: statBg(st), color: st.color }"
              >{{ st.t }}</span
            >
          </div>
        </div>
      </div>

      <div
        v-if="!loading && filteredEntries.length === 0"
        class="wiki-empty-state"
      >
        No entries match "{{ query }}".
      </div>
    </main>
  </div>
</template>

<style lang="scss" src="./WikiPage.scss" scoped></style>
