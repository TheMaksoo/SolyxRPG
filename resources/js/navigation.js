// Shared nav config for GameLayout's sidebar + the router. One entry per
// screen in SOLYX_BUILD_GUIDE.md §5. Wiki lives outside this list — it has
// its own standalone layout (see WikiPage.vue's "Back to game" link).
export const NAV = [
  { path: '/dashboard', name: 'dashboard', label: 'Dashboard', icon: '🏠' },
  { path: '/battle', name: 'battle', label: 'Battle', icon: '⚔' },
  { path: '/quests', name: 'quests', label: 'Quests', icon: '📜' },
  { path: '/shop', name: 'shop', label: 'Shop', icon: '🛒' },
  { path: '/inventory', name: 'inventory', label: 'Inventory', icon: '🎒' },
  { path: '/skills', name: 'skills', label: 'Skills', icon: '✦' },
  { path: '/world-map', name: 'world-map', label: 'World Map', icon: '🗺' },
  { path: '/dungeons', name: 'dungeons', label: 'Dungeons', icon: '🏰' },
  { path: '/pets', name: 'pets', label: 'Companions', icon: '🐾' },
  { path: '/trade-skills', name: 'trade-skills', label: 'Trade Skills', icon: '⛏' },
  { path: '/crafting', name: 'crafting', label: 'Crafting', icon: '🔨' },
  { path: '/guild', name: 'guild', label: 'Guild', icon: '🛡' },
  { path: '/friends', name: 'friends', label: 'Friends', icon: '🧑‍🤝‍🧑' },
  { path: '/party', name: 'party', label: 'Party', icon: '🧑‍🧑‍🧒' },
  { path: '/pvp', name: 'pvp', label: 'PvP Arena', icon: '⚔' },
  { path: '/leaderboard', name: 'leaderboard', label: 'Leaderboard', icon: '🏆' },
  { path: '/daily', name: 'daily', label: 'Daily', icon: '🎁' },
  { path: '/gem-store', name: 'gem-store', label: 'Gem Store', icon: '💎' },
  { path: '/battle-pass', name: 'battle-pass', label: 'Battle Pass', icon: '🎫' },
  { path: '/vip', name: 'vip', label: 'VIP', icon: '👑' },
  { path: '/profile', name: 'profile', label: 'Profile', icon: '👤' },
  { path: '/settings', name: 'settings', label: 'Settings', icon: '⚙' },
];

export const NAV_FOOTER = [
  { path: '/wiki', name: 'wiki-link', label: 'Wiki', icon: '📖' },
  { path: '/admin', name: 'admin', label: 'GM Console', icon: '🛠' },
];
