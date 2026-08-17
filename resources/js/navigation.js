// Shared nav config for GameLayout's sidebar + the router. One entry per
// screen in SOLYX_BUILD_GUIDE.md §5. Wiki lives outside this list — it has
// its own standalone layout (see WikiPage.vue's "Back to game" link).
//
// Ordered by unlockLevel ascending (ungated items — available from level 1 — come first, in their
// original relative order) so the sidebar reads top-to-bottom as the actual order things unlock in.
// GameLayout's visibleNav computed hides every locked item except the single soonest one regardless of
// array position, but THIS order is what determines where that "next unlock" slot sits, and what order
// things land in as a character actually levels up.
export const NAV = [
  // Always available from level 1 — Battle is the only real "feature" here; Profile and Dashboard (the
  // Inn — your home base, not a discrete system to reveal) are meta/hub pages, same reasoning as Settings
  // in the footer, so both stay ungated too.
  { path: '/battle', name: 'battle', label: 'Battle', icon: '⚔', flagKey: 'battle' },
  { path: '/profile', name: 'profile', label: 'Profile', icon: '👤' },
  { path: '/dashboard', name: 'dashboard', label: 'The Inn', icon: '🏠' },

  // Exactly one new feature per level from here — no two entries share an unlockLevel. Skills has to
  // land on level 2 specifically (not just "early"): that's the first level-up, and the tutorial tells
  // the player they now have skill points to spend right then, so the page must already be reachable.
  { path: '/skills', name: 'skills', label: 'Skills', icon: '✦', unlockLevel: 2, unlockHint: 'Reach level 2 to spend your first skill point.', flagKey: 'skills' },
  { path: '/inventory', name: 'inventory', label: 'Inventory', icon: '🎒', unlockLevel: 3, unlockHint: 'Reach level 3 to check your gear and loot.', flagKey: 'inventory' },
  { path: '/shop', name: 'shop', label: 'Shop', icon: '🛒', unlockLevel: 4, unlockHint: 'Reach level 4 to unlock the Shop.', flagKey: 'shop' },
  // Crates just open loot you're already earning in battle (no new system to learn), so they land right
  // after the Inventory/Shop basics instead of waiting behind Crafting/Marketplace like the bigger systems.
  { path: '/crates', name: 'crates', label: 'Crates', icon: '📦', unlockLevel: 5, unlockHint: 'Reach level 5 to start unlocking loot crates.', flagKey: 'crates' },
  { path: '/quests', name: 'quests', label: 'Quests', icon: '📜', unlockLevel: 6, unlockHint: 'Reach level 6 to pick up quests.', flagKey: 'quests' },
  // Gathering has to precede Crafting — there's nothing to craft with until you've gathered materials.
  { path: '/trade-skills', name: 'trade-skills', label: 'Gathering', icon: '⛏', unlockLevel: 7, unlockHint: 'Reach level 7 to start gathering materials.', flagKey: 'trade_skills' },
  { path: '/crafting', name: 'crafting', label: 'Crafting', icon: '🔨', unlockLevel: 8, unlockHint: 'Reach level 8 to unlock crafting.', flagKey: 'crafting' },
  { path: '/market', name: 'market', label: 'Marketplace', icon: '🏪', unlockLevel: 9, unlockHint: 'Reach level 9 to buy and sell on the Marketplace.', flagKey: 'marketplace' },
  { path: '/party', name: 'party', label: 'Party', icon: '🧑‍🧑‍🧒', unlockLevel: 10, unlockHint: 'Reach level 10 to form a party.', flagKey: 'party' },
  // Referring a friend only makes sense once there's a party/market/crates worth telling them about.
  { path: '/friends', name: 'friends', label: 'Friends', icon: '🧑‍🤝‍🧑', unlockLevel: 11, unlockHint: 'Reach level 11 to add friends.', flagKey: 'friends' },
  { path: '/leaderboard', name: 'leaderboard', label: 'Leaderboard', icon: '🏆', unlockLevel: 12, unlockHint: 'Reach level 12 to see how you rank.', flagKey: 'leaderboard' },
  { path: '/referrals', name: 'referrals', label: 'Invite Friends', icon: '🎁', unlockLevel: 13, unlockHint: 'Reach level 13 to invite friends and earn rewards.' },
  { path: '/world-map', name: 'world-map', label: 'World Map', icon: '🗺', unlockLevel: 14, unlockHint: 'Reach level 14 to travel between regions.', flagKey: 'world_map' },
  { path: '/daily', name: 'daily', label: 'Daily', icon: '🎁', unlockLevel: 15, unlockHint: 'Reach level 15 to start claiming daily rewards.', flagKey: 'daily' },
  { path: '/gem-store', name: 'gem-store', label: 'Gem Store', icon: '💎', unlockLevel: 16, unlockHint: 'Reach level 16 to unlock the Gem Store.', flagKey: 'gem_store' },
  { path: '/battle-pass', name: 'battle-pass', label: 'Battle Pass', icon: '🎫', unlockLevel: 17, unlockHint: 'Reach level 17 to unlock the Battle Pass.', flagKey: 'battle_pass' },
  { path: '/vip', name: 'vip', label: 'Premium', icon: '👑', unlockLevel: 18, unlockHint: 'Reach level 18 to unlock Premium.', flagKey: 'vip' },
  // Companions, Guild, Dungeons and PvP are all deliberately the last things to open — each expects a
  // build/roster that's had time to come together, not a character who just finished the tutorial.
  { path: '/pets', name: 'pets', label: 'Companions', icon: '🐾', unlockLevel: 19, unlockHint: 'Reach level 19 to adopt your first companion.', flagKey: 'pets' },
  { path: '/guild', name: 'guild', label: 'Guild', icon: '🛡', unlockLevel: 20, unlockHint: 'Reach level 20 to join or found a guild.', flagKey: 'guilds' },
  // Deliberately gated well past Spider's Den's own min_level of 14 (see DungeonSeeder) — dungeons are
  // meant to feel like late-game content, not something to walk into moments after the tutorial, so by
  // the time this tab opens at 21 there's already a real backlog of dungeons waiting, not just one.
  { path: '/dungeons', name: 'dungeons', label: 'Dungeons', icon: '🏰', unlockLevel: 21, unlockHint: 'Reach level 21 to start scouting dungeons.', flagKey: 'dungeons' },
  { path: '/pvp', name: 'pvp', label: 'PvP Arena', icon: '⚔', unlockLevel: 22, unlockHint: 'Reach level 22 to enter the arena.', flagKey: 'pvp' },

  // Deliberately way out on its own — Forge is a late-game gear-reforging system, not part of the
  // level 2-22 "one new thing at a time" onboarding run above.
  { path: '/forge', name: 'forge', label: 'Forge', icon: '🔥', unlockLevel: 200, unlockHint: 'Reach level 200 to reforge gear.', flagKey: 'forge' },
];

export const NAV_FOOTER = [
  // Settings is deliberately never level-gated (it's account/UX config, not gameplay) — pinned as the
  // first footer entry, directly above Join Discord, so it's always reachable regardless of level.
  { path: '/settings', name: 'settings', label: 'Settings', icon: '⚙' },
  { path: 'https://discord.gg/WhCmHm3KaS', name: 'discord-link', label: 'Join Discord', icon: '💬', external: true },
  { path: '/wiki', name: 'wiki-link', label: 'Wiki', icon: '📖' },
  { path: '/known-bugs', name: 'known-bugs-link', label: 'Known Bugs', icon: '🐞' },
  { path: '/changelog', name: 'changelog-link', label: 'Changelog', icon: '📜' },
  { path: '/hall-of-founders', name: 'hall-of-founders-link', label: 'Hall of Founders', icon: '⚜️' },
  // Role-gated (not level-gated, see GameLayout's visibleNavFooter) — role checks live there since this
  // file has no concept of the account's role, only the character's level.
  { path: '/art-studio', name: 'art-studio', label: 'Art Studio', icon: '🎨' },
  { path: '/admin', name: 'admin', label: 'GM Console', icon: '🛠' },
];
