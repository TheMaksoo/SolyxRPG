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
  <div style="display:flex;min-height:100vh">
    <aside
      style="width:230px;flex:none;background:#0e0e10;border-right:1px solid rgba(255,255,255,.06);position:sticky;top:0;height:100vh;overflow-y:auto;padding:18px 12px"
    >
      <div style="display:flex;align-items:center;gap:10px;padding:0 6px 16px">
        <img src="/images/solyx-icon.png" alt="" style="width:28px;height:28px" />
        <div>
          <div class="ox" style="font-weight:800;letter-spacing:.06em;font-size:15px">SOLYX</div>
          <div style="font-size:10px;color:rgba(255,255,255,.4);letter-spacing:.1em">WIKI</div>
        </div>
      </div>
      <nav>
        <button
          v-for="c in categories"
          :key="c.key"
          @click="activeCat = c.key"
          :style="{
            display: 'flex',
            alignItems: 'center',
            gap: '8px',
            width: '100%',
            textAlign: 'left',
            background: activeCat === c.key ? 'rgba(232,72,47,.13)' : 'transparent',
            border: 'none',
            borderLeft: `3px solid ${activeCat === c.key ? '#e8482f' : 'transparent'}`,
            color: activeCat === c.key ? '#fff' : 'rgba(255,255,255,.62)',
            padding: '10px 10px',
            borderRadius: '8px',
            fontSize: '13.5px',
            fontWeight: activeCat === c.key ? '700' : '500',
            cursor: 'pointer',
            marginBottom: '2px',
          }"
        >
          <span style="width:22px;display:inline-block;text-align:center">{{ c.icon }}</span>
          {{ c.label }}
          <span style="margin-left:auto;font-size:11px;color:rgba(255,255,255,.35)">{{ c.count }}</span>
        </button>
      </nav>
      <div style="padding:14px 8px 0;margin-top:12px;border-top:1px solid rgba(255,255,255,.06)">
        <router-link to="/" style="font-size:12px;color:rgba(255,255,255,.5)">← Back to game</router-link>
      </div>
    </aside>

    <main style="flex:1;min-width:0;padding:26px 30px;max-width:1300px">
      <div style="font-size:12px;color:rgba(255,255,255,.35);margin-bottom:6px">
        solyx.gg / wiki / {{ activeCategory.key }}
      </div>
      <div
        style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;margin-bottom:20px"
      >
        <div style="display:flex;align-items:center;gap:12px">
          <div style="font-size:28px">{{ activeCategory.icon }}</div>
          <h1 class="ox" style="font-size:28px;font-weight:800;margin:0">{{ activeCategory.label }}</h1>
          <span
            style="font-size:12px;color:rgba(255,255,255,.4);background:#151517;border:1px solid rgba(255,255,255,.08);padding:3px 10px;border-radius:20px"
            >{{ filteredEntries.length }} entries</span
          >
        </div>
        <input
          v-model="query"
          placeholder="🔍 Search the wiki…"
          style="width:280px;max-width:100%;background:#151517;border:1px solid rgba(255,255,255,.12);border-radius:10px;padding:10px 14px;color:#fff;font-size:14px;outline:none"
        />
      </div>

      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px">
        <div
          v-for="e in filteredEntries"
          :key="e.id"
          style="background:#151517;border:1px solid rgba(255,255,255,.07);border-radius:13px;padding:18px"
        >
          <div style="display:flex;align-items:flex-start;gap:13px;margin-bottom:10px">
            <div
              :style="{
                width: '50px',
                height: '50px',
                flex: 'none',
                borderRadius: '11px',
                background: TILE_BG,
                display: 'grid',
                placeItems: 'center',
                fontSize: '26px',
              }"
            >
              {{ e.g }}
            </div>
            <div style="flex:1;min-width:0">
              <div class="ox" :style="{ fontWeight: 700, fontSize: '16px', color: RARITY_COLOR[e.rarity] }">
                {{ e.name }}
              </div>
              <div style="font-size:11px;color:rgba(255,255,255,.45);margin-top:2px">{{ e.sub }}</div>
            </div>
          </div>
          <div style="font-size:12.5px;color:rgba(255,255,255,.6);line-height:1.5;margin-bottom:12px">
            {{ e.desc }}
          </div>
          <div style="display:flex;gap:6px;flex-wrap:wrap">
            <span
              v-for="(st, i) in e.stats"
              :key="i"
              :style="{
                fontSize: '11px',
                background: statBg(st),
                color: st.color,
                padding: '3px 9px',
                borderRadius: '6px',
                fontWeight: 600,
              }"
              >{{ st.t }}</span
            >
          </div>
        </div>
      </div>

      <div
        v-if="!loading && filteredEntries.length === 0"
        style="text-align:center;padding:60px 20px;color:rgba(255,255,255,.4)"
      >
        No entries match "{{ query }}".
      </div>
    </main>
  </div>
</template>
