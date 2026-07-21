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
}));

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
    path: 'characters/:id/profile',
    name: 'public-profile',
    component: pageImport('PublicProfilePage'),
  }
);

const routes = [
  { path: '/', redirect: '/dashboard' },
  { path: '/landing', name: 'landing', component: pageImport('LandingPage') },
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
  const isPublic =
    isLanding ||
    to.path === '/wiki' ||
    to.path === '/terms' ||
    to.path === '/privacy' ||
    to.path === '/forgot-password' ||
    to.path === '/reset-password';

  if (!auth.isAuthenticated && !isPublic) {
    return '/landing';
  }
  if (auth.isAuthenticated && !auth.hasCharacter && !isCreate && !isSelect && !isLanding) {
    const hasAnyCharacters = (auth.user?.characters?.length ?? 0) > 0;
    return hasAnyCharacters ? '/characters' : '/character/create';
  }
  if (auth.isAuthenticated && auth.hasCharacter && isLanding) {
    return '/dashboard';
  }
  if (to.meta.requiresGm && !['gm', 'owner'].includes(auth.user?.role)) {
    return '/dashboard';
  }
});

export default router;
