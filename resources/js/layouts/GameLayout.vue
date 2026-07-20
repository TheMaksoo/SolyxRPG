<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { NAV, NAV_FOOTER } from '../navigation';
import { useCharacterStore } from '../stores/character';
import { useAuthStore } from '../stores/auth';
import api from '../api/client';
import TutorialOverlay from '../components/TutorialOverlay.vue';
import Toast from '../components/Toast.vue';

const route = useRoute();
const characterStore = useCharacterStore();
const auth = useAuthStore();
const activeLabel = computed(
  () => [...NAV, ...NAV_FOOTER].find((n) => n.path === route.path)?.label ?? ''
);
const visibleNavFooter = computed(() =>
  NAV_FOOTER.filter((n) => n.path !== '/admin' || ['gm', 'owner'].includes(auth.user?.role))
);

const unreadCount = ref(0);

async function loadUnread() {
  const { data } = await api.get('/inbox');
  unreadCount.value = data.items.filter((i) => i.invite).length;
}

// One "!" badge count per sidebar path — quests/battle-pass/daily rewards ready to claim, pending party
// invites, pending friend requests. Refetched on every route change (cheap, single request) so claiming
// something updates the sidebar right away, plus a slow poll as a fallback for things that tick over on
// their own (e.g. a fresh daily reward at midnight).
const navBadges = ref({});
const BADGE_PATH = {
  quests: '/quests',
  battle_pass: '/battle-pass',
  daily: '/daily',
  party_invites: '/party',
  friend_requests: '/friends',
};
let badgePollTimer = null;

async function loadNavBadges() {
  const { data } = await api.get('/nav-badges');
  const next = {};
  for (const [key, path] of Object.entries(BADGE_PATH)) {
    next[path] = data[key] ?? 0;
  }
  navBadges.value = next;
}

function badgeFor(path) {
  return navBadges.value[path] ?? 0;
}

function isLocked(n) {
  return !!n.unlockLevel && (characterStore.character?.level ?? 0) < n.unlockLevel;
}

const expandedLocked = ref(null);
function toggleLockedHint(path) {
  expandedLocked.value = expandedLocked.value === path ? null : path;
}

// Highlights sidebar entries that just crossed their level gate, cleared a few seconds after the
// level-up toast so the glow reads as "new" rather than becoming a permanent decoration.
const justUnlocked = ref(new Set());
let unlockHighlightTimer = null;

const levelUpMessage = ref('');
let levelUpMessageTimer = null;

watch(
  () => characterStore.character?.level,
  (newLevel, oldLevel) => {
    if (!oldLevel || !newLevel || newLevel <= oldLevel) return;
    const unlocked = NAV.filter((n) => n.unlockLevel > oldLevel && n.unlockLevel <= newLevel);
    clearTimeout(levelUpMessageTimer);
    if (unlocked.length) {
      const names = unlocked.map((n) => n.label).join(', ');
      levelUpMessage.value = `Level up! You reached Lv.${newLevel} — unlocked: ${names}`;
      clearTimeout(unlockHighlightTimer);
      justUnlocked.value = new Set(unlocked.map((n) => n.path));
      unlockHighlightTimer = setTimeout(() => {
        justUnlocked.value = new Set();
      }, 10000);
    } else {
      levelUpMessage.value = `Level up! You reached Lv.${newLevel}`;
    }
    levelUpMessageTimer = setTimeout(() => {
      levelUpMessage.value = '';
    }, 4000);
  }
);

watch(() => route.path, loadNavBadges);

onMounted(() => {
  if (!characterStore.character) characterStore.fetch();
  loadUnread();
  loadNavBadges();
  badgePollTimer = setInterval(loadNavBadges, 30000);
});

onUnmounted(() => {
  clearInterval(badgePollTimer);
});
</script>

<template>
  <div class="game-layout">
    <Toast :message="levelUpMessage" type="success" />
    <aside class="sidebar">
      <div class="sidebar__brand">
        <img src="/images/solyx-icon.png" alt="" class="sidebar__logo" />
        <div>
          <div class="ox sidebar__brand-name">SOLYX</div>
          <div class="sidebar__brand-tag">WEB GAME</div>
        </div>
      </div>
      <nav class="sidebar__nav">
        <template v-for="n in NAV" :key="n.path">
          <router-link v-if="!isLocked(n)" :to="n.path" custom v-slot="{ navigate, isActive }">
            <button
              @click="navigate"
              class="sidebar__nav-btn"
              :class="{ 'is-active': isActive, 'is-newly-unlocked': justUnlocked.has(n.path) }"
            >
              <span class="sidebar__nav-icon">{{ n.icon }}</span>
              {{ n.label }}
              <span v-if="justUnlocked.has(n.path)" class="sidebar__nav-new">NEW</span>
              <span v-if="badgeFor(n.path) > 0" class="sidebar__nav-badge">{{ badgeFor(n.path) }}</span>
            </button>
          </router-link>
          <div v-else class="sidebar__nav-locked">
            <button
              class="sidebar__nav-btn sidebar__nav-btn--locked"
              type="button"
              @click="toggleLockedHint(n.path)"
            >
              <span class="sidebar__nav-icon">🔒</span>
              {{ n.label }}
              <span class="sidebar__nav-lvl">Lv.{{ n.unlockLevel }}</span>
            </button>
            <p v-if="expandedLocked === n.path" class="sidebar__nav-hint">{{ n.unlockHint }}</p>
          </div>
        </template>
      </nav>
      <div class="sidebar__footer">
        <router-link
          v-for="n in visibleNavFooter"
          :key="n.path"
          :to="n.path"
          class="sidebar__footer-link"
        >
          <span class="sidebar__footer-icon">{{ n.icon }}</span>
          {{ n.label }}
        </router-link>
      </div>
    </aside>

    <div class="layout-main-col">
      <header class="topbar">
        <span class="ox topbar__title"
          >Solyx Web Game <span class="topbar__title-sep">—</span>
          <span class="topbar__title-active">{{ activeLabel }}</span></span
        >
        <div class="topbar__actions">
          <router-link to="/inbox" class="topbar__inbox">
            🔔
            <span v-if="unreadCount > 0" class="topbar__inbox-badge">{{ unreadCount }}</span>
          </router-link>
          <div v-if="characterStore.character" class="topbar__pill topbar__pill--gold">
            <span class="topbar__pill-icon">◉</span> {{ characterStore.character.gold }}
          </div>
          <router-link v-if="characterStore.character" to="/gem-store" class="topbar__pill topbar__pill--gems">
            <span class="topbar__pill-icon">◆</span> {{ characterStore.character.gems }}
          </router-link>
        </div>
      </header>
      <main class="layout-content">
        <router-view />
      </main>
    </div>

    <TutorialOverlay v-if="characterStore.character && !characterStore.character.tutorial_seen" />
  </div>
</template>

<style lang="scss" src="./GameLayout.scss" scoped></style>
