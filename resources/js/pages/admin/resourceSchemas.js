// Field schemas for the generic GM content editor — one entry per resource
// type the backend's GmContentController supports. `json` fields are edited
// as raw JSON text and parsed/stringified on load/save.
//
// `previewFields` picks which columns show as stat chips on a resource's card in the content grid
// (falls back to the first few `columns` minus id/name/key if omitted). `badge` fields render as a
// colored pill instead of plain text — see BADGE_TONES below.
export const RESOURCE_SCHEMAS = {
  items: {
    label: 'Items',
    columns: ['id', 'key', 'name', 'type', 'rarity', 'enabled'],
    previewFields: ['type', 'rarity', 'price_gold', 'price_gems'],
    badgeFields: ['type', 'rarity'],
    fields: [
      { name: 'key', type: 'text' },
      { name: 'name', type: 'text' },
      { name: 'type', type: 'select', options: ['weapon', 'armor', 'consumable', 'cosmetic', 'material'] },
      { name: 'rarity', type: 'select', options: ['common', 'rare', 'epic', 'legendary', 'mythic'] },
      { name: 'glyph', type: 'text' },
      { name: 'sprite', type: 'text' },
      { name: 'description', type: 'textarea' },
      // Individual stat columns (see app/Models/Item.php's STAT_KEYS) instead of one raw JSON blob —
      // lets a GM tune a single stat directly without hand-editing JSON syntax. Leave irrelevant ones
      // blank; only non-null stats actually show up on the item.
      { name: 'atk', type: 'number' },
      { name: 'def', type: 'number' },
      { name: 'crit', type: 'number' },
      { name: 'dodge_pct', type: 'number' },
      { name: 'mp', type: 'number' },
      { name: 'luck', type: 'number' },
      { name: 'lifesteal_pct', type: 'number' },
      { name: 'heal_hp_flat', type: 'number' },
      { name: 'heal_hp_pct', type: 'number' },
      { name: 'heal_mp_flat', type: 'number' },
      { name: 'heal_mp_pct', type: 'number' },
      { name: 'hp_regen_pct_buff', type: 'number' },
      { name: 'mana_regen_pct_buff', type: 'number' },
      { name: 'duration_seconds', type: 'number' },
      { name: 'atk_pct_buff', type: 'number' },
      { name: 'buff_fights', type: 'number' },
      { name: 'revive_chance_pct', type: 'number' },
      { name: 'pet_xp', type: 'number' },
      { name: 'gather_speed_pct', type: 'number' },
      { name: 'gather_yield_bonus', type: 'number' },
      { name: 'craft_speed_pct', type: 'number' },
      { name: 'price_gold', type: 'number' },
      { name: 'price_gems', type: 'number' },
      { name: 'enabled', type: 'checkbox' },
      { name: 'tester_only', type: 'checkbox' },
    ],
  },
  monsters: {
    label: 'Monsters',
    columns: ['id', 'key', 'name', 'hp', 'atk', 'min_level', 'enabled'],
    previewFields: ['hp', 'atk', 'min_level', 'is_boss'],
    badgeFields: ['is_boss'],
    fields: [
      { name: 'key', type: 'text' },
      { name: 'name', type: 'text' },
      { name: 'glyph', type: 'text' },
      { name: 'sprite', type: 'text' },
      { name: 'hp', type: 'number' },
      { name: 'atk', type: 'number' },
      { name: 'gold', type: 'number' },
      { name: 'xp', type: 'number' },
      { name: 'gems', type: 'number' },
      { name: 'is_boss', type: 'checkbox' },
      { name: 'zone_id', type: 'number' },
      { name: 'min_level', type: 'number' },
      { name: 'loot_table_json', type: 'json' },
      { name: 'enabled', type: 'checkbox' },
      { name: 'tester_only', type: 'checkbox' },
    ],
  },
  zones: {
    label: 'Zones',
    columns: ['id', 'key', 'name', 'danger', 'min_level', 'locked', 'enabled'],
    previewFields: ['danger', 'min_level', 'locked'],
    badgeFields: ['danger'],
    fields: [
      { name: 'key', type: 'text' },
      { name: 'name', type: 'text' },
      { name: 'glyph', type: 'text' },
      { name: 'sprite', type: 'text' },
      { name: 'danger', type: 'select', options: ['safe', 'medium', 'high', 'deadly'] },
      { name: 'min_level', type: 'number' },
      { name: 'locked', type: 'checkbox' },
      { name: 'sort_order', type: 'number' },
      { name: 'enabled', type: 'checkbox' },
      { name: 'tester_only', type: 'checkbox' },
    ],
  },
  dungeons: {
    label: 'Dungeons',
    columns: ['id', 'key', 'name', 'difficulty', 'min_level', 'enabled'],
    previewFields: ['difficulty', 'min_level', 'party_size'],
    badgeFields: ['difficulty'],
    fields: [
      { name: 'key', type: 'text' },
      { name: 'name', type: 'text' },
      { name: 'glyph', type: 'text' },
      { name: 'sprite', type: 'text' },
      { name: 'difficulty', type: 'select', options: ['normal', 'hard', 'raid', 'mythic'] },
      { name: 'boss_monster_id', type: 'number' },
      { name: 'min_level', type: 'number' },
      { name: 'party_size', type: 'number' },
      { name: 'drops_json', type: 'json' },
      { name: 'enabled', type: 'checkbox' },
      { name: 'tester_only', type: 'checkbox' },
    ],
  },
  quests: {
    label: 'Quests',
    columns: ['id', 'key', 'name', 'type', 'enabled'],
    previewFields: ['type'],
    badgeFields: ['type'],
    fields: [
      { name: 'key', type: 'text' },
      { name: 'name', type: 'text' },
      { name: 'description', type: 'textarea' },
      { name: 'type', type: 'select', options: ['daily', 'weekly', 'monthly', 'main', 'raid'] },
      { name: 'goal_json', type: 'json' },
      { name: 'reward_json', type: 'json' },
      { name: 'enabled', type: 'checkbox' },
    ],
  },
  skills: {
    label: 'Skills',
    columns: ['id', 'key', 'name', 'branch', 'tier', 'level_req'],
    previewFields: ['branch', 'tier', 'level_req', 'mp_cost'],
    fields: [
      { name: 'branch', type: 'text' },
      { name: 'key', type: 'text' },
      { name: 'name', type: 'text' },
      { name: 'glyph', type: 'text' },
      { name: 'sprite', type: 'text' },
      { name: 'description', type: 'textarea' },
      { name: 'tier', type: 'number' },
      { name: 'level_req', type: 'number' },
      { name: 'mp_cost', type: 'number' },
      { name: 'cooldown_rounds', type: 'number' },
      { name: 'effect_json', type: 'json' },
      { name: 'class_scope', type: 'text' },
      { name: 'requires_profession', type: 'checkbox' },
    ],
  },
  recipes: {
    label: 'Recipes',
    columns: ['id', 'name', 'result_item_id', 'enabled'],
    previewFields: ['result_item_id', 'gold_cost'],
    fields: [
      { name: 'name', type: 'text' },
      { name: 'result_item_id', type: 'number' },
      { name: 'materials_json', type: 'json' },
      { name: 'gold_cost', type: 'number' },
      { name: 'enabled', type: 'checkbox' },
    ],
  },
  pets: {
    label: 'Companions',
    columns: ['id', 'key', 'name', 'unlock_gems', 'enabled'],
    previewFields: ['unlock_gems'],
    fields: [
      { name: 'key', type: 'text' },
      { name: 'name', type: 'text' },
      { name: 'glyph', type: 'text' },
      { name: 'description', type: 'textarea' },
      { name: 'bonus_json', type: 'json' },
      { name: 'unlock_gems', type: 'number' },
      { name: 'enabled', type: 'checkbox' },
    ],
  },
  cosmetics: {
    label: 'Cosmetics',
    columns: ['id', 'key', 'type', 'name', 'rarity', 'cost_gems', 'enabled'],
    previewFields: ['type', 'rarity', 'cost_gems'],
    badgeFields: ['type', 'rarity'],
    fields: [
      { name: 'key', type: 'text' },
      { name: 'type', type: 'select', options: ['title', 'color', 'banner', 'icon'] },
      { name: 'name', type: 'text' },
      { name: 'value', type: 'text' },
      { name: 'rarity', type: 'select', options: ['common', 'rare', 'epic', 'legendary', 'mythic'] },
      { name: 'category', type: 'select', options: ['leveling', 'mastery', 'raid', 'daily_weekly', 'event', 'purchased'] },
      { name: 'cost_gems', type: 'number' },
      { name: 'unlock_quest_key', type: 'text' },
      { name: 'unlock_event', type: 'text' },
      { name: 'enabled', type: 'checkbox' },
    ],
  },
  events: {
    label: 'Events',
    columns: ['id', 'name', 'type', 'active'],
    previewFields: ['type', 'active'],
    badgeFields: ['type', 'active'],
    fields: [
      { name: 'name', type: 'text' },
      { name: 'type', type: 'select', options: ['bonus_xp', 'bonus_gold', 'world_boss', 'drop_up', 'login'] },
      { name: 'reward', type: 'text' },
      { name: 'effect_json', type: 'json' },
      { name: 'starts_at', type: 'text' },
      { name: 'ends_at', type: 'text' },
      { name: 'active', type: 'checkbox' },
    ],
  },
  known_bugs: {
    label: 'Known Bugs',
    columns: ['id', 'title', 'area', 'severity', 'status'],
    previewFields: ['area', 'severity', 'status'],
    badgeFields: ['severity', 'status'],
    fields: [
      { name: 'title', type: 'text' },
      { name: 'description', type: 'textarea' },
      { name: 'area', type: 'text' },
      { name: 'severity', type: 'select', options: ['minor', 'major', 'critical'] },
      { name: 'status', type: 'select', options: ['reported', 'investigating', 'fixed'] },
    ],
  },
  changelogs: {
    label: 'Changelog',
    columns: ['id', 'version', 'title', 'tag', 'visibility', 'published_at'],
    fields: [
      { name: 'version', type: 'text' },
      { name: 'title', type: 'text' },
      { name: 'body', type: 'textarea' },
      { name: 'details', type: 'textarea' },
      { name: 'tag', type: 'select', options: ['feature', 'fix', 'balance', 'misc'] },
      { name: 'visibility', type: 'select', options: ['player', 'tester', 'gm'] },
    ],
  },
};

// Mirrors SeederSyncService::SEEDER_MAP on the backend — resources whose seeder file is kept in sync
// on create/update/delete and can be downloaded. `recipes` is intentionally excluded (its seeder stores
// item *keys* resolved at seed-time, not the literal ids the DB stores), as are `known_bugs` (no
// seeder) and `changelogs` (its own separate seeder-to-DB flow via "Run Changelog Seeder").
export const SEEDER_BACKED_RESOURCES = ['items', 'monsters', 'zones', 'dungeons', 'quests', 'skills', 'pets', 'cosmetics', 'events'];

// Color tone per known enum value, keyed by field name then value. Falls back to a neutral tone for
// any value not listed here (new enum options added later just render plain until added below).
const BADGE_TONES = {
  rarity: { common: 'neutral', rare: 'blue', epic: 'purple', legendary: 'gold', mythic: 'red' },
  danger: { safe: 'green', medium: 'blue', high: 'gold', deadly: 'red' },
  difficulty: { normal: 'neutral', hard: 'blue', raid: 'purple', mythic: 'red' },
  severity: { minor: 'green', major: 'gold', critical: 'red' },
  status: { reported: 'gold', investigating: 'blue', fixed: 'green' },
  type: {
    weapon: 'blue', armor: 'blue', consumable: 'green', cosmetic: 'purple', material: 'neutral',
    daily: 'green', weekly: 'blue', monthly: 'purple', main: 'gold', raid: 'red',
    title: 'purple', color: 'blue', banner: 'gold', icon: 'neutral',
    bonus_xp: 'green', bonus_gold: 'gold', world_boss: 'red', drop_up: 'blue', login: 'neutral',
  },
  is_boss: { true: 'red', false: 'neutral' },
  active: { true: 'green', false: 'neutral' },
};

export function badgeTone(field, value) {
  return BADGE_TONES[field]?.[String(value)] ?? 'neutral';
}

// Which column holds a resource's level requirement, if it has one at all — backs GmContentEditor's
// "Group by Level" option. Quests/recipes/pets/events/cosmetics/known_bugs/changelogs have no level
// concept, so they're absent here and only ever offer the flat (ungrouped) view.
export const LEVEL_FIELD = {
  monsters: 'min_level',
  items: 'min_level',
  zones: 'min_level',
  dungeons: 'min_level',
  skills: 'level_req',
};

// Only monsters carry a real zone_id FK — items/skills/etc have no place of their own, and zones ARE
// places (grouping a zone by "zone" would just be grouping it by itself). Backs the "Group by Zone" option.
export const ZONE_SCOPED_RESOURCES = ['monsters'];
