<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../api/client';
import Skeleton from '../components/Skeleton.vue';
import { TAG_META, tagMeta } from '../utils/changelogMeta';

const entries = ref([]);
const loading = ref(true);
const filter = ref('all'); // 'all' | 'feature' | 'fix' | 'balance' | 'misc'
const expanded = ref(new Set());

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get('/changelog');
    entries.value = data.entries;
  } finally {
    loading.value = false;
  }
}

const filtered = computed(() => {
  if (filter.value === 'all') return entries.value;
  return entries.value.filter((e) => e.tag === filter.value);
});

const tagCounts = computed(() => {
  const counts = {};
  for (const entry of entries.value) counts[entry.tag] = (counts[entry.tag] ?? 0) + 1;
  return counts;
});

function formatDate(isoString) {
  return new Date(isoString).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

function toggleDetails(id) {
  const next = new Set(expanded.value);
  if (next.has(id)) next.delete(id);
  else next.add(id);
  expanded.value = next;
}

// Splits a details blob into renderable blocks: "- " prefixed lines are grouped into a <ul>,
// everything else becomes its own <p>, mirroring how `body` already uses pre-wrap.
function detailBlocks(details) {
  const lines = (details ?? '')
    .split('\n')
    .map((line) => line.trim())
    .filter(Boolean);

  const blocks = [];
  for (const line of lines) {
    if (line.startsWith('- ')) {
      const last = blocks[blocks.length - 1];
      if (last?.type === 'ul') last.items.push(line.slice(2));
      else blocks.push({ type: 'ul', items: [line.slice(2)] });
    } else {
      blocks.push({ type: 'p', text: line });
    }
  }
  return blocks;
}

onMounted(load);
</script>

<template>
  <div class="changelog-page">
    <div class="changelog-header">
      <div class="changelog-header__icon">📜</div>
      <h1 class="ox changelog-title">Changelog</h1>
      <span v-if="entries.length" class="changelog-header__version">Current version: v{{ entries[0].version }}</span>
    </div>

    <div v-if="!loading && entries.length" class="changelog-tabs">
      <button class="changelog-tab" :class="{ 'changelog-tab--active': filter === 'all' }" @click="filter = 'all'">
        All
        <span class="changelog-tab__count">{{ entries.length }}</span>
      </button>
      <button
        v-for="(meta, tag) in TAG_META"
        :key="tag"
        v-show="tagCounts[tag]"
        class="changelog-tab"
        :class="{ 'changelog-tab--active': filter === tag }"
        @click="filter = tag"
      >
        {{ meta.icon }} {{ meta.label }}
        <span class="changelog-tab__count">{{ tagCounts[tag] }}</span>
      </button>
    </div>

    <div v-if="loading" class="changelog-skeleton">
      <Skeleton height="86px" :count="5" />
    </div>
    <div v-else class="changelog-list">
      <div v-for="entry in filtered" :key="entry.id" class="changelog-row" :class="`changelog-row--${entry.tag}`">
        <div class="changelog-row__accent"></div>
        <div class="changelog-row__body-wrap">
          <div class="changelog-row__head">
            <span class="changelog-row__tag" :class="`changelog-row__tag--${entry.tag}`">
              {{ tagMeta(entry.tag).icon }} {{ tagMeta(entry.tag).label }}
            </span>
            <span class="ox changelog-row__title">{{ entry.title }}</span>
            <span class="changelog-row__version">v{{ entry.version }}</span>
            <span class="changelog-row__date">{{ formatDate(entry.published_at) }}</span>
          </div>
          <p class="changelog-row__body">{{ entry.body }}</p>
          <template v-if="entry.details">
            <button
              type="button"
              class="changelog-row__toggle"
              @click="toggleDetails(entry.id)"
            >
              {{ expanded.has(entry.id) ? '▴ Hide details' : '▾ Show details' }}
            </button>
            <div v-if="expanded.has(entry.id)" class="changelog-row__details">
              <template v-for="(block, i) in detailBlocks(entry.details)" :key="i">
                <p v-if="block.type === 'p'" class="changelog-row__details-p">{{ block.text }}</p>
                <ul v-else class="changelog-row__details-ul">
                  <li v-for="(item, j) in block.items" :key="j">{{ item }}</li>
                </ul>
              </template>
            </div>
          </template>
        </div>
      </div>
      <p v-if="!filtered.length" class="changelog-empty">
        {{ entries.length ? 'No updates match this filter.' : 'No updates logged yet.' }}
      </p>
    </div>
  </div>
</template>

<style lang="scss" src="./ChangelogPage.scss" scoped></style>
