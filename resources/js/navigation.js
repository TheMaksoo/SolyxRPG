// Shared nav config for GameLayout's sidebar + the router. One entry per
// screen in SOLYX_BUILD_GUIDE.md §5. Wiki lives outside this list — it has
// its own standalone layout (see WikiPage.vue's "Back to game" link).
export const NAV = [
  { path: '/dashboard', name: 'dashboard', label: 'Dashboard', icon: '🏠' },
  { path: '/battle', name: 'battle', label: 'Battle', icon: '⚔', flagKey: 'battle' },
  { path: '/quests', name: 'quests', label: 'Quests', icon: '📜', flagKey: 'quests' },
  { path: '/shop', name: 'shop', label: 'Shop', icon: '🛒', unlockLevel: 3, unlockHint: 'Reach level 3 to unlock the Shop.', flagKey: 'shop' },
  { path: '/inventory', name: 'inventory', label: 'Inventory', icon: '🎒', flagKey: 'inventory' },
  { path: '/skills', name: 'skills', label: 'Skills', icon: '✦', flagKey: 'skills' },
  { path: '/world-map', name: 'world-map', label: 'World Map', icon: '🗺', unlockLevel: 5, unlockHint: 'Reach level 5 to travel between regions.', flagKey: 'world_map' },
  { path: '/dungeons', name: 'dungeons', label: 'Dungeons', icon: '🏰', unlockLevel: 15, unlockHint: 'Reach level 15 to start scouting dungeons (the first, Wolf’s Den, opens at 15).', flagKey: 'dungeons' },
  { path: '/pets', name: 'pets', label: 'Companions', icon: '🐾', unlockLevel: 10, unlockHint: 'Reach level 10 to adopt your first companion.', flagKey: 'pets' },
  { path: '/trade-skills', name: 'trade-skills', label: 'Gathering', icon: '⛏', unlockLevel: 2, unlockHint: 'Reach level 2 to start gathering materials.', flagKey: 'trade_skills' },
  { path: '/crafting', name: 'crafting', label: 'Crafting', icon: '🔨', unlockLevel: 3, unlockHint: 'Reach level 3 to unlock crafting.', flagKey: 'crafting' },
  { path: '/market', name: 'market', label: 'Marketplace', icon: '🏪', unlockLevel: 3, unlockHint: 'Reach level 3 to buy and sell on the Marketplace.', flagKey: 'marketplace' },
  { path: '/guild', name: 'guild', label: 'Guild', icon: '🛡', unlockLevel: 20, unlockHint: 'Reach level 20 to join or found a guild.', flagKey: 'guilds' },
  { path: '/friends', name: 'friends', label: 'Friends', icon: '🧑‍🤝‍🧑', flagKey: 'friends' },
  { path: '/party', name: 'party', label: 'Party', icon: '🧑‍🧑‍🧒', unlockLevel: 5, unlockHint: 'Reach level 5 to form a party.', flagKey: 'party' },
  { path: '/pvp', name: 'pvp', label: 'PvP Arena', icon: '⚔', unlockLevel: 20, unlockHint: 'Reach level 20 to enter the arena.', flagKey: 'pvp' },
  { path: '/leaderboard', name: 'leaderboard', label: 'Leaderboard', icon: '🏆', flagKey: 'leaderboard' },
  { path: '/daily', name: 'daily', label: 'Daily', icon: '🎁', flagKey: 'daily' },
  { path: '/gem-store', name: 'gem-store', label: 'Gem Store', icon: '💎', flagKey: 'gem_store' },
  { path: '/battle-pass', name: 'battle-pass', label: 'Battle Pass', icon: '🎫', flagKey: 'battle_pass' },
  { path: '/vip', name: 'vip', label: 'VIP', icon: '👑', flagKey: 'vip' },
  { path: '/profile', name: 'profile', label: 'Profile', icon: '👤' },
  { path: '/settings', name: 'settings', label: 'Settings', icon: '⚙' },
];

export const NAV_FOOTER = [
  { path: '/wiki', name: 'wiki-link', label: 'Wiki', icon: '📖' },
  { path: '/known-bugs', name: 'known-bugs-link', label: 'Known Bugs', icon: '🐞' },
  { path: '/admin', name: 'admin', label: 'GM Console', icon: '🛠' },
];
