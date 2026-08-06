import { createRouter, createWebHistory } from 'vue-router';
import GameLayout from './layouts/GameLayout.vue';
import { NAV } from './navigation';
import { useAuthStore } from './stores/auth';

const pageImport = (name) => () => import(`./pages/${name}.vue`);

// Map nav path -> page component file name.
const PAGE_COMPONENT = {
  '/dashboard': 'DashboardPage',
  '/battle': 'BattlePage',
  '/quests': 'QuestsPage',
  '/shop': 'ShopPage',
  '/inventory': 'InventoryPage',
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
  '/settings': 'SettingsPage',
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
    path: 'development',
    name: 'development',
    component: pageImport('DevelopmentPage'),
  },
  {
    path: 'hall-of-founders',
    name: 'hall-of-founders',
    component: pageImport('HallOfFoundersPage'),
  },
  {
    path: 'characters/:id/profile',
    name: 'public-profile',
    component: pageImport('PublicProfilePage'),
  }
);

const routes = [
  { path: '/', redirect: '/dashboard' },
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
  { path: '/tester-pending', name: 'tester-pending', component: pageImport('TesterPendingPage') },
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

  const isLanding = to.path === '/landing';
  const isCreate = to.path === '/character/create';
  const isSelect = to.path === '/characters';
  const isLevelRequired = to.path === '/level-required';
  const isTesterPending = to.path === '/tester-pending';
  const isPublic =
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

  // Pending testers can only see the waiting screen (and log out from it).
  if (auth.isAuthenticated && auth.pendingApproval && !isTesterPending) {
    return '/tester-pending';
  }
  // Once approved, boot them off the waiting screen.
  if (auth.isAuthenticated && !auth.pendingApproval && isTesterPending) {
    const hasAnyCharacters = (auth.user?.characters?.length ?? 0) > 0;
    return hasAnyCharacters ? '/characters' : '/character/create';
  }

  if (auth.isAuthenticated && !auth.hasCharacter && !isCreate && !isSelect && !isLanding && !isTesterPending) {
    const hasAnyCharacters = (auth.user?.characters?.length ?? 0) > 0;
    return hasAnyCharacters ? '/characters' : '/character/create';
  }
  if (auth.isAuthenticated && auth.hasCharacter && isLanding) {
    return '/dashboard';
  }
  if (to.meta.requiresGm && !['gm', 'owner'].includes(auth.user?.role)) {
    return '/dashboard';
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
