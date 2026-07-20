export const RARITY_COLORS = {
  common: '#cbd5e1',
  rare: '#5cc7f5',
  epic: '#a78bfa',
  legendary: '#eab308',
  mythic: '#f472b6',
};

export const RARITY_LABELS = {
  common: 'Common',
  rare: 'Rare',
  epic: 'Epic',
  legendary: 'Legendary',
  mythic: 'Mythic',
};

const STAT_LABELS = {
  atk: 'ATK',
  def: 'DEF',
  crit: 'Crit',
  crit_damage: 'Crit Dmg',
  luck: 'Luck',
  dodge_pct: 'Dodge',
  lifesteal_pct: 'Lifesteal',
  mp: 'Mana',
  gather_speed_pct: 'Gather Speed',
  craft_speed_pct: 'Craft Speed',
  gather_yield_bonus: 'Gather Yield',
  heal_hp_pct: 'Heals HP',
  heal_mp_pct: 'Heals MP',
  hp_regen_pct_buff: 'HP Regen',
  mana_regen_pct_buff: 'Mana Regen',
  atk_pct_buff: 'ATK',
};

/** Turns an item's stat_json into a compact, human-readable list like ["+40 ATK", "+4% Dodge"]. */
export function formatStats(statJson) {
  if (!statJson) return [];
  return Object.entries(statJson)
    .filter(([key]) => STAT_LABELS[key])
    .map(([key, value]) => `+${value}${key.includes('pct') ? '%' : ''} ${STAT_LABELS[key]}`);
}
