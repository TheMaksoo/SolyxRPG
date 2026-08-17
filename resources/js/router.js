import { createRouter, createWebHistory } from 'vue-router';
import GameLayout from './layouts/GameLayout.vue';
import { NAV } from './navigation';
import { useAuthStore } from './stores/auth';
import { homePath } from './utils/homePath';

const pageImport = (name) => () => import(`./pages/${name}.vue`);

// Map nav path -> page component file name.
const PAGE_COMPONENT = {
  '/dashboard': 'DashboardPage',
  '/battle': 'BattlePage',
  '/quests': 'QuestsPage',
  '/shop': 'ShopPage',
  '/inventory': 'InventoryPage',
  '/forge': 'ForgePage',
  '/crates': 'CratesPage',
  '/skills': 'SkillsPage',
  '/world-map': 'WorldMapPage',
  '/dungeons': 'DungeonsPage',
  '/pets': 'PetsPage',
  '/trade-skills': 'TradeSkillsPage',
  '/crafting': 'CraftingPage',
  '/market': 'MarketplacePage',
  '/guild': 'GuildPage',
  '/friends': 'FriendsPage',
  '/party': 'PartyPage',
  '/pvp': 'PvpPage',
  '/leaderboard': 'LeaderboardPage',
  '/daily': 'DailyPage',
  '/gem-store': 'GemStorePage',
  '/battle-pass': 'BattlePassPage',
  '/vip': 'VipPage',
  '/profile': 'ProfilePage',
};

const gameChildren = NAV.map((n) => ({
  path: n.path.slice(1),
  name: n.name,
  component: pageImport(PAGE_COMPONENT[n.path]),
  meta: n.unlockLevel ? { requiredLevel: n.unlockLevel, featureName: n.label } : {},
}));

// Lets the sidebar warm a page's JS chunk on hover/focus, before the click actually happens — the
// dynamic import() is cached by the browser, so by the time the nav click fires the route change no
// longer has to wait on a network fetch + parse, which was the biggest contributor to slow sidebar
// nav clicks (INP). Safe to call repeatedly; import() of an already-fetched module is a cheap no-op.
export function preloadRoute(path) {
  const name = PAGE_COMPONENT[path];
  if (name) pageImport(name)();
}

// GM console lives under GameLayout too, for now (its own layout can split
// off once the real admin panel is built).
gameChildren.push(
  {
    path: 'admin',
    name: 'admin',
    component: () => import('./pages/admin/GmConsole.vue'),
    meta: { requiresGm: true },
  },
  {
    path: 'art-studio',
    name: 'art-studio',
    component: () => import('./pages/ArtStudioPage.vue'),
    meta: { requiresArtist: true },
  },
  {
    path: 'inbox',
    name: 'inbox',
    component: pageImport('InboxPage'),
  },
  {
    path: 'known-bugs',
    name: 'known-bugs',
    component: pageImport('KnownBugsPage'),
  },
  {
    path: 'changelog',
    name: 'changelog',
    component: pageImport('ChangelogPage'),
  },
  {
    path: 'referrals',
    name: 'referrals',
    component: pageImport('ReferralsPage'),
  },
  {
    path: 'hall-of-founders',
    name: 'hall-of-founders',
    component: pageImport('HallOfFoundersPage'),
  },
  {
    path: 'settings',
    name: 'settings',
    component: pageImport('SettingsPage'),
  },
  {
    path: 'characters/:id/profile',
    name: 'public-profile',
    component: pageImport('PublicProfilePage'),
  }
);

const routes = [
  { path: '/', name: 'home', component: pageImport('HomePage') },
  { path: '/landing', name: 'landing', component: pageImport('LandingPage') },
  { path: '/oauth/callback', name: 'oauth-callback', component: pageImport('OAuthCallbackPage') },
  { path: '/forgot-password', name: 'forgot-password', component: pageImport('ForgotPasswordPage') },
  { path: '/reset-password', name: 'reset-password', component: pageImport('ResetPasswordPage') },
  {
    path: '/character/create',
    name: 'character-create',
    component: pageImport('CharacterCreatePage'),
  },
  {
    path: '/characters',
    name: 'character-select',
    component: pageImport('CharacterSelectPage'),
  },
  { path: '/wiki', name: 'wiki', component: pageImport('WikiPage') },
  { path: '/terms', name: 'terms', component: pageImport('TermsPage') },
  { path: '/privacy', name: 'privacy', component: pageImport('PrivacyPage') },
  { path: '/level-required', name: 'level-required', component: pageImport('LevelRequiredPage') },
  {
    path: '/',
    component: GameLayout,
    children: gameChildren,
  },
];

const router = createRouter({
  history: createWebHistory(),
  routes,
});

router.beforeEach(async (to) => {
  const auth = useAuthStore();
  if (!auth.checked) {
    await auth.fetchMe();
  }

  const isHome = to.path === '/';
  const isLanding = to.path === '/landing';
  const isCreate = to.path === '/character/create';
  const isSelect = to.path === '/characters';
  const isLevelRequired = to.path === '/level-required';
  const isPublic =
    isHome ||
    isLanding ||
    to.path === '/oauth/callback' ||
    to.path === '/wiki' ||
    to.path === '/terms' ||
    to.path === '/privacy' ||
    to.path === '/forgot-password' ||
    to.path === '/reset-password';

  if (!auth.isAuthenticated && !isPublic) {
    return '/landing';
  }
  if (auth.isAuthenticated && !auth.hasCharacter && !isCreate && !isSelect && !isLanding && !isHome) {
    const hasAnyCharacters = (auth.user?.characters?.length ?? 0) > 0;
    return hasAnyCharacters ? '/characters' : '/character/create';
  }
  if (auth.isAuthenticated && auth.hasCharacter && (isLanding || isHome)) {
    return homePath(auth);
  }
  if (to.meta.requiresGm && !['gm', 'owner'].includes(auth.user?.role)) {
    return homePath(auth);
  }
  if (to.meta.requiresArtist && !['artist', 'gm', 'owner'].includes(auth.user?.role)) {
    return homePath(auth);
  }
  
  // Check level requirements for pages
  if (to.meta.requiredLevel && auth.hasCharacter && !isLevelRequired) {
    const currentLevel = auth.user?.character?.level || 0;
    if (currentLevel < to.meta.requiredLevel) {
      return {
        path: '/level-required',
        query: {
          level: to.meta.requiredLevel,
          feature: to.meta.featureName || 'this page',
        },
      };
    }
  }
});

export default router;
