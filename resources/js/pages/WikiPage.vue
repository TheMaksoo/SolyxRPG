<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../api/client';

const RARITY_COLOR = {
  Common: '#cbd5e1',
  Rare: '#5cc7f5',
  Epic: '#a78bfa',
  Legendary: '#eab308',
  Mythic: '#f472b6',
};
const TILE_BG = 'repeating-linear-gradient(45deg,#1a1216,#1a1216 8px,#161014 8px,#161014 16px)';

const categories = ref([]);
const entries = ref([]);
const activeCat = ref('items');
const activeGroup = ref(null);
const query = ref('');
const loading = ref(true);
const selectedEntry = ref(null);
const selectedTierKey = ref(null);
// Modal navigation history — pushed onto whenever a linked row (e.g. a zone's monster, a monster's
// dropped item) is clicked, so "← Back" can return to whatever opened this entry instead of just closing.
const navStack = ref([]);

onMounted(async () => {
  const { data } = await api.get('/wiki');
  categories.value = data.categories;
  entries.value = data.entries;
  activeCat.value = data.categories[0]?.key ?? 'items';
  loading.value = false;
});

function selectCategory(key) {
  activeCat.value = key;
  activeGroup.value = null;
}

const activeCategory = computed(
  () => categories.value.find((c) => c.key === activeCat.value) ?? { label: '', icon: '', key: '', tallies: [] }
);

const entriesInCategory = computed(() => entries.value.filter((e) => e.category === activeCat.value));

// Sub-filter within a category — e.g. Monsters by zone, Items by class — only shown when the category
// actually carries a group_label (see WikiSyncService); categories without one never show this row
// since every entry's `group` is null.
const groupsForCategory = computed(() => {
  const groups = new Set(entriesInCategory.value.filter((e) => e.group).map((e) => e.group));
  return [...groups].sort();
});

const filteredEntries = computed(() => {
  const q = query.value.trim().toLowerCase();
  return entriesInCategory.value
    .filter((e) => !activeGroup.value || e.group === activeGroup.value)
    .filter(
      (e) =>
        !q ||
        e.name.toLowerCase().includes(q) ||
        e.desc.toLowerCase().includes(q) ||
        e.sub.toLowerCase().includes(q)
    );
});

// Entries grouped into titled sections (in first-seen order) — every category now carries a
// group_label, so this is always populated rather than falling back to one flat ungrouped section.
const sections = computed(() => {
  const order = [];
  const byGroup = new Map();
  for (const e of filteredEntries.value) {
    const key = e.group || 'Other';
    if (!byGroup.has(key)) {
      byGroup.set(key, []);
      order.push(key);
    }
    byGroup.get(key).push(e);
  }
  return order.map((label) => ({ label, cards: byGroup.get(label) }));
});

function statBg(stat) {
  return stat.muted ? 'rgba(255,255,255,.06)' : stat.color + '24';
}

function openEntry(entry) {
  selectedEntry.value = entry;
  const tiers = entry.details?.tiers ?? [];
  const currentKey = entry.rarity?.toLowerCase();
  selectedTierKey.value = tiers.find((t) => t.key === currentKey)?.key ?? null;
}

// Opens a cross-linked entry (e.g. a zone's "Monsters found here" row, an item's "Dropped by" row)
// found by real category+id — the current entry is pushed onto navStack first so "← Back" can return
// to it. A link with no resolvable id (the target hasn't been synced to the wiki yet) just no-ops,
// leaving the row as plain text.
function openLink(link) {
  if (!link?.id) return;
  const target = entries.value.find((e) => e.category === link.category && e.id === link.id);
  if (!target) return;
  navStack.value = [...navStack.value, selectedEntry.value];
  openEntry(target);
}

function goBack() {
  const previous = navStack.value[navStack.value.length - 1];
  navStack.value = navStack.value.slice(0, -1);
  if (previous) {
    openEntry(previous);
  } else {
    closeModal();
  }
}

function closeModal() {
  selectedEntry.value = null;
  selectedTierKey.value = null;
  navStack.value = [];
}

const selectedTier = computed(() => {
  const tiers = selectedEntry.value?.details?.tiers ?? [];
  return tiers.find((t) => t.key === selectedTierKey.value) ?? null;
});

// What the modal's stat panel actually shows: the picked tier's own full stat block if it has one
// (item rarity siblings precompute a full stat array per tier), otherwise the picked tier's one-line
// summary (monster grades / skill ranks / pet ranks), otherwise the entry's own base full_stats.
const modalStatRows = computed(() => {
  if (selectedTier.value?.full_stats) return selectedTier.value.full_stats;
  return selectedEntry.value?.details?.full_stats ?? [];
});

const modalTierSummary = computed(() => {
  if (selectedTier.value && !selectedTier.value.full_stats) return selectedTier.value.detail;
  return null;
});

// "Gear Tier" would wrongly imply a level-locked progression (rarity and level are actually
// independent — nothing stops a mythic-rarity item from existing at level 1); naming this by the axis
// it actually sorts on avoids that.
const TIER_PICKER_LABELS = {
  grade: 'Grade Scaling — tap to compare',
  rank: 'Ranks — tap to compare',
  signature: 'Signature Gear — tap to compare',
  rarity: 'Rarity Tier — tap to compare',
};
const tierPickerLabel = computed(
  () => TIER_PICKER_LABELS[selectedEntry.value?.details?.tier_kind] ?? 'Tiers — tap to compare'
);
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
          @click="selectCategory(c.key)"
          class="wiki-nav-btn"
          :class="{ 'is-active': activeCat === c.key }"
        >
          <span class="wiki-nav-btn__icon">{{ c.icon }}</span>
          {{ c.label }}
          <span class="wiki-nav-btn__count">{{ c.count }}</span>
        </button>
      </nav>
      <div class="wiki-sidebar__footer">
        <div class="wiki-sidebar__total">{{ entries.length.toLocaleString() }} entries across {{ categories.length }} categories</div>
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
        </div>
        <input
          v-model="query"
          placeholder="🔍 Search the wiki…"
          class="wiki-search-input"
        />
      </div>

      <div class="wiki-tally-row">
        <div v-for="(t, i) in activeCategory.tallies" :key="t.label" class="wiki-tally-card" :class="{ 'is-accent': i === 2 }">
          <div class="ox wiki-tally-card__value">{{ t.value }}</div>
          <div class="wiki-tally-card__label">{{ t.label.toUpperCase() }}</div>
        </div>
      </div>

      <div v-if="groupsForCategory.length" class="wiki-group-row">
        <button type="button" class="wiki-group-pill" :class="{ 'is-active': !activeGroup }" @click="activeGroup = null">
          All
        </button>
        <button
          v-for="g in groupsForCategory"
          :key="g"
          type="button"
          class="wiki-group-pill"
          :class="{ 'is-active': activeGroup === g }"
          @click="activeGroup = g"
        >
          {{ g }}
        </button>
      </div>

      <div v-for="sec in sections" :key="sec.label" class="wiki-section">
        <div class="wiki-section__head">
          <span class="ox wiki-section__label">{{ sec.label }}</span>
          <span class="wiki-section__count">{{ sec.cards.length }}</span>
          <div class="wiki-section__rule"></div>
        </div>
        <div class="wiki-entries-grid">
          <div
            v-for="e in sec.cards"
            :key="e.id"
            class="wiki-entry-card"
            @click="openEntry(e)"
          >
            <div class="wiki-entry__header">
              <div class="wiki-entry__icon" :style="{ background: e.image ? `url(${e.image}) center/cover` : TILE_BG }">
                <span v-if="!e.image">{{ e.g }}</span>
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
      </div>

      <div
        v-if="!loading && filteredEntries.length === 0"
        class="wiki-empty-state"
      >
        No entries match "{{ query }}".
      </div>
    </main>

    <div v-if="selectedEntry" class="wiki-modal-overlay" @click.self="closeModal">
      <div class="wiki-modal">
        <div class="wiki-modal__header">
          <div
            class="wiki-modal__icon"
            :style="{ background: selectedEntry.image ? `url(${selectedEntry.image}) center/cover` : TILE_BG }"
          >
            <span v-if="!selectedEntry.image">{{ selectedEntry.g }}</span>
          </div>
          <div class="wiki-modal__title-block">
            <div class="wiki-modal__crumb">solyx.gg / wiki / {{ selectedEntry.category }}</div>
            <div class="ox wiki-modal__name">{{ selectedEntry.name }}</div>
            <div class="wiki-modal__sub">{{ selectedEntry.sub }}</div>
            <div class="wiki-modal__chips">
              <span class="wiki-stat-chip" :style="{ background: RARITY_COLOR[selectedEntry.rarity] + '24', color: RARITY_COLOR[selectedEntry.rarity] }">{{ selectedEntry.rarity }}</span>
              <span v-if="selectedEntry.group" class="wiki-stat-chip" style="background:rgba(92,199,245,.16);color:#5cc7f5">{{ selectedEntry.group }}</span>
            </div>
          </div>
          <button v-if="navStack.length" type="button" class="wiki-modal__back" @click="goBack">← Back</button>
          <button type="button" class="wiki-modal__close" @click="closeModal">✕</button>
        </div>

        <p class="wiki-modal__desc">{{ selectedEntry.desc }}</p>

        <div v-if="selectedEntry.details?.tiers?.length" class="wiki-modal__tiers">
          <div class="wiki-modal__section-label">{{ tierPickerLabel }}</div>
          <button
            v-for="t in selectedEntry.details.tiers"
            :key="t.key"
            type="button"
            class="wiki-tier-row-btn"
            :class="{ 'is-active': selectedTierKey === t.key }"
            :style="{ '--tier-color': t.color }"
            @click="selectedTierKey = t.key"
          >
            <span class="wiki-tier-row-btn__dot" :style="{ background: t.color }"></span>
            <span class="wiki-tier-row-btn__label">{{ t.label }}</span>
            <span class="wiki-tier-row-btn__detail">{{ t.detail }}</span>
          </button>
        </div>

        <p v-if="modalTierSummary" class="wiki-modal__tier-summary">{{ modalTierSummary }}</p>

        <div v-if="modalStatRows.length" class="wiki-modal__stats-grid">
          <div v-for="(s, i) in modalStatRows" :key="i" class="wiki-modal__stat-cell">
            <template v-if="s.t">
              <div class="wiki-modal__stat-value" :style="{ color: s.color }">{{ s.t }}</div>
            </template>
            <template v-else>
              <div class="wiki-modal__stat-label">{{ s.label }}</div>
              <div class="ox wiki-modal__stat-value">{{ s.value }}</div>
            </template>
          </div>
        </div>

        <div v-if="selectedEntry.details?.acquisition?.length" class="wiki-modal__acquisition">
          <div class="wiki-modal__section-label">How to get it</div>
          <component
            :is="a.link?.id ? 'button' : 'div'"
            v-for="(a, i) in selectedEntry.details.acquisition"
            :key="i"
            type="button"
            class="wiki-modal__acquisition-row"
            :class="{ 'is-linked': a.link?.id }"
            @click="a.link?.id && openLink(a.link)"
          >
            <span class="wiki-modal__acquisition-glyph">{{ a.glyph }}</span>
            <span class="wiki-modal__acquisition-label">{{ a.label }}</span>
            <span class="wiki-modal__acquisition-detail">{{ a.detail }}</span>
          </component>
        </div>

        <div v-if="selectedEntry.details?.found_here?.length" class="wiki-modal__acquisition">
          <div class="wiki-modal__section-label">{{ selectedEntry.category === 'zones' ? 'Monsters found here' : selectedEntry.category === 'items' ? 'Used to craft' : selectedEntry.category === 'dungeons' ? 'Encounters' : 'Boss' }}</div>
          <component
            :is="f.link?.id ? 'button' : 'div'"
            v-for="(f, i) in selectedEntry.details.found_here"
            :key="i"
            type="button"
            class="wiki-modal__acquisition-row"
            :class="{ 'is-linked': f.link?.id }"
            @click="f.link?.id && openLink(f.link)"
          >
            <span class="wiki-modal__acquisition-glyph">{{ f.glyph }}</span>
            <span class="wiki-modal__acquisition-label">{{ f.name }}</span>
            <span class="wiki-modal__acquisition-detail">{{ f.detail }}</span>
          </component>
        </div>

        <div v-if="selectedEntry.artist" class="wiki-modal__artwork">
          <div class="wiki-modal__artwork-label">ARTWORK</div>
          <div class="wiki-modal__artwork-row">
            <div class="wiki-modal__artwork-icon" :style="{ background: `url(${selectedEntry.image}) center/cover` }"></div>
            <div>
              <div class="wiki-modal__artwork-name">Art by {{ selectedEntry.artist }}</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style lang="scss" src="./WikiPage.scss" scoped></style>
