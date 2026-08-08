// Shared changelog metadata — used by the player-facing ChangelogPage and the GM console's
// changelog card editor so tag/visibility icons, labels, and the CSS modifier they map to
// (changelog-row__tag--feature, gm-changelog-card__visibility--gm, ...) never drift apart.
export const TAG_META = {
  feature: { label: 'New', icon: '✨' },
  fix: { label: 'Fix', icon: '🛠' },
  balance: { label: 'Balance', icon: '⚖' },
  misc: { label: 'Misc', icon: '🔧' },
};

export const VISIBILITY_META = {
  player: { label: 'Player', icon: '🌐', hint: 'Visible to everyone' },
  tester: { label: 'Tester', icon: '🧪', hint: 'Visible to testers and GMs' },
  gm: { label: 'GM Only', icon: '🔒', hint: 'Visible to GMs only' },
};

export function tagMeta(tag) {
  return TAG_META[tag] ?? { label: tag, icon: '•' };
}

export function visibilityMeta(visibility) {
  return VISIBILITY_META[visibility] ?? VISIBILITY_META.player;
}
