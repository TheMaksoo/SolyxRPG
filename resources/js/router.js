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
  '/crafting': 'CraftingPage',
  '/guild': 'GuildPage',
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
gameChildren.push({
  path: 'admin',
  name: 'admin',
  component: () => import('./pages/admin/GmConsole.vue'),
});

const routes = [
  { path: '/', redirect: '/dashboard' },
  { path: '/landing', name: 'landing', component: pageImport('LandingPage') },
  {
    path: '/character/create',
    name: 'character-create',
    component: pageImport('CharacterCreatePage'),
  },
  { path: '/wiki', name: 'wiki', component: pageImport('WikiPage') },
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
  const isPublic = isLanding || to.path === '/wiki';

  if (!auth.isAuthenticated && !isPublic) {
    return '/landing';
  }
  if (auth.isAuthenticated && !auth.hasCharacter && !isCreate && !isLanding) {
    return '/character/create';
  }
  if (auth.isAuthenticated && auth.hasCharacter && (isCreate || isLanding)) {
    return '/dashboard';
  }
});

export default router;
