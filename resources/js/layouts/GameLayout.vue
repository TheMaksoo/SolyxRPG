<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { preloadRoute } from '../router';
import { NAV, NAV_FOOTER } from '../navigation';
import { useGameTick } from '../composables/gameTick';
import { useCharacterStore } from '../stores/character';
import { useAuthStore } from '../stores/auth';
import { usePvpQueueStore } from '../stores/pvpQueue';
import { useAutoGatherStore } from '../stores/autoGather';
import { useCraftingQueueStore } from '../stores/craftingQueue';
import { useGatherCooldownsStore } from '../stores/gatherCooldowns';
import { useAutoBattleStore } from '../stores/autoBattle';
import api from '../api/client';
import TutorialOverlay from '../components/TutorialOverlay.vue';
import Toast from '../components/Toast.vue';
import BugReportWidget from '../components/BugReportWidget.vue';

const route = useRoute();
const router = useRouter();
const characterStore = useCharacterStore();
const auth = useAuthStore();
const pvpQueue = usePvpQueueStore();
const autoGather = useAutoGatherStore();
const craftingQueue = useCraftingQueueStore();
const gatherCooldowns = useGatherCooldownsStore();
const autoBattle = useAutoBattleStore();
const { tickCount } = useGameTick();
const activeLabel = computed(
  () => [...NAV, ...NAV_FOOTER].find((n) => n.path === route.path)?.label ?? ''
);
const visibleNavFooter = computed(() =>
  NAV_FOOTER.filter((n) => n.path !== '/admin' || ['gm', 'owner'].includes(auth.user?.role))
);

// A feature flag with LIVE off (and, for non-testers, TESTERS off too) means the feature is fully
// unreachable — hide its sidebar entry entirely rather than showing a locked tab that just 403s.
const visibleNav = computed(() => NAV.filter((n) => !n.flagKey || auth.featureAccess[n.flagKey] !== false));

const unreadCount = ref(0);
const activePlayersHour = ref(null);
const bugReportWidget = ref(null);
const gameVersion = ref(null);

async function loadGameVersion() {
  try {
    const { data } = await api.get('/changelog/current');
    gameVersion.value = data.version;
  } catch {
    // Sidebar tag is a nice-to-have — silently skip if it fails.
  }
}

// Settings > Preferences > "Reduce motion" — toggles a root class that CSS animations (e.g. the topbar
// bug-report glow) key off, rather than a fake preference with no actual effect.
watch(
  () => auth.user?.preferences?.reduce_motion,
  (reduceMotion) => {
    document.documentElement.classList.toggle('reduce-motion', !!reduceMotion);
  },
  { immediate: true }
);

async function loadActivePlayers() {
  try {
    const { data } = await api.get('/stats/public');
    activePlayersHour.value = data.players_active_hour;
  } catch {
    // Sidebar stat is a nice-to-have — silently skip if it fails.
  }
}

async function loadUnread() {
  const { data } = await api.get('/inbox');
  // Every actionable/unread notification counts — invites (friend requests, etc.) plus any unread mail —
  // not just invites, so the bell badge reflects the same "unread" state the Inbox page itself highlights.
  unreadCount.value = data.items.filter((i) => i.invite || (i.type === 'mail' && !i.read)).length;
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
  mail: '/inbox',
  crafting: '/crafting',
  dungeons: '/dungeons',
  pvp: '/pvp',
  referrals: '/referrals',
};
let badgePollTimer = null;
const badgesUpdatedAt = ref(0);

async function loadNavBadges() {
  const { data } = await api.get('/nav-badges');
  const next = {};
  for (const [key, path] of Object.entries(BADGE_PATH)) {
    next[path] = data[key] ?? 0;
  }
  navBadges.value = next;
}

async function checkForBadgeUpdates() {
  try {
    const { data } = await api.get('/status/check');
    // Only fetch full badges if they changed
    if (data.badges_updated_at > badgesUpdatedAt.value) {
      badgesUpdatedAt.value = data.badges_updated_at;
      await loadNavBadges();
    }
  } catch {
    // silent
  }
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

function formatMmSs(totalSeconds) {
  const total = Math.max(0, Math.floor(totalSeconds || 0));
  const m = Math.floor(total / 60);
  const s = total % 60;
  return `${m}:${String(s).padStart(2, '0')}`;
}

// PvP matchmaking runs in a global store (see stores/pvpQueue.js) precisely so it isn't tied to whether
// /pvp happens to be mounted — this pill is the only always-visible sign the search is still running, and
// its own cancel button is the fastest way to back out without navigating to the arena first.
const pvpToast = ref('');
let pvpToastTimer = null;
const pvpSearchDuration = computed(() => formatMmSs(pvpQueue.elapsedSeconds));

watch(() => pvpQueue.matchFoundId, () => {
  pvpToast.value = 'Opponent found! Heading to the arena...';
  clearTimeout(pvpToastTimer);
  pvpToastTimer = setTimeout(() => { pvpToast.value = ''; }, 4000);
  if (route.path !== '/pvp') {
    router.push('/pvp');
  }
});

// Same idea as the PvP pill, for the other two long-running background timers: Auto-Gather (stores/
// autoGather.js) and the Crafting queue (stores/craftingQueue.js). Both already re-derive their own
// countdown live off an absolute timestamp, so this is purely display formatting.
const autoGatherDuration = computed(() => formatMmSs(autoGather.secondsRemaining));
const craftingDuration = computed(() => formatMmSs(craftingQueue.secondsRemaining));
const gatherCooldownDuration = computed(() => formatMmSs(gatherCooldowns.secondsRemaining));
const autoBattleDuration = computed(() => formatMmSs(autoBattle.secondsRemaining));

// Auto-Gather and Auto-Battle's own status endpoints have a side effect (they tick queued gathering /
// fights forward, spending Energy or HP/MP) — polling them from here just to display a topbar countdown
// would silently spend those resources on every page, not just Gathering/Battle. Both stores are instead
// pure display projections seeded from Character's own fields (already present on every /character
// fetch), and ticked by the shared game heartbeat — no network request of their own.
watch(() => characterStore.character, (character) => {
  if (!character) return;
  autoGather.setFromCharacter(character);
  autoBattle.setExpiresAt(character.auto_battle_expires_at);
}, { immediate: true });

watch(tickCount, () => {
  autoGather.tick();
  autoBattle.tick();
});

// Mobile drawer: sidebar becomes an off-canvas panel below the phone breakpoint (see
// GameLayout.scss), toggled by the hamburger button in the topbar. Closing on every route
// change means tapping a nav link (or the backdrop) always returns to the page, never leaves
// the drawer stuck open over the new screen.
const mobileNavOpen = ref(false);
function toggleMobileNav() {
  mobileNavOpen.value = !mobileNavOpen.value;
}
watch(() => route.path, () => {
  mobileNavOpen.value = false;
});

onMounted(() => {
  if (!characterStore.character) characterStore.fetch();
  loadUnread();
  loadNavBadges();
  loadActivePlayers();
  loadGameVersion();
  pvpQueue.init();
  craftingQueue.init();
  gatherCooldowns.init();
  // Check every 30s instead of fetching full badges - only fetches if something changed
  badgePollTimer = setInterval(checkForBadgeUpdates, 30000);
});

onUnmounted(() => {
  clearInterval(badgePollTimer);
});
</script>

<template>
  <div class="game-layout">
    <Toast :message="levelUpMessage" type="success" />
    <Toast :message="pvpToast" type="success" />
    <div
      v-if="mobileNavOpen"
      class="sidebar-backdrop"
      @click="mobileNavOpen = false"
    ></div>
    <aside class="sidebar" :class="{ 'sidebar--open': mobileNavOpen }">
      <div class="sidebar__brand">
        <img src="/images/solyx-icon.png" alt="" class="sidebar__logo" />
        <div>
          <div class="ox sidebar__brand-name">SOLYX</div>
          <div class="sidebar__brand-tag">WEB GAME</div>
          <router-link to="/changelog" class="sidebar__brand-tag sidebar__brand-tag--version">
            {{ gameVersion ? `v${gameVersion}` : 'Beta version!' }}
          </router-link>
        </div>
      </div>
      <div v-if="activePlayersHour !== null" class="sidebar__online">
        <span class="sidebar__online-dot"></span>
        {{ activePlayersHour }} active this hour
      </div>
      <BugReportWidget ref="bugReportWidget" />
      <nav class="sidebar__nav">
        <template v-for="n in visibleNav" :key="n.path">
          <router-link v-if="!isLocked(n)" :to="n.path" custom v-slot="{ navigate, isActive }">
            <button
              @click="navigate"
              @mouseenter="preloadRoute(n.path)"
              @focus="preloadRoute(n.path)"
              @touchstart="preloadRoute(n.path)"
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
        <template v-for="n in visibleNavFooter" :key="n.path">
          <a v-if="n.external" :href="n.path" target="_blank" rel="noopener noreferrer" class="sidebar__footer-link">
            <span class="sidebar__footer-icon">{{ n.icon }}</span>
            {{ n.label }}
          </a>
          <router-link v-else :to="n.path" class="sidebar__footer-link">
            <span class="sidebar__footer-icon">{{ n.icon }}</span>
            {{ n.label }}
          </router-link>
        </template>
      </div>
    </aside>

    <div class="layout-main-col">
      <header class="topbar">
        <button class="topbar__hamburger" type="button" @click="toggleMobileNav" aria-label="Menu">
          <span></span><span></span><span></span>
        </button>
        <span class="ox topbar__title"
          >Solyx Web Game <span class="topbar__title-sep">—</span>
          <span class="topbar__title-active">{{ activeLabel }}</span></span
        >
        <button type="button" class="topbar__bug-report-btn" @click="bugReportWidget?.open()">
          🐞 Report a Bug — Beta!
        </button>
        <div class="topbar__actions">
          <router-link
            v-if="pvpQueue.searching"
            to="/pvp"
            class="topbar__pill topbar__pill--pvp-search"
            title="Searching for a PvP opponent — click to view"
          >
            <span class="topbar__pill-icon">⚔</span> {{ pvpSearchDuration }}
            <button
              type="button"
              class="topbar__pill-cancel"
              @click.stop.prevent="pvpQueue.cancelSearch()"
              aria-label="Cancel PvP search"
            >✕</button>
          </router-link>
          <router-link
            v-if="autoBattle.active"
            to="/battle"
            class="topbar__pill topbar__pill--auto-battle"
            title="Auto-Battle training running — click to view"
          >
            <span class="topbar__pill-icon">⚔</span> {{ autoBattleDuration }}
          </router-link>
          <router-link
            v-if="autoGather.active"
            to="/trade-skills"
            class="topbar__pill topbar__pill--auto-gather"
            :title="`Auto-${autoGather.skill} running — click to view`"
          >
            <span class="topbar__pill-icon">⛏</span> {{ autoGatherDuration }}
          </router-link>
          <router-link
            v-if="gatherCooldowns.secondsRemaining > 0"
            to="/trade-skills"
            class="topbar__pill topbar__pill--gather-cooldown"
            :title="`${gatherCooldowns.longest?.label} cooling down — click to view`"
          >
            <span class="topbar__pill-icon">{{ gatherCooldowns.longest?.glyph || '⏱' }}</span> {{ gatherCooldownDuration }}
          </router-link>
          <router-link
            v-if="craftingQueue.activeJob"
            to="/crafting"
            class="topbar__pill topbar__pill--crafting"
            title="Crafting in progress — click to view"
          >
            <span class="topbar__pill-icon">🔨</span> {{ craftingDuration }}
          </router-link>
          <router-link
            v-if="craftingQueue.readyCount > 0"
            to="/crafting"
            class="topbar__pill topbar__pill--crafting-ready"
            title="A craft is ready to collect"
          >
            🔨 {{ craftingQueue.readyCount }}
          </router-link>
          <router-link to="/inbox" class="topbar__inbox">
            🔔
            <span v-if="unreadCount > 0" class="topbar__inbox-badge">{{ unreadCount }}</span>
          </router-link>
          <div v-if="characterStore.character" class="topbar__pill topbar__pill--gold">
            <span class="topbar__pill-icon">◉</span> {{ characterStore.character.gold }}
          </div>
          <router-link v-if="characterStore.character" to="/gem-store" class="topbar__pill topbar__pill--gems">
            <span class="topbar__pill-icon">◆</span> {{ characterStore.character.account_gems }}
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
