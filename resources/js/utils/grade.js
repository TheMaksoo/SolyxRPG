// Mirrors GradeService::TIERS labels/colors — the per-encounter rarity roll (common/elite/champion/
// legendary) shown as a pill wherever a monster or a tamed companion's origin grade is displayed.
export const GRADE_META = {
  common: { label: 'Common', color: '#cbd5e1' },
  elite: { label: 'Elite', color: '#5cc7f5' },
  champion: { label: 'Champion', color: '#a78bfa' },
  legendary: { label: 'Legendary', color: '#eab308' },
};

export function gradeMeta(grade) {
  return GRADE_META[grade] ?? GRADE_META.common;
}
