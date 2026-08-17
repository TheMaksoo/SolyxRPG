// Shared per-danger-tier visual language (color, tint, background texture) — originally lived only in
// WorldMapPage.vue; pulled out here so BattlePage's battlefield backdrop uses the exact same palette
// instead of inventing a second one, and any zone shown anywhere in the game reads consistently.
export const DANGER = {
  safe: { color: '#4ade80', bg: 'rgba(74,222,128,.13)', art: 'repeating-linear-gradient(45deg,#152318,#152318 10px,#101c14 10px,#101c14 20px)' },
  medium: { color: '#eab308', bg: 'rgba(234,179,8,.13)', art: 'repeating-linear-gradient(45deg,#231b10,#231b10 10px,#1c150c 10px,#1c150c 20px)' },
  high: { color: '#ff8163', bg: 'rgba(255,129,99,.13)', art: 'repeating-linear-gradient(135deg,#20161b,#20161b 10px,#1a1216 10px,#1a1216 20px)' },
  deadly: { color: '#a78bfa', bg: 'rgba(167,139,250,.13)', art: 'repeating-linear-gradient(135deg,#1a1425,#1a1425 10px,#14101d 10px,#14101d 20px)' },
};

export function dangerMeta(danger) {
  return DANGER[danger] ?? DANGER.safe;
}
