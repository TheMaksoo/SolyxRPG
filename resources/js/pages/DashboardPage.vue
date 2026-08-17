<script setup>
import { ref, computed, onMounted } from 'vue';
import { useCharacterStore } from '../stores/character';
import { useRegen } from '../composables/regen';
import api from '../api/client';
import AdBanner from '../components/AdBanner.vue';
import WorldChat from '../components/WorldChat.vue';
import Skeleton from '../components/Skeleton.vue';
import { NAV } from '../navigation';

/** Gear counts as "low durability" once it drops to this fraction of max — worth a repair-pack nudge. */
const LOW_DURABILITY_PCT = 0.2;

const store = useCharacterStore();
// Shared HP/MP/energy regen projection (composables/regen.js) — same singleton state Battle reads, so
// the two pages never disagree and navigating between them never resets or re-seeds the projection.
// displayHp (not localHp) is what shows here — it automatically switches to the live in-combat HP
// whenever BattlePage reports an active battle, so this tile shows the exact same number Battle does
// even while a fight is in progress, instead of sitting frozen on the stale pre-battle HP.
const { displayHp, localMana, localEnergy, hpMax, mpMax, energyMax, isRegeneratingHp, isRegeneratingMana, isRegeneratingEnergy } = useRegen();

const daily = ref(null);
const battlePass = ref(null);
const leaders = ref([]);
const announcements = ref([]);
const craftQueue = ref([]);
const craftRecipes = ref([]);
const craftMaxSlots = ref(0);
const unclaimedQuestCount = ref(0);
const autoBattle = ref(null);
const autoGather = ref(null);
const railLoaded = ref(false);

// Several DO NEXT/shortcut entries below link to pages that are level-gated (see navigation.js) while
// their underlying condition (a quest completing, a daily reward being ready) can still trigger in the
// background for a character below that gate — without this, clicking the entry would bounce straight
// into the Level Required page instead of the page it promised. A whole shortcut/tab gets hidden
// entirely rather than shown locked, since a still-locked character has no use for a preview of a page
// it can't open yet.
function canAccess(path) {
  const entry = NAV.find((n) => n.path === path);
  if (!entry?.unlockLevel) return true;
  return (store.character?.level ?? 0) >= entry.unlockLevel;
}

async function loadRail() {
  const [dailyRes, passRes, lbRes, questRes, annRes, craftRes, recipeRes, autoBattleRes, autoGatherRes] = await Promise.all([
    api.get('/daily'),
    api.get('/battlepass'),
    api.get('/leaderboard'),
    api.get('/quests'),
    api.get('/announcements'),
    api.get('/crafting/queue'),
    api.get('/crafting/recipes'),
    api.get('/auto-battle'),
    api.get('/auto-gather'),
  ]);
  daily.value = dailyRes.data;
  battlePass.value = passRes.data.battle_pass;
  leaders.value = lbRes.data.leaderboard.slice(0, 6);
  unclaimedQuestCount.value = questRes.data.quests.filter((q) => q.completed && !q.claimed).length;
  announcements.value = annRes.data.announcements.slice(0, 4);
  craftQueue.value = craftRes.data.jobs;
  craftMaxSlots.value = craftRes.data.max_slots;
  craftRecipes.value = recipeRes.data.recipes;
  autoBattle.value = autoBattleRes.data;
  autoGather.value = autoGatherRes.data;
  railLoaded.value = true;
}

function timeAgo(isoString) {
  const seconds = Math.max(0, Math.round((Date.now() - new Date(isoString).getTime()) / 1000));
  if (seconds < 60) return `${seconds}s ago`;
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
  return `${Math.floor(seconds / 3600)}h ago`;
}

function formatDuration(totalSeconds) {
  const m = Math.floor(totalSeconds / 60);
  const s = totalSeconds % 60;
  return `${m}:${String(s).padStart(2, '0')}`;
}

const isCrafting = computed(() => craftQueue.value.length > 0);

/** True if the queue actually has a free slot and at least one recipe is both level-unlocked and
 * affordable right now — nudging "queue something up" is pointless if nothing's actually craftable. */
const hasActionableCraft = computed(
  () => craftQueue.value.length < craftMaxSlots.value && craftRecipes.value.some((r) => r.can_craft)
);

/** Fully broken gear contributes zero stats while still equipped (see Character::effectiveStats) — this is the
 * most urgent durability state, distinct from merely "running low". */
const brokenGear = computed(() =>
  (store.character?.inventory || []).filter((row) => row.equipped && row.durability_max && row.durability <= 0)
);

const nearBrokenGear = computed(() =>
  (store.character?.inventory || []).filter(
    (row) =>
      row.equipped &&
      row.durability_max &&
      row.durability > 0 &&
      row.durability / row.durability_max <= LOW_DURABILITY_PCT
  )
);

/** Send the player wherever they can actually act: Inventory if they're holding a repair pack to use right
 * now, otherwise Crafting's Repair Packs section so they can make one. */
const repairLinkTarget = computed(() => {
  const hasRepairPack = (store.character?.inventory || []).some(
    (row) => row.item?.type === 'repair_pack' && row.qty > 0
  );
  return hasRepairPack ? '/inventory' : { path: '/crafting', query: { section: 'repair_pack' } };
});

const hasUnclaimedBattlePass = computed(() => {
  const bp = battlePass.value;
  if (!bp) return false;
  const freeClaimed = bp.claimed_free_tiers || [];
  const premClaimed = bp.claimed_premium_tiers || [];
  for (let tier = 1; tier <= bp.tier; tier++) {
    if (!freeClaimed.includes(tier)) return true;
    if (bp.premium && !premClaimed.includes(tier)) return true;
  }
  return false;
});

// Cast to Number — the API can return these as numeric strings, and a naive `+` between a string
// and a number silently concatenates ("5" + 1 === "51") instead of adding.
const unspentAttributePoints = computed(() => Number(store.character?.attribute_points ?? 0));
const unspentSkillPoints = computed(() => Number(store.character?.skill_points ?? 0));
const unspentPoints = computed(() => unspentAttributePoints.value + unspentSkillPoints.value);

const xpPct = computed(() => {
  const currentXp = store.character?.xp ?? 0;
  const xpMax = store.character?.calculated_xp_max ?? 1;
  const xpMin = store.character?.calculated_xp_min ?? 0;
  const xpRange = xpMax - xpMin;
  const xpProgress = currentXp - xpMin;
  return Math.min(100, Math.round((xpProgress / xpRange) * 100));
});

const xpCurrentLevel = computed(() => {
  const currentXp = store.character?.xp ?? 0;
  const xpMin = store.character?.calculated_xp_min ?? 0;
  return Math.max(0, currentXp - xpMin);
});

const xpMaxLevel = computed(() => {
  const xpMax = store.character?.calculated_xp_max ?? 1;
  const xpMin = store.character?.calculated_xp_min ?? 0;
  return xpMax - xpMin;
});

const xpToGo = computed(() => Math.max(0, xpMaxLevel.value - xpCurrentLevel.value));

const CLASS_LABEL = { warrior: 'Warrior', mage: 'Mage', rogue: 'Rogue', ranger: 'Ranger' };
const classLabel = computed(() => CLASS_LABEL[store.character?.base_class] ?? store.character?.base_class ?? '');

// ---- DO NEXT — one merged, tagged list instead of a loose stack of differently-styled banners ----
const TAG_TONE = {
  Daily: '#4ade80',
  Quests: '#4ade80',
  'Battle Pass': '#4ade80',
  Gear: '#ff6a4d',
  Skills: '#eab308',
  Crafting: 'rgba(255,255,255,.4)',
};

const doNextItems = computed(() => {
  const items = [];
  if (daily.value?.can_claim && canAccess('/daily')) {
    items.push({ key: 'daily', text: 'Daily reward is ready to claim', tag: 'Daily', to: '/daily' });
  }
  if (unclaimedQuestCount.value > 0 && canAccess('/quests')) {
    items.push({
      key: 'quests',
      text: `${unclaimedQuestCount.value} completed quest${unclaimedQuestCount.value > 1 ? 's' : ''} waiting on you`,
      tag: 'Quests',
      to: '/quests',
    });
  }
  if (hasUnclaimedBattlePass.value && canAccess('/battle-pass')) {
    items.push({ key: 'battlepass', text: 'Battle Pass rewards ready to claim', tag: 'Battle Pass', to: '/battle-pass' });
  }
  if (brokenGear.value.length && canAccess(repairLinkTarget.value)) {
    items.push({
      key: 'broken',
      text: `${brokenGear.value.map((row) => row.item.name).join(', ')} ${brokenGear.value.length > 1 ? 'are' : 'is'} broken — repair now`,
      tag: 'Gear',
      to: repairLinkTarget.value,
    });
  }
  if (nearBrokenGear.value.length && canAccess(repairLinkTarget.value)) {
    items.push({
      key: 'near-broken',
      text: `${nearBrokenGear.value.map((row) => row.item.name).join(', ')} almost broken`,
      tag: 'Gear',
      to: repairLinkTarget.value,
    });
  }
  if (hasActionableCraft.value && canAccess('/crafting')) {
    items.push({ key: 'craft', text: 'Crafting queue is empty', tag: 'Crafting', to: '/crafting' });
  }
  if (unspentPoints.value > 0 && canAccess('/skills')) {
    const parts = [];
    if (unspentAttributePoints.value > 0) parts.push(`${unspentAttributePoints.value} attribute point${unspentAttributePoints.value > 1 ? 's' : ''}`);
    if (unspentSkillPoints.value > 0) parts.push(`${unspentSkillPoints.value} skill point${unspentSkillPoints.value > 1 ? 's' : ''}`);
    items.push({ key: 'points', text: `${parts.join(' and ')} unspent`, tag: 'Skills', to: '/skills' });
  }
  return items.map((item) => ({ ...item, tone: TAG_TONE[item.tag] ?? 'rgba(255,255,255,.4)' }));
});

// Full list on desktop; mobile starts at 3 with a "Show N more" toggle (mirrors the reference design).
const doNextExpanded = ref(false);
const doNextVisible = computed(() => (doNextExpanded.value ? doNextItems.value : doNextItems.value.slice(0, 3)));
const doNextMoreCount = computed(() => Math.max(0, doNextItems.value.length - 3));

// ---- JUMP BACK IN — the 3 places you actually act, each with a live status pill ----
const shortcuts = computed(() => {
  const list = [
    {
      key: 'battle',
      glyph: '⚔',
      title: 'Battle',
      to: '/battle',
      sub: store.character?.zone
        ? `${store.character.zone.name} · Danger: ${store.character.zone.danger}`
        : 'Pick a zone to start fighting',
      pill: autoBattle.value?.active ? `Training ${formatDuration(autoBattle.value.seconds_remaining)}` : 'Ready',
      tone: autoBattle.value?.active ? '#e8482f' : 'rgba(255,255,255,.35)',
    },
  ];
  if (canAccess('/crafting')) {
    const freeSlots = craftMaxSlots.value - craftQueue.value.length;
    list.push({
      key: 'crafting',
      glyph: '🔨',
      title: 'Crafting',
      to: '/crafting',
      sub: freeSlots > 0 ? `${freeSlots} slot${freeSlots === 1 ? '' : 's'} free` : 'Queue full',
      pill: isCrafting.value ? 'Crafting' : 'Idle',
      tone: isCrafting.value ? '#4ade80' : 'rgba(255,255,255,.35)',
    });
  }
  if (canAccess('/trade-skills')) {
    list.push({
      key: 'gathering',
      glyph: '⛏',
      title: 'Gathering',
      to: '/trade-skills',
      sub: autoGather.value?.active ? `Auto-${autoGather.value.skill}` : 'Not gathering',
      pill: autoGather.value?.active ? 'Live' : 'Idle',
      tone: autoGather.value?.active ? '#4ade80' : 'rgba(255,255,255,.35)',
    });
  }
  return list;
});

// ---- TAVERN RAIL — tabbed Chat/Ranks/News instead of three stacked cards ----
const tavernTab = ref('chat');
const tavernTabs = computed(() => {
  const tabs = [{ key: 'chat', label: 'Chat' }];
  if (canAccess('/leaderboard')) tabs.push({ key: 'ranks', label: 'Ranks' });
  tabs.push({ key: 'news', label: 'News' });
  return tabs;
});

const RANK_COLOR = { 1: '#eab308', 2: '#cbd5e1', 3: '#d08c4a' };
function rankColor(rank) {
  return RANK_COLOR[rank] ?? 'rgba(255,255,255,.3)';
}

onMounted(() => {
  store.fetch();
  loadRail();
});
</script>

<template>
  <!-- Every card shell below is always rendered — loading and loaded states differ only in what's
  inside a card, never in the card's own box (padding/width/border). That's what actually stops the
  page from jumping: there's no separate "skeleton dashboard" tree with its own guessed heights being
  swapped for a completely different "real dashboard" tree, so there's nothing to mismatch. -->
  <div class="dashboard">
    <div class="dashboard__main">
      <!-- HERO — level ring, name/class/zone, XP bar, and the two primary actions. -->
      <div class="hero-card">
        <template v-if="store.character">
          <div class="hero-card__ring dash-fade-in" :style="{ '--xp-pct': xpPct }">
            <div class="hero-card__ring-inner">
              <div class="ox hero-card__level">{{ store.character.calculated_level }}</div>
              <div class="hero-card__level-tag">LEVEL</div>
            </div>
          </div>
          <div class="hero-card__body dash-fade-in">
            <div class="hero-card__title-row">
              <span class="ox hero-card__name">{{ store.character.name }}</span>
              <span class="hero-card__class-badge">{{ classLabel }}</span>
            </div>
            <div class="hero-card__zone">
              {{ store.character.zone ? `${store.character.zone.name} · Danger: ${store.character.zone.danger}` : 'Pick a zone in World Map to start fighting' }}
            </div>
            <div class="hero-card__xp-track">
              <div class="hero-card__xp-fill" :style="{ width: xpPct + '%' }"></div>
            </div>
            <div class="hero-card__xp-numbers">
              <span>{{ xpCurrentLevel.toLocaleString() }} / {{ xpMaxLevel.toLocaleString() }} XP</span>
              <span>{{ xpToGo.toLocaleString() }} to Lv.{{ store.character.calculated_level + 1 }}</span>
            </div>
          </div>
          <div class="hero-card__actions dash-fade-in">
            <router-link to="/battle" class="hero-card__cta">Continue fighting</router-link>
            <router-link v-if="canAccess('/world-map')" to="/world-map" class="hero-card__cta-secondary">Change zone</router-link>
          </div>
        </template>
        <template v-else>
          <Skeleton variant="circle" width="94px" height="94px" />
          <div class="hero-card__body">
            <Skeleton height="22px" width="55%" style="margin-bottom: 8px" />
            <Skeleton height="12px" width="70%" style="margin-bottom: 14px" />
            <Skeleton height="8px" width="100%" />
          </div>
        </template>
      </div>

      <!-- VITALS -->
      <div class="vitals-grid">
        <div class="vital-tile">
          <template v-if="store.character">
            <div class="vital-tile__head">
              <span class="vital-tile__label">HP</span>
              <span class="ox vital-tile__value vital-tile__value--hp" :class="{ 'is-regenerating': isRegeneratingHp }">{{ Math.round(displayHp) }} / {{ hpMax }}</span>
            </div>
            <div class="vital-tile__track"><div class="vital-tile__fill vital-tile__fill--hp" :style="{ width: Math.min(100, Math.round((displayHp / hpMax) * 100)) + '%' }"></div></div>
            <div class="vital-tile__note">💚 healing +{{ store.regenPerTick }} / 5s</div>
          </template>
          <template v-else>
            <Skeleton height="14px" width="60%" style="margin-bottom: 10px" />
            <Skeleton height="10px" width="100%" />
          </template>
        </div>
        <div class="vital-tile">
          <template v-if="store.character">
            <div class="vital-tile__head">
              <span class="vital-tile__label">MANA</span>
              <span class="ox vital-tile__value vital-tile__value--mana" :class="{ 'is-regenerating': isRegeneratingMana }">{{ Math.round(localMana) }} / {{ mpMax }}</span>
            </div>
            <div class="vital-tile__track"><div class="vital-tile__fill vital-tile__fill--mana" :style="{ width: Math.min(100, Math.round((localMana / mpMax) * 100)) + '%' }"></div></div>
            <div class="vital-tile__note">💧 regen +{{ store.manaRegenPerTick }} / 5s</div>
          </template>
          <template v-else>
            <Skeleton height="14px" width="60%" style="margin-bottom: 10px" />
            <Skeleton height="10px" width="100%" />
          </template>
        </div>
        <div class="vital-tile">
          <template v-if="store.character">
            <div class="vital-tile__head">
              <span class="vital-tile__label">ENERGY</span>
              <span class="ox vital-tile__value vital-tile__value--energy" :class="{ 'is-regenerating': isRegeneratingEnergy }">{{ Math.round(localEnergy) }} / {{ energyMax }}</span>
            </div>
            <div class="vital-tile__track"><div class="vital-tile__fill vital-tile__fill--energy" :style="{ width: Math.min(100, Math.round((localEnergy / energyMax) * 100)) + '%' }"></div></div>
            <div class="vital-tile__note">⚡ regen +{{ store.energyRegenPerTick }} / 5s</div>
          </template>
          <template v-else>
            <Skeleton height="14px" width="60%" style="margin-bottom: 10px" />
            <Skeleton height="10px" width="100%" />
          </template>
        </div>
        <div class="vital-tile">
          <template v-if="store.character">
            <div class="vital-tile__head">
              <span class="vital-tile__label">GOLD</span>
              <span class="ox vital-tile__value vital-tile__value--gold">{{ store.character.gold.toLocaleString() }}</span>
            </div>
            <div class="vital-tile__track"><div class="vital-tile__fill vital-tile__fill--gold" style="width: 100%"></div></div>
            <div class="vital-tile__note">&nbsp;</div>
          </template>
          <template v-else>
            <Skeleton height="14px" width="60%" style="margin-bottom: 10px" />
            <Skeleton height="10px" width="100%" />
          </template>
        </div>
      </div>

      <!-- DO NEXT — every actionable alert merged into one tagged list instead of a loose stack of
      differently-styled banners. -->
      <div v-if="doNextItems.length" class="section">
        <div class="section__head">
          <span class="ox section__eyebrow">DO NEXT</span>
          <span class="section__count">{{ doNextItems.length }} thing{{ doNextItems.length > 1 ? 's' : '' }} waiting</span>
        </div>
        <div class="do-next-card">
          <router-link v-for="item in doNextVisible" :key="item.key" :to="item.to" class="do-next-row">
            <span class="do-next-row__dot" :style="{ background: item.tone }"></span>
            <span class="do-next-row__text">{{ item.text }}</span>
            <span class="do-next-row__tag" :style="{ color: item.tone, borderColor: `${item.tone}44`, background: `${item.tone}12` }">{{ item.tag }}</span>
            <span class="do-next-row__chevron">›</span>
          </router-link>
          <button v-if="!doNextExpanded && doNextMoreCount > 0" type="button" class="do-next-more" @click="doNextExpanded = true">
            Show {{ doNextMoreCount }} more
          </button>
        </div>
      </div>

      <!-- JUMP BACK IN — the 3 places you actually act, each with a live status pill. -->
      <div class="section">
        <div class="ox section__eyebrow">JUMP BACK IN</div>
        <div class="shortcuts-grid">
          <router-link v-for="s in shortcuts" :key="s.key" :to="s.to" class="shortcut-tile">
            <div class="shortcut-tile__head">
              <span class="shortcut-tile__glyph">{{ s.glyph }}</span>
              <span class="shortcut-tile__pill" :style="{ color: s.tone, borderColor: `${s.tone}44`, background: `${s.tone}12` }">{{ s.pill }}</span>
            </div>
            <div class="ox shortcut-tile__title">{{ s.title }}</div>
            <div class="shortcut-tile__sub">{{ s.sub }}</div>
          </router-link>
        </div>
      </div>

      <AdBanner variant="inline" />
    </div>

    <!-- TAVERN RAIL — tabbed Chat/Ranks/News instead of three stacked cards. -->
    <div class="tavern-rail">
      <div class="tavern-rail__tabs">
        <button
          v-for="t in tavernTabs"
          :key="t.key"
          type="button"
          class="tavern-rail__tab"
          :class="{ 'is-active': tavernTab === t.key }"
          @click="tavernTab = t.key"
        >
          {{ t.label }}
        </button>
      </div>
      <div class="tavern-rail__body" :class="{ 'tavern-rail__body--chat': tavernTab === 'chat' }">
        <WorldChat v-if="tavernTab === 'chat'" embedded />

        <div v-else-if="tavernTab === 'ranks'" class="tavern-ranks">
          <template v-if="railLoaded">
            <div v-for="l in leaders" :key="l.character_id" class="tavern-rank-row" :style="l.banner ? { background: l.banner } : null">
              <span class="ox tavern-rank-row__rank" :style="{ color: rankColor(l.rank) }">{{ l.rank }}</span>
              <span v-if="l.icon" class="tavern-rank-row__icon">{{ l.icon }}</span>
              <span class="tavern-rank-row__name" :style="l.name_color ? { color: l.name_color } : null">{{ l.name }}</span>
              <span class="tavern-rank-row__level">Lv.{{ l.level }}</span>
            </div>
            <div v-if="!leaders.length" class="rail-empty">No ranked characters yet.</div>
          </template>
          <template v-else>
            <Skeleton v-for="i in 6" :key="i" height="16px" style="margin: 8px 0" />
          </template>
        </div>

        <div v-else class="tavern-news">
          <template v-if="railLoaded">
            <div v-for="an in announcements" :key="an.id" class="tavern-news-row">
              <div class="tavern-news-row__head">
                <span class="tavern-news-row__kind">GM</span>
                <span class="tavern-news-row__when">{{ timeAgo(an.created_at) }}</span>
              </div>
              <div class="tavern-news-row__text">{{ an.body }}</div>
              <div class="tavern-news-row__author">— {{ an.gm?.name }}</div>
            </div>
            <div v-if="!announcements.length" class="rail-empty">No announcements yet.</div>
          </template>
          <template v-else>
            <Skeleton v-for="i in 4" :key="i" height="30px" style="margin: 8px 0" />
          </template>
        </div>
      </div>
      <AdBanner variant="sidebar" />
    </div>
  </div>
</template>

<style lang="scss" src="./DashboardPage.scss" scoped></style>
