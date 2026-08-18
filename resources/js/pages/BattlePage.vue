<script setup>
import { ref, onMounted, onUnmounted, computed, watch } from 'vue';
import api from '../api/client';
import { useCharacterStore } from '../stores/character';
import { useAuthStore } from '../stores/auth';
import { useAutoBattleStore } from '../stores/autoBattle';
import { useGameTick } from '../composables/gameTick';
import { useRegen } from '../composables/regen';
import AdBanner from '../components/AdBanner.vue';
import Skeleton from '../components/Skeleton.vue';
import TutorialOverlay from '../components/TutorialOverlay.vue';
import Toast from '../components/Toast.vue';
import { gradeMeta as gradeMetaFor } from '../utils/grade';
import { dangerMeta } from '../utils/danger';
import { NAV } from '../navigation';

const { tickCount } = useGameTick();
const AUTO_BATTLE_POLL_TICKS = 20;

const characterStore = useCharacterStore();
const auth = useAuthStore();
// The topbar's Auto-Battle pill (see stores/autoBattle.js + GameLayout.vue) polls independently, but
// buying/refreshing here shouldn't make it wait up to 20s to notice — nudge it to refresh right away.
const autoBattleStore = useAutoBattleStore();
const battle = ref(null);
const result = ref(null);
const loading = ref(true);
// True only for the animated event playback after an action's network round-trip has already
// finished — blocks starting another action mid-playback without visually dimming the panel the way
// `loading` (the actual network-wait state) does. See act()'s use of both below.
const animating = ref(false);
const error = ref('');
const notice = ref('');

// notice/error used to render as inline <p> banners right under the header — appearing/disappearing
// shifted every bit of the fight view below them. The one exception is the durability warning (BROKEN /
// Low durability), which stays as a persistent sidebar banner (see .battle-alert-bar in the template)
// with its own "Repair" CTA rather than a toast, since it's meant to stick around until the player
// actually repairs, not vanish after a few seconds.
function isDurabilityNotice(msg) {
  return !!msg && (msg.includes('BROKEN') || msg.includes('Low durability'));
}
const toastNotice = computed(() => (notice.value && !isDurabilityNotice(notice.value) ? notice.value : ''));

let noticeToastTimer = null;
watch(notice, (val) => {
  clearTimeout(noticeToastTimer);
  if (val && !isDurabilityNotice(val)) {
    noticeToastTimer = setTimeout(() => { notice.value = ''; }, 4000);
  }
});

let errorToastTimer = null;
watch(error, (val) => {
  clearTimeout(errorToastTimer);
  if (val) errorToastTimer = setTimeout(() => { error.value = ''; }, 5000);
});

// Nothing here previously stopped any of this on unmount — navigating away mid-animation (e.g.
// clicking a nav link right after an Attack with queued events) left the in-flight playEvents() loop,
// its per-event floater/flash setTimeouts, and these two toast timers all running in the background,
// continuing to mutate battle.value/floaters.value/flashing.value on a component nobody can see for up
// to several seconds. componentUnmounted short-circuits playEvents' loop between events; the pending
// timeout sets let every scheduled floater/flash/toast callback be cancelled outright.
let componentUnmounted = false;
const pendingTimeouts = new Set();
function trackedTimeout(fn, ms) {
  const id = setTimeout(() => {
    pendingTimeouts.delete(id);
    fn();
  }, ms);
  pendingTimeouts.add(id);
  return id;
}
onUnmounted(() => {
  componentUnmounted = true;
  clearTimeout(noticeToastTimer);
  clearTimeout(errorToastTimer);
  for (const id of pendingTimeouts) clearTimeout(id);
  pendingTimeouts.clear();
});

const dungeonRun = ref(null);
const battleLogExpanded = ref(false);
const rewardBreakdownExpanded = ref(false);

const autoBattle = ref({ active: false, seconds_remaining: 0, costs: {}, gems: 0 });
const autoBattleMessage = ref('');
const autoBattleSummary = ref(null);

// Counts completed rounds in the current fight — reset on a fresh encounter, +1 per action taken.
// Battle itself doesn't persist a round number (log_json is lines, not rounds), so this is tracked
// client-side against the same events that already drive everything else.
const roundCount = ref(0);

function formatDuration(totalSeconds) {
  const m = Math.floor(totalSeconds / 60);
  const s = totalSeconds % 60;
  return `${m}:${String(s).padStart(2, '0')}`;
}

async function loadAutoBattle() {
  const { data } = await api.get('/auto-battle');
  autoBattle.value = data;
  if (data.summary) {
    autoBattleSummary.value = data.summary;
    characterStore.fetch();
  }
  // Feeds the topbar's Auto-Battle pill (see stores/autoBattle.js) with data this page already fetched —
  // that store never hits /auto-battle itself, since reading it also ticks fights forward server-side.
  autoBattleStore.setExpiresAt(data.active ? data.expires_at : null);
}

async function buyAutoBattleCash(sku) {
  try {
    const { data } = await api.post('/store/checkout', { sku });
    window.location.href = data.checkout_url;
  } catch (e) {
    autoBattleMessage.value = e.response?.data?.message || 'Checkout unavailable.';
  }
}

async function buyAutoBattle(minutes) {
  try {
    const { data } = await api.post('/auto-battle/purchase', { minutes });
    autoBattle.value = { ...autoBattle.value, active: true, seconds_remaining: data.seconds_remaining, gems: data.gems };
    autoBattleMessage.value = `Training started: ${minutes} minutes added.`;
    characterStore.fetch();
    autoBattleStore.setExpiresAt(data.expires_at);
  } catch (e) {
    autoBattleMessage.value = e.response?.data?.message || 'Could not start training.';
  }
}

const monster = computed(() => battle.value?.monster ?? null);
const hpPct = (hp, max) => (max > 0 ? Math.max(0, Math.min(100, Math.round((hp / max) * 100))) : 0);

// Shared HP/MP/energy regen projection (composables/regen.js) — same singleton state Dashboard reads,
// so the two pages never disagree and switching between them never resets or re-seeds the projection.
// displayHp already prefers the live in-combat projection once setBattleHp has been called below, so
// Dashboard's HP tile shows that exact same number while you're mid-fight instead of sitting frozen on
// the stale pre-battle value. hpMax/mpMax also come from here rather than being recomputed locally, so
// there's exactly one cached "last known effective max" instead of two that could drift apart.
const { displayHp, localMana, setBattleHp, clearBattleHp, hpBarPct, mpBarPct, hpMax: playerHpMax, mpMax: playerMpMax } = useRegen();

// Battle tracks its own character_hp snapshot, separate from Character::hp, because HP regen keeps
// ticking mid-fight too (see CombatService::regenInBattle — same real-elapsed-time × regenPerTick()
// formula as out-of-combat regen, just applied to the battle's own counter instead of the character
// record). Feed it into the shared composable rather than projecting it locally, so every page reading
// displayHp (not just this one) sees the live in-combat value.
watch(() => (battle.value?.status === 'active' ? battle.value.character_hp : null), (hp) => {
  if (hp == null) {
    clearBattleHp();
  } else {
    setBattleHp(battle.value.id, hp);
  }
}, { immediate: true });

const displayCharacterHp = computed(() => Math.round(displayHp.value));
const displayCharacterMana = computed(() => Math.round(localMana.value));
const isRegeneratingHp = computed(() => displayCharacterHp.value < playerHpMax.value);
const isRegeneratingMana = computed(() => displayCharacterMana.value < playerMpMax.value);

const currentZoneName = computed(() => characterStore.character?.zone?.name ?? 'the wilds');

// Battlefield backdrop behind the enemy grid — reuses the same per-danger-tier palette/texture
// WorldMapPage already shows for this zone (see utils/danger.js), so the fight visually matches the
// zone you're actually in without needing any real location artwork/assets.
const battlefieldTheme = computed(() => dangerMeta(characterStore.character?.zone?.danger));

// Re-check durability when character inventory changes
watch(() => characterStore.character?.inventory, () => {
  if (battle.value) {
    const hasLowDurability = checkLowDurabilityGear();
    // Clear notice if no low durability and current notice is a durability warning
    if (!hasLowDurability && notice.value && (notice.value.includes('BROKEN') || notice.value.includes('Low durability'))) {
      notice.value = '';
    }
  }
}, { deep: true });

// "Adds" fighting alongside the primary monster in a multi-enemy boss encounter — empty for a normal 1v1
// fight, so none of this UI appears unless a dungeon boss actually brought friends.
const extraMonsters = computed(() => battle.value?.battle_monsters ?? []);
const hasAdds = computed(() => extraMonsters.value.length > 0);

// null = primary boss (the default target for attack / single-target skills).
const selectedTargetId = ref(null);

function selectTarget(id) {
  selectedTargetId.value = selectedTargetId.value === id ? null : id;
}

// If the selected add died (or a new battle/stage started), fall back to the primary boss rather than
// keep sending a dead/foreign target id.
watch(extraMonsters, (rows) => {
  if (selectedTargetId.value && !rows.some((m) => m.id === selectedTargetId.value && m.hp > 0)) {
    selectedTargetId.value = null;
  }
});

// Every unlocked ACTIVE skill (mp_cost > 0) gets its own action button — passives (mp_cost 0, e.g. Power
// Strike) are always-on stat boosts folded into effectiveStats(), not something you "cast" in battle.
// Further filtered to the character's Active Deck (see CharacterController::setActiveDeck) when one has
// actually been set — an empty/never-set deck (active_deck_json null or []) means "show every unlocked
// active", so nobody gets soft-locked out of skills they already had before decks existed.
const activeSkillRows = computed(() => {
  const actives = (characterStore.character?.skills || []).filter((row) => (row.skill?.mp_cost ?? 0) > 0);
  const deck = characterStore.character?.active_deck_json;
  if (!deck || deck.length === 0) return actives;
  return actives.filter((row) => deck.includes(row.skill_id));
});

// Rounds remaining per skill id, scoped to the current battle (Battle::skill_cooldowns_json) — turn-based,
// not a wall-clock timer, so it only ever changes when the server sends back an updated `battle` after an
// action (no local ticking needed, unlike the old seconds-based version).
const skillCooldowns = computed(() => battle.value?.skill_cooldowns_json ?? {});
// Every usable-in-battle consumable (heal, mana, or the elixir ATK buff) with stock — not just the
// single auto-picked "best" potion, so you can choose e.g. a mana potion over a health potion, or save
// your Phoenix Elixir for later. Using one is a free action (see CombatService::act()'s $type==='item'
// branch) — it doesn't end your turn.
const usableConsumables = computed(() => {
  const inv = characterStore.character?.inventory ?? [];
  return inv.filter((i) => {
    const stats = i.item?.stat_json ?? {};
    return i.item?.type === 'consumable' && i.qty > 0 && (
      stats.heal_hp_pct ||
      stats.heal_mp_pct ||
      stats.heal_hp_flat ||
      stats.heal_mp_flat ||
      stats.atk_pct_buff
    );
  });
});

// The battle actions grid used to render one button per usable potion, which crowded the grid fast on
// a phone once a player was carrying 4-5 different consumables. One "Consumables" button opens a picker
// grid instead — using an item from it is still the same free action (act('item', ...) doesn't end your
// turn, see CombatService::act()'s $type==='item' branch).
const consumableGridOpen = ref(false);

function useConsumable(row) {
  consumableGridOpen.value = false;
  act('item', { item_id: row.item_id });
}

function consumableLabel(row) {
  const stats = row.item?.stat_json ?? {};
  const parts = [];
  if (stats.heal_hp_flat) parts.push(`+${stats.heal_hp_flat} HP`);
  if (stats.heal_mp_flat) parts.push(`+${stats.heal_mp_flat} MP`);
  if (stats.heal_hp_pct) parts.push(`+${stats.heal_hp_pct}% HP`);
  if (stats.heal_mp_pct) parts.push(`+${stats.heal_mp_pct}% MP`);
  if (stats.atk_pct_buff) parts.push(`+${stats.atk_pct_buff}% ATK`);
  return `${row.item.glyph || '🧪'} ${row.item.name} (${parts.join(', ')})`;
}

const LOG_COLORS = [
  { match: /critical/i, color: '#eab308' },
  { match: /regenerates \d+ hp/i, color: '#4ade80' },
  { match: /defeated|fled/i, color: '#4ade80' },
  { match: /dodge|undying/i, color: '#5cc7f5' },
  { match: /hits you|were defeated|uses .+ for \d+/i, color: '#ff6a4d' },
];
function logColor(line) {
  return LOG_COLORS.find((l) => l.match.test(line))?.color ?? 'rgba(255,255,255,.7)';
}

// Condensed view (opt-in via Settings > Preferences): rather than every per-round line, show only the
// outcome/reward line (per CombatService::simulate()'s log format — "Defeated ... +Ng +Nxp", "You were
// defeated and revived...", or "You fled the battle...") plus a round-count summary — the full log
// (fullBattleLog) stays available underneath either way.
const fullBattleLog = computed(() => battle.value?.log_json || []);
const compactBattleLog = computed(() => {
  const log = battle.value?.log_json || [];
  const keyLines = log.filter((line) => /^Defeated |^You were defeated|^You fled the battle/i.test(line));
  return [...keyLines, `${log.length} round${log.length === 1 ? '' : 's'} total`];
});
const displayedBattleLog = computed(() =>
  auth.user?.preferences?.compact_battle_log ? compactBattleLog.value : fullBattleLog.value
);

const gradeMeta = computed(() => gradeMetaFor(battle.value?.grade));
const monsterHpMax = computed(() => battle.value?.monster_hp_max ?? monster.value?.hp ?? 0);

/** Monster-identity rank badge (fixed per monster) — distinct from the per-encounter grade roll above. */
const RANK_META = {
  boss: { label: '👑 Boss', color: '#f97316' },
  elite: { label: '⭐ Elite', color: '#22d3ee' },
};
const rankMeta = computed(() => {
  if (monster.value?.is_boss) return RANK_META.boss;
  if (monster.value?.is_elite) return RANK_META.elite;
  return null;
});

// Mirrors MonsterAiService::ROLES/roleSpecial() — a role pill on every enemy/companion card so its AI
// identity (fast striker, healer, hexing caster, heavy hitter) is readable at a glance, not just implied
// by its kit. 'brute' renders as a plain melee tag rather than being hidden, so every card gets one.
// Same class -> glyph mapping CharacterSelectPage uses — gives the player's own card a circular portrait
// badge that visually matches the enemy/companion cards it now faces across the battlefield.
const CLASS_GLYPH = { warrior: '⚔', mage: '✷', rogue: '🗡', ranger: '🏹' };
const playerGlyph = computed(() => CLASS_GLYPH[characterStore.character?.base_class] ?? '✷');

const ROLE_META = {
  brute: { label: 'Brute', color: '#ff8163', desc: 'Straightforward melee, no special behavior.' },
  skirmisher: { label: 'Skirmisher', color: '#5cc7f5', desc: 'Targets whoever on your side has the lowest HP%.' },
  caster: { label: 'Caster', color: '#a78bfa', desc: 'Charges, then Hexes a target with a crit-chance debuff.' },
  mender: { label: 'Mender', color: '#4ade80', desc: 'Heals its lowest-HP living ally instead of attacking when one is hurt.' },
  elite_brute: { label: 'Elite', color: '#e8482f', desc: 'Periodically lands a heavy Crushing Blow that shreds armor.' },
  tank: { label: 'Tank', color: '#eab308', desc: 'A much tankier build: far more HP and reduced incoming damage, hits softer in return. Draws attacks more often.' },
  buffer: { label: 'Buffer', color: '#22d3ee', desc: 'Periodically Rallies its side instead of attacking, empowering every living ally.' },
};
function roleMeta(role) {
  return ROLE_META[role] ?? ROLE_META.brute;
}

// Mirrors StatusEffectService::CATALOG's tones — used for the buff/debuff pill row on every combatant.
const STATUS_TONE_COLORS = {
  orange: '#ff8163', purple: '#a78bfa', red: '#e8482f', gray: 'rgba(255,255,255,.5)',
  blue: '#5cc7f5', gold: '#eab308', green: '#4ade80',
};
function statusToneColor(tone) {
  return STATUS_TONE_COLORS[tone] ?? 'rgba(255,255,255,.5)';
}
function statusPillLabel(s) {
  const amount = s.unit === 'pct' ? `${s.magnitude}%` : s.unit === 'flag' ? '' : s.magnitude;
  return `${s.glyph} ${s.label}${amount !== '' ? ' ' + amount : ''} (${s.rounds})`;
}

// Backend keeps every application as its own stack (StatusEffectService::pillsFor) so two
// different-magnitude Weaken stacks can expire independently — correct for the math, but shown as two
// near-identical pills it just reads as clutter that wraps unevenly on a narrow card. Merge same-key
// stacks into one pill here for display only: magnitudes sum (matching the real combined effect) and
// the shown round count is the LONGEST remaining stack's (the pill disappears only once every stack
// backing it is actually gone).
function dedupeStatusPills(statuses) {
  const byKey = new Map();
  for (const s of statuses ?? []) {
    const existing = byKey.get(s.key);
    if (!existing) {
      byKey.set(s.key, { ...s });
    } else {
      existing.magnitude += s.magnitude;
      existing.rounds = Math.max(existing.rounds, s.rounds);
    }
  }
  return [...byKey.values()];
}

const playerStatuses = computed(() => battle.value?.player_statuses ?? []);
const monsterStatuses = computed(() => battle.value?.monster_statuses ?? []);
const monsterIntent = computed(() => battle.value?.monster_intent ?? null);

// The REAL per-unit turn queue for this round (see MonsterAiService::speedOrder()) — fastest first,
// ties toward the party side then spawn order. One Attack/Skill click still resolves everyone else's
// turn for the rest of the round in one response (see CombatService::resolveRestOfRound()), so this
// strip is both the display ranking AND the actual order events play back in — they can't drift.
const speedOrder = computed(() => battle.value?.speed_order ?? []);

// A monster's `sprite` field is just its art's base name (e.g. "boar") — actual files are one per grade
// tier ("boar_common.png", "boar_elite.png", ...) since a Champion/Legendary roll is drawn distinctly.
// Every encounter (primary + adds) shares one grade roll (see GradeService), so there's a single lookup
// here rather than per-card.
function spriteSrc(sprite) {
  return `/images/monsters/${sprite}_${battle.value?.grade ?? 'common'}.png`;
}

// Sprites confirmed missing this session — enemyCards is a computed that rebuilds fresh row objects on
// every battle.value change (every animation step during playEvents), so setting `sprite = null`
// directly on a row would get discarded the instant the next HP-driven recompute ran, reverting to the
// broken filename and re-triggering the <img> @error handler on every combat event. Tracking it here
// instead, keyed by sprite name (not per-card), survives recomputes.
const brokenSprites = ref(new Set());
function markSpriteBroken(sprite) {
  if (!sprite || brokenSprites.value.has(sprite)) return;
  brokenSprites.value = new Set(brokenSprites.value).add(sprite);
}

// Every enemy (primary + adds), each carrying enough to render as a uniform grid card — including
// defeated ones (isDead), which stay on screen styled as fallen rather than vanishing from the grid the
// instant their HP hits 0. Used both for the enemy grid and for deriving threat/target-info below, so
// those two panels can never disagree with what's actually on screen.
const enemyCards = computed(() => {
  const rows = [];
  if (monster.value) {
    rows.push({
      id: null,
      name: monster.value.name,
      glyph: monster.value.glyph,
      sprite: brokenSprites.value.has(monster.value.sprite) ? null : monster.value.sprite,
      level: monster.value.min_level,
      role: monster.value.role,
      atk: battle.value?.monster_effective_atk ?? monster.value.atk,
      hp: battle.value?.monster_hp ?? 0,
      hpMax: monsterHpMax.value,
      statuses: monsterStatuses.value,
      intent: monsterIntent.value,
      threat: battle.value?.monster_threat ?? null,
      isDead: (battle.value?.monster_hp ?? 0) <= 0,
    });
  }
  for (const add of extraMonsters.value) {
    rows.push({
      id: add.id,
      name: add.monster?.name,
      glyph: add.monster?.glyph,
      sprite: brokenSprites.value.has(add.monster?.sprite) ? null : add.monster?.sprite,
      level: add.monster?.min_level,
      role: add.monster?.role,
      atk: add.effective_atk ?? add.monster?.atk,
      hp: add.hp,
      hpMax: add.hp_max,
      statuses: add.hp > 0 ? (add.statuses ?? []) : [],
      intent: add.hp > 0 ? add.intent : null,
      threat: add.hp > 0 ? (add.threat ?? null) : null,
      isDead: add.hp <= 0,
    });
  }
  return rows;
});
// Only the still-standing enemies — used for targeting/threat logic, which must never consider a
// defeated enemy a valid target even though its card still renders (see enemyCards above).
const livingEnemies = computed(() => enemyCards.value.filter((e) => !e.isDead));
const aliveCount = computed(() => livingEnemies.value.length);
const totalEnemyCount = computed(() => (monster.value ? 1 : 0) + extraMonsters.value.length);
const encounterLabel = computed(() => {
  if (hasAdds.value) return `Pack Encounter (${totalEnemyCount.value} enemies)`;
  if (monster.value?.is_boss) return 'Boss Encounter';
  return 'Encounter';
});

// The enemy the Attack button / target-info bar actually refers to right now — the selected add, or
// the primary monster when nothing's explicitly targeted (mirrors what the server itself falls back
// to in CombatService::resolveTargetedMonsters()).
const effectiveTarget = computed(() =>
  livingEnemies.value.find((e) => e.id === selectedTargetId.value) ?? livingEnemies.value[0] ?? null
);


// Aggro preview — reads the real per-enemy target (Battle::monster_threat / BattleMonster::threat,
// backed by ThreatService::topThreatLabel()) rather than guessing, so this panel can never disagree
// with the aggro tag shown directly on each enemy card or with who the enemy actually hits next.
const threatRows = computed(() => livingEnemies.value.map((e) => ({ from: e.name, to: e.threat?.label ?? 'You' })));

// Generic, role-driven tips (not per-monster-name hardcoding) — only ever mentions a role that's
// actually alive in THIS fight, so the panel can't describe a threat that isn't present.
const readTheFightTips = computed(() => {
  const roles = new Set(livingEnemies.value.map((e) => e.role));
  const tips = [];
  if (roles.has('mender')) tips.push({ tone: 'green', text: 'A Mender heals its wounded allies each round and never itself; kill it first if the fight is dragging.' });
  if (roles.has('caster')) tips.push({ tone: 'purple', text: 'A Caster charges for a round then Hexes a target, lowering crit chance; its intent line warns you first.' });
  if (roles.has('elite_brute')) tips.push({ tone: 'red', text: 'An Elite periodically lands a heavy Crushing Blow that also shreds armor; watch for its intent.' });
  if (roles.has('skirmisher')) tips.push({ tone: 'blue', text: 'A Skirmisher goes after whoever on your side has the lowest HP%, not necessarily you.' });
  if (roles.has('tank')) tips.push({ tone: 'gold', text: 'A Tank has much more HP and shrugs off damage; expect a longer fight, not a dangerous one.' });
  if (roles.has('buffer')) tips.push({ tone: 'blue', text: 'A Buffer periodically Rallies instead of attacking, empowering every other living enemy; worth focusing down.' });
  if (hasAdds.value) tips.push({ tone: 'gold', text: 'Clearing the whole pack pays a bonus reward: AOE skills trade single-target burst for hitting everyone at once.' });
  return tips;
});

const RESULT_META = {
  won: { icon: '🏆', title: 'Victory!', color: '#4ade80' },
  tamed: { icon: '🐾', title: 'Tamed!', color: '#a78bfa' },
};
const resultIcon = computed(() => RESULT_META[result.value?.outcome]?.icon ?? '💀');
const resultTitle = computed(() => RESULT_META[result.value?.outcome]?.title ?? 'Defeated');
const resultColor = computed(() => RESULT_META[result.value?.outcome]?.color ?? '#ff6a4d');

// Tamed companions fighting alongside the player this battle — empty unless at least one is active
// (see CombatService::start()). Each row has hp/hp_max plus a nested tamed_companion (name/glyph).
const companions = computed(() => battle.value?.battle_companions ?? []);
const hasCompanions = computed(() => companions.value.length > 0);

// Taming only works in 1v1 fights (no adds) once the target's ≤10% hp and it's not a boss — mirrors
// the exact guards CombatService::attemptTame() enforces server-side, so the button simply doesn't
// appear for an attempt that would just 422. tame_eligible === false means the (one-time, free)
// eligibility roll for THIS monster already came back "can't be tamed" — that's cached for the rest
// of the fight, so leaving the button up would just invite endless no-op clicks with zero feedback.
// Uses the raw hp/hpMax fraction rather than the rounded hpPct() display value — attemptTame() aborts
// on the exact fraction (e.g. 0.104 > 0.10), which Math.round'ing down to "10%" would otherwise let
// the button show for, only to 422 the moment it's clicked.
const canAttemptTame = computed(() =>
  battle.value?.status === 'active' &&
  !hasAdds.value &&
  !monster.value?.is_boss &&
  battle.value?.tame_eligible !== false &&
  !battle.value?.roster_full &&
  (characterStore.character?.level ?? 0) >= (battle.value?.tame_unlock_level ?? 1) &&
  monsterHpMax.value > 0 &&
  (battle.value?.monster_hp ?? 0) / monsterHpMax.value <= 0.10
);

const playerAtk = computed(() => characterStore.stats?.eff_atk ?? 0);
const playerDef = computed(() => characterStore.stats?.eff_def ?? 0);

// Same crit_down/vulnerable stacking StatusEffectService::modifiers() folds into the real formula —
// computed here from the same statuses the cards already render, so this preview can't disagree with
// what CombatService actually rolls next.
function sumStatus(statuses, key) {
  return (statuses ?? []).filter((s) => s.key === key).reduce((sum, s) => sum + s.magnitude, 0);
}
const playerCritChance = computed(() => Math.max(0, (characterStore.stats?.crit_chance ?? 0) - sumStatus(playerStatuses.value, 'crit_down')));
const playerCritDamageMult = computed(() => characterStore.stats?.crit_damage_mult ?? 1.8);

/** Predicted damage range + finishing-move hint for whichever enemy is currently targeted — every
 * number here (variance bounds, crit chance, target's own Vulnerable/Weaken stacks) mirrors
 * CombatService::act()'s real formula exactly, so this is a preview of what WILL happen, not a guess.
 * Monsters have no Defense stat in this game (only the player does), so there's no "mitigation %" to
 * show here — a target's Vulnerable/Weaken stacks are the only thing that actually shifts the range. */
const targetDamageMods = computed(() => {
  const s = effectiveTarget.value?.statuses ?? [];
  const dmgTakenMult = 1 + (sumStatus(s, 'vulnerable') + sumStatus(s, 'break')) / 100;

  return { dmgTakenMult };
});

function damageRangeFor(dmgMult, hits, loVariance, hiVariance) {
  const atk = playerAtk.value * dmgMult * hits * targetDamageMods.value.dmgTakenMult;
  const lo = Math.max(2, Math.round(atk * loVariance));
  const hi = Math.max(2, Math.round(atk * hiVariance * playerCritDamageMult.value));
  return { lo, hi };
}

const predictedHit = computed(() => {
  if (!effectiveTarget.value) return null;
  const { lo, hi } = damageRangeFor(1, 1, 0.8, 1.2);
  return { lo, hi, critChance: Math.round(playerCritChance.value) };
});

// ---- Animated turn-by-turn playback (Settings > Preferences > "Animate battles") ----
// CombatService::act() now returns a structured `events` array alongside the flat text log — one
// entry per damage/heal/status/dodge/stun moment, in the exact order they happened. When animations
// are on, act() below plays through them one at a time (floating numbers + a hit flash per card, and
// a locally-mutated HP number so the bar actually drains/fills in step) before swapping in the real
// server state — so a pack fight reads as "your pets swing, then each enemy swings" instead of every
// number changing at once. Turned off, the exact same events array is simply skipped and state applies
// immediately, matching the old instant-resolve behavior.
const animationsEnabled = computed(() => auth.user?.preferences?.animate_combat !== false);
const ANIM_STEP_MS = 620;
const floaters = ref([]);
const flashing = ref(new Set());
let floaterSeq = 0;

// Mirrors StatusEffectService::CATALOG's glyph/label — events only carry the status key, not its
// full metadata, so a small local lookup renders a readable floater ("Vulnerable") instead of a raw key.
// Mirrors StatusEffectService::CATALOG — glyph/label feed the combat floaters during playback below;
// tone/desc/stacking additionally drive the "Status Effects" glossary panel (see readTheFightTips'
// neighboring intel-panel), so the mechanical explanation shown there can never drift from what the
// backend actually catalogs.
const STATUS_META = {
  weaken: { glyph: '↓⚔', label: 'Weaken', tone: 'orange', desc: "Reduces the target's outgoing damage.", stacking: 'Each application is its own stack that ticks down independently; magnitudes add together while stacks overlap.' },
  vulnerable: { glyph: '⚠', label: 'Vulnerable', tone: 'purple', desc: 'Increases damage the target takes from every source.', stacking: 'Each application is its own stack that ticks down independently; magnitudes add together while stacks overlap.' },
  armor_shred: { glyph: '🛡↓', label: 'Armor Shred', tone: 'red', desc: "Reduces the target's effective Defense.", stacking: 'Each application is its own stack that ticks down independently; magnitudes add together while stacks overlap.' },
  crit_down: { glyph: '✦↓', label: 'Crit Down', tone: 'gray', desc: "Lowers the target's crit chance (flat points).", stacking: 'Each application is its own stack that ticks down independently; magnitudes add together while stacks overlap.' },
  evasion: { glyph: '💨', label: 'Evasion', tone: 'blue', desc: "Raises the target's dodge chance (flat points).", stacking: 'Each application is its own stack that ticks down independently; magnitudes add together while stacks overlap.' },
  haste: { glyph: '⚡', label: 'Haste', tone: 'gold', desc: 'Increases speed, so this unit acts sooner in the round order.', stacking: 'Each application is its own stack that ticks down independently; magnitudes add together while stacks overlap.' },
  slow: { glyph: '🐌', label: 'Slow', tone: 'gray', desc: 'Reduces speed, so this unit acts later in the round order.', stacking: 'Each application is its own stack that ticks down independently; magnitudes add together while stacks overlap.' },
  stun: { glyph: '💫', label: 'Stun', tone: 'gold', desc: "Skips this unit's next turn entirely, then wears off.", stacking: 'Any stack present skips the next turn, then exactly one stack is consumed — extra stacks queue up further skipped turns.' },
  freeze: { glyph: '❄', label: 'Freeze', tone: 'blue', desc: "Skips this unit's next turn entirely, then thaws.", stacking: 'Mechanically identical to Stun (same skip-a-turn effect), themed for ice/cold skills.' },
  burn: { glyph: '🔥', label: 'Burn', tone: 'red', desc: 'Flat damage at the start of each round, ignores Defense.', stacking: 'Each application is its own stack that ticks down independently; a skill for "25% of damage dealt" rolls that amount fresh every time it hits.' },
  poison: { glyph: '☠', label: 'Poison', tone: 'green', desc: "Deals a % of the target's own max HP at the start of each round, per stack.", stacking: 'Stacks accumulate with every application (no per-stack timer) and the whole pool loses exactly one stack per round.' },
  shield: { glyph: '🛡', label: 'Shield', tone: 'blue', desc: 'Absorbs the next hit(s) before HP is touched.', stacking: "Each application is its own pool, consumed oldest-first — a fresh Shield stacks on top of any that's left." },
  taunt: { glyph: '🎯', label: 'Taunt', tone: 'red', desc: 'Enemy attacks are concentrated onto this unit instead of splitting.', stacking: 'Any stack present forces every enemy attack this round; duplicate applications just refresh how long it lasts.' },
  break: { glyph: '💥🛡', label: 'Break', tone: 'red', desc: "Instantly shatters the target's Shield stacks, then increases damage taken like Vulnerable.", stacking: 'The shield-shatter happens once on application; the resulting damage-taken increase stacks and ticks down exactly like Vulnerable.' },
  root: { glyph: '🌿⛓', label: 'Root', tone: 'gray', desc: 'Cannot Flee the battle while rooted.', stacking: 'Any stack present blocks Flee; duplicate applications just refresh how long it lasts.' },
  mend_over_time: { glyph: '💚', label: 'Regen', tone: 'green', desc: 'Flat heal at the start of each round.', stacking: 'Each application is its own stack that ticks down independently; magnitudes add together while stacks overlap.' },
  atk_up: { glyph: '⚔↑', label: 'Empowered', tone: 'gold', desc: "Increases the target's outgoing damage.", stacking: 'Each application is its own stack that ticks down independently; magnitudes add together while stacks overlap.' },
};

// Mirrors MonsterAiService::ROLE_COMPANION_ABILITY's applies_status/buff effect per role — a tamed
// companion's role isn't otherwise carried into any client-side status table, so this small lookup lets
// the glossary below count a fighting companion's own status the same way it counts the player's skills.
const COMPANION_ROLE_STATUS = {
  brute: 'vulnerable', skirmisher: 'crit_down', caster: 'crit_down', elite_brute: 'armor_shred', tank: 'shield', buffer: 'atk_up',
};

function addAppliesStatusEffects(keys, appliesStatus) {
  for (const def of appliesStatus ?? []) {
    if (def?.effect) keys.add(def.effect);
  }
}

function addMonsterStatusEffects(keys, monster) {
  if (!monster) return;
  if (monster.status_effect_key) keys.add(monster.status_effect_key);
  for (const ability of monster.skills_json ?? []) {
    addAppliesStatusEffects(keys, ability?.applies_status);
  }
}

/** Every status_key this fight could actually put on screen — the player's own equipped actives (see
 * activeSkillRows above), whatever's fighting alongside them (a tamed companion's role ability), and
 * whatever the enemy/enemies present can inflict (Monster::status_effect_key, a species-themed bonus —
 * see MonsterAiService::SPECIES_STATUS — plus any skills_json ability that carries its own applies_status).
 * Drives the "Status Effects" glossary below so it only lists what's actually relevant to THIS fight
 * instead of the full ~16-entry catalog every time. */
const availableStatusKeys = computed(() => {
  const keys = new Set();

  for (const row of activeSkillRows.value) addAppliesStatusEffects(keys, row.skill?.effect_json?.applies_status);

  addMonsterStatusEffects(keys, battle.value?.monster);
  for (const row of battle.value?.battle_monsters ?? []) addMonsterStatusEffects(keys, row.monster);

  for (const row of battle.value?.battle_companions ?? []) {
    const role = row.tamed_companion?.role;
    if (role && COMPANION_ROLE_STATUS[role]) keys.add(COMPANION_ROLE_STATUS[role]);
  }

  return keys;
});

const visibleStatusMeta = computed(() =>
  Object.fromEntries(Object.entries(STATUS_META).filter(([key]) => availableStatusKeys.value.has(key)))
);

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

/** Same identity scheme the template already keys enemy/companion cards by — 'player', 'companion-<id>',
 * or 'monster-<id-or-0-for-primary>' — so a floater/flash spawned for an event lands on the exact card
 * that event actually happened to. */
function eventTargetKey(type, id) {
  if (type === 'player') return 'player';
  if (type === 'companion') return `companion-${id}`;

  return `monster-${id ?? 0}`;
}

function spawnFloater(key, text, tone) {
  const id = ++floaterSeq;
  floaters.value = [...floaters.value, { id, key, text, tone }];
  trackedTimeout(() => {
    floaters.value = floaters.value.filter((f) => f.id !== id);
  }, 900);
}

function flashTarget(key, cls) {
  const tagged = `${key}::${cls}`;
  flashing.value = new Set([...flashing.value, tagged]);
  trackedTimeout(() => {
    const next = new Set(flashing.value);
    next.delete(tagged);
    flashing.value = next;
  }, 420);
}

function isFlashing(key, cls) {
  return flashing.value.has(`${key}::${cls}`);
}

function floatersFor(key) {
  return floaters.value.filter((f) => f.key === key);
}

/** Nudges the LOCAL (still-displayed, pre-swap) battle snapshot's hp so the bar actually animates in
 * step with each floater rather than sitting frozen until the real server state swaps in at the end —
 * purely cosmetic, the authoritative numbers still come from the server response right after. */
function applyEventLocally(ev) {
  if (!battle.value || ev.amount == null) return;
  let delta = 0;
  if (ev.type === 'heal') delta = ev.amount;
  else if (ev.type === 'damage') delta = -ev.amount;
  if (delta === 0) return;

  if (ev.target === 'player') {
    battle.value.character_hp = Math.max(0, (battle.value.character_hp ?? 0) + delta);
  } else if (ev.target === 'monster') {
    if (!ev.target_id) {
      battle.value.monster_hp = Math.max(0, (battle.value.monster_hp ?? 0) + delta);
    } else {
      const row = (battle.value.battle_monsters ?? []).find((m) => m.id === ev.target_id);
      if (row) row.hp = Math.max(0, row.hp + delta);
    }
  } else if (ev.target === 'companion') {
    const row = (battle.value.battle_companions ?? []).find((c) => c.id === ev.target_id);
    if (row) row.hp = Math.max(0, Math.min(row.hp_max, row.hp + delta));
  }
}

// Matches the key the turn-order strip already renders each chip under (`entry.side + '-' + entry.id`,
// see MonsterAiService::speedOrder) — 'player' actor/target covers the player exactly, 'companion'
// covers a companion under the SAME 'player' side (speedOrder groups your whole party as one side),
// 'monster' maps to speedOrder's 'foe' side.
function turnKeyForActor(actor, actorId) {
  if (actor === 'player') return 'player-null';
  if (actor === 'companion') return `player-${actorId}`;
  if (actor === 'monster') return `foe-${actorId ?? 0}`;
  return null;
}

// Which turn-order chip is "acting" right now — starts (and rests, between rounds) on the player,
// since this engine resolves a whole round per click and it's always the player's move next. Moved to
// whoever's actually swinging as playEvents() works through this round's events, so the strip reads as
// a live turn indicator instead of a static list.
const currentTurnKey = ref('player-null');

/** Which card is actually DOING the acting for this event — distinct from eventTargetKey (who it's
 * aimed at). Only monster/companion actors get a card of their own to highlight; 'player'/'status'
 * actors have no separate "attacker" card worth pulsing (the player IS the one clicking Attack, and a
 * status tick isn't a fresh attack). Lets a multi-enemy pack fight show WHICH specific enemy card just
 * acted, not just the turn-order strip's small chip. */
function eventActorKey(actor, actorId) {
  if (actor === 'monster') return `monster-${actorId ?? 0}`;
  if (actor === 'companion') return `companion-${actorId}`;

  return null;
}

async function playEvents(events) {
  for (const ev of events) {
    if (componentUnmounted) return;
    const key = eventTargetKey(ev.target, ev.target_id);
    const turnKey = turnKeyForActor(ev.actor, ev.actor_id);
    if (turnKey) currentTurnKey.value = turnKey;

    const actorKey = eventActorKey(ev.actor, ev.actor_id);
    if (actorKey && actorKey !== key) flashTarget(actorKey, 'attacking');

    if (ev.type === 'damage') {
      applyEventLocally(ev);
      spawnFloater(key, `-${ev.amount}`, ev.crit ? 'crit' : 'damage');
      flashTarget(key, 'shake');
    } else if (ev.type === 'heal') {
      applyEventLocally(ev);
      spawnFloater(key, `+${ev.amount}`, 'heal');
      flashTarget(key, 'glow');
    } else if (ev.type === 'status') {
      const meta = STATUS_META[ev.status_key] ?? { glyph: '●', label: ev.status_key };
      spawnFloater(key, `${meta.glyph} ${meta.label}`, 'status');
      flashTarget(key, 'glow');
    } else if (ev.type === 'dodge') {
      spawnFloater(key, 'Dodge!', 'dodge');
    } else if (ev.type === 'stun') {
      spawnFloater(key, 'Stunned!', 'status');
    } else if (ev.type === 'undying') {
      spawnFloater(key, 'Undying!', 'heal');
    }

    await sleep(ANIM_STEP_MS);
  }

  // Round's over — rests back on the player, since it's their move again next.
  currentTurnKey.value = 'player-null';
}

const phaseLabel = computed(() => {
  if (!battle.value) return 'Idle';
  if (battle.value.status !== 'active') return RESULT_META[result.value?.outcome]?.title.replace('!', '') ?? 'Defeated';
  return 'In combat';
});
const phaseDotColor = computed(() => {
  if (battle.value?.status === 'active') return '#eab308';
  if (result.value?.outcome) return RESULT_META[result.value.outcome]?.color ?? '#e8482f';
  return 'rgba(255,255,255,.25)';
});

// Same primary button throughout (see template) — only its label/icon change with context, so
// starting the next fight never means hunting for a differently-labeled control.
const primaryLabel = computed(() => {
  if (battle.value?.status === 'active') return `⚔ Attack${effectiveTarget.value ? ' ' + effectiveTarget.value.name : ''}`;
  if (result.value?.outcome === 'won') return '↺ Fight again';
  if (result.value?.outcome === 'lost') return '♥ Continue';
  return '🚶 Walk';
});

/** Blue/violet/green tone per skill effect — matches the reward/consumable color language elsewhere
 * (heal = green, AOE = violet, plain damage = blue) instead of every skill button looking identical. */
function skillTone(skill) {
  if (skill.effect_json?.heal_hp_pct || skill.effect_json?.heal_hp_flat) return 'green';
  if (skill.effect_json?.aoe) return 'violet';
  return 'blue';
}

/** SINGLE/AOE/SUPPORT scope badge per skill tile — derived straight from effect_json, same source the
 * server actually resolves off, so this can never claim a scope the skill doesn't really have. */
function skillScope(skill) {
  if (skill.effect_json?.aoe) return { label: 'AOE', color: '#e8482f', desc: 'Hits every living enemy at once, not just your selected target.' };
  if ((skill.effect_json?.dmg_mult ?? 1) <= 0 && skill.effect_json?.applies_status) return { label: 'SUPPORT', color: '#4ade80', desc: "Applies a buff/debuff rather than dealing damage directly." };
  if (skill.effect_json?.heal_hp_pct || skill.effect_json?.heal_hp_flat) return { label: 'HEAL', color: '#4ade80', desc: 'Restores HP — a free action, does not end your turn.' };
  return { label: 'SINGLE', color: '#5cc7f5', desc: 'Hits only your selected target (or the primary enemy if none is selected).' };
}

/** Whether a NAV-listed page is unlocked at the character's current level — used to keep any quick
 * link/CTA on this page from pointing a still-locked character at a page that just bounces them to
 * LevelRequiredPage (see postBattleTiles below for the same check applied to a whole tile list). */
function navUnlocked(path) {
  const unlockLevel = NAV.find((n) => n.path === path)?.unlockLevel;
  return !unlockLevel || (characterStore.character?.level ?? 0) >= unlockLevel;
}

/** Real quick links to jump to after a fight resolves — not fictional "same foe" replays, just the
 * places a player actually goes right after combat. Shown alongside the full-width Walk button. */
const postBattleTiles = computed(() => {
  const tiles = [
    { to: '/dashboard', glyph: '⌂', name: 'The Inn', meta: 'Overview', tone: 'neutral' },
    { to: '/inventory', glyph: '▦', name: 'Inventory', meta: 'Gear & loot', tone: 'violet' },
    { to: '/quests', glyph: '✎', name: 'Quests', meta: 'Track progress', tone: 'green' },
    { to: '/shop', glyph: '◉', name: 'Shop', meta: 'Buy & sell', tone: 'neutral' },
  ];
  if (result.value?.leveled_up) {
    tiles.unshift({ to: '/skills', glyph: '🌟', name: 'Skill Tree', meta: 'Spend points', tone: 'red' });
  }
  if (dungeonRun.value?.completed) {
    tiles.unshift({ to: '/dungeons', glyph: '🏰', name: 'Back to Dungeons', meta: 'Run another', tone: 'red' });
  }
  // Drop any tile the character hasn't unlocked yet (Dashboard/Shop both gate above level 1) — a
  // still-locked quick link here would just bounce straight to /level-required.
  const level = characterStore.character?.level ?? 0;
  return tiles.filter((t) => {
    const navUnlockLevel = NAV.find((n) => n.path === t.to)?.unlockLevel;
    return !navUnlockLevel || level >= navUnlockLevel;
  });
});

async function resumeOrBrowse() {
  loading.value = true;
  try {
    // Character (for inventory/durability, and always refreshed here so regenPerTick/manaRegenPerTick
    // never go stale from whatever page was visited last) and the active-battle check don't depend on
    // each other — fire both in parallel instead of waterfalling, so a cold page load isn't paying for
    // both round trips back-to-back. onMounted below relies on this being the only characterStore.fetch()
    // call on mount — a second unconditional one used to double the request on every cold load.
    const [, activeRes] = await Promise.all([
      characterStore.fetch(),
      api.get('/battle/active'),
    ]);
    const { data } = activeRes;
    if (data.battle) {
      battle.value = data.battle;
      dungeonRun.value = data.dungeon_run;
      // No exact round count is persisted server-side (log_json is lines, not rounds) — this is the
      // closest available approximation for a fight resumed mid-way rather than started fresh here.
      roundCount.value = data.battle.log_json?.length ?? 0;

      // Check for low durability gear (returns true if warning was set)
      const hasLowDurability = checkLowDurabilityGear();

      // Only show dungeon/battle notice if no durability warning
      if (!hasLowDurability) {
        notice.value = dungeonRun.value
          ? `Resumed your dungeon run — stage ${dungeonRun.value.stage} / ${dungeonRun.value.total_stages}.`
          : 'Resumed your battle in progress.';
      }
    }
  } finally {
    loading.value = false;
  }
}

function checkLowDurabilityGear() {
  if (!characterStore.character?.inventory) {
    return false;
  }

  const equippedGear = characterStore.character.inventory.filter(inv => inv.equipped && inv.durability_max);

  const brokenGear = [];
  const lowDurabilityGear = [];

  equippedGear.forEach(inv => {
    const pct = (inv.durability / inv.durability_max) * 100;

    if (pct === 0) {
      brokenGear.push(inv);
    } else if (pct <= 20) {
      lowDurabilityGear.push(inv);
    }
  });

  if (brokenGear.length > 0 || lowDurabilityGear.length > 0) {
    let warning = '⚠️ ';

    if (brokenGear.length > 0) {
      const brokenNames = brokenGear.map(inv => inv.item.name).join(', ');
      warning += `BROKEN: ${brokenNames}`;
    }

    if (lowDurabilityGear.length > 0) {
      if (brokenGear.length > 0) warning += ' | ';
      const lowNames = lowDurabilityGear.map(inv => inv.item.name).join(', ');
      warning += `Low durability: ${lowNames}`;
    }

    warning += '. Visit Inventory to repair.';

    notice.value = warning;
    return true;
  }
  return false;
}

async function walk() {
  error.value = '';
  loading.value = true;
  try {
    // Ensure character data with inventory is loaded
    if (!characterStore.character?.inventory) {
      await characterStore.fetch();
    }

    const { data } = await api.post('/battle/walk');
    battle.value = data.battle;
    result.value = null;
    dungeonRun.value = null;
    roundCount.value = 0;

    // Check for low durability gear (returns true if warning was set)
    const hasLowDurability = checkLowDurabilityGear();

    // Only show grade notice if no durability warning
    if (!hasLowDurability) {
      notice.value = data.grade?.label && data.grade.label !== 'Common' ? `You encounter a ${data.grade.label} foe!` : '';
    }
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not find an enemy.';
  } finally {
    loading.value = false;
  }
}

async function act(type, extra = {}) {
  if (!battle.value || loading.value || animating.value) return;
  loading.value = true;
  error.value = '';
  try {
    const { data } = await api.post(`/battle/${battle.value.id}/action`, { type, ...extra });
    const updatedCharacter = data.result?.character ?? data.character;
    const updatedStats = data.result?.stats ?? data.stats;
    if (updatedCharacter) {
      characterStore.character = updatedCharacter;
    }
    if (updatedStats) {
      characterStore.stats = updatedStats;
    }

    // Items are a free action (see CombatService::act()'s $type==='item' branch) — they don't end
    // the turn, so they shouldn't advance the round counter either.
    if (type !== 'item') roundCount.value += 1;

    // The network round-trip is over — drop the "loading" dim right away rather than keeping the panel
    // darkened for the whole animated playback below too (which used to read as the page just freezing/
    // going dark instead of anything actually happening). `animating` still blocks a second action from
    // being submitted mid-playback, just without the visual dim.
    loading.value = false;

    // Play this round's structured events (see CombatService::act()'s new `events` array) against the
    // still-old `battle.value` before swapping in the authoritative result below — this is what makes a
    // pack fight read as "your pets swing one at a time, then each enemy swings" instead of every HP bar
    // and damage number changing in one instant snap. Skipped entirely (old snappy behavior) with
    // animations off or when nothing actually happened (e.g. a free-action item/heal with no events).
    if (data.events?.length && animationsEnabled.value) {
      animating.value = true;
      await playEvents(data.events);
      animating.value = false;
    }

    if (data.dungeon_run?.next_battle) {
      dungeonRun.value = data.dungeon_run;
      battle.value = data.dungeon_run.next_battle;
      result.value = null;
      roundCount.value = 0;
      // The stage's real gold/xp (data.result) was already granted server-side — surface it in the
      // notice text rather than silently discarding it, since the full reward-chip panel can't show
      // here without hiding the next stage's battle view (see the `v-if="result"` swap below).
      const stageReward = data.result;
      const rewardText = stageReward ? ` +${stageReward.gold} gold, +${stageReward.xp} XP.` : '';
      notice.value = `Stage cleared!${rewardText} Entering stage ${data.dungeon_run.stage} / ${data.dungeon_run.total_stages}.`;
    } else {
      battle.value = data.battle;
      result.value = data.result;
      if (data.dungeon_run) {
        dungeonRun.value = data.dungeon_run;
      }
    }

    // A failed/ineligible tame attempt doesn't end the battle (see CombatService::attemptTame()'s
    // continuingBattlePayload) — result stays null, so without this the only trace is a battle-log
    // line that's invisible whenever the log is collapsed. Surface that same line as a toast instead.
    if (type === 'tame' && !data.result && data.battle?.log_json?.length) {
      notice.value = data.battle.log_json[data.battle.log_json.length - 1];
    }

    // Fetch fresh character data to ensure dashboard XP and all stats update
    if (data.result && (data.result.outcome === 'won' || data.result.outcome === 'lost')) {
      characterStore.fetch();
    }
  } catch (e) {
    error.value = e.response?.data?.message || 'Action failed.';
    if (e.response?.status === 403 || e.response?.status === 404) {
      battle.value = null;
      result.value = null;
      dungeonRun.value = null;
    }
  } finally {
    loading.value = false;
    animating.value = false;
  }
}

async function flee() {
  if (!battle.value || loading.value) return;
  loading.value = true;
  error.value = '';
  try {
    const { data } = await api.post(`/battle/${battle.value.id}/action`, { type: 'flee' });
    const hpLost = data.result?.hp_lost ?? 0;
    notice.value = hpLost > 0 ? `You fled the battle, losing ${hpLost} HP on the way out.` : 'You fled the battle.';
    if (data.character) characterStore.character = data.character;
    if (data.stats) characterStore.stats = data.stats;
    battle.value = null;
    result.value = null;
    dungeonRun.value = null;
    roundCount.value = 0;
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not flee.';
  } finally {
    loading.value = false;
  }
}

// Note: the one periodic "game save" push (HP/MP/energy, including the battle's own HP counter via
// activeBattleId — see setBattleHp above) is entirely driven by useRegen() itself (composables/regen.js).
// This tick watcher only covers what's specific to this page: auto-battle countdown/polling.
watch(tickCount, (count) => {
  if (autoBattle.value.seconds_remaining > 0) autoBattle.value.seconds_remaining -= 1;
  // Only keeps polling /auto-battle (which also ticks queued fights forward server-side) while training
  // is actually running — otherwise there's nothing for it to learn every 20s for the rest of the
  // session just from sitting on this page.
  if (autoBattle.value.active && count % AUTO_BATTLE_POLL_TICKS === 0) loadAutoBattle();
});

onMounted(() => {
  resumeOrBrowse();
  loadAutoBattle();
});
</script>

<template>
  <div class="battle-page">
    <div class="battle-header">
      <div class="battle-header__icon">⚔</div>
      <h1 class="ox battle-title">Battle</h1>
      <span class="battle-header__zone">{{ currentZoneName }}</span>
    </div>

    <!-- Transient banners are toasts now (see Toast.vue) — an inline <p> here used to appear/disappear
    right under the header and shift the whole fight view below it on every notice/error. The durability
    warning is the one exception, kept as the persistent .battle-alert-bar sidebar banner below instead
    of a toast, since it's meant to stick around until repaired rather than auto-dismiss. -->
    <Toast :message="toastNotice" type="success" />
    <Toast :message="error" type="error" />

    <div v-if="autoBattleSummary" class="battle-top-row">
      <div class="claim-summary">
        <div class="claim-summary__header">
          <span class="ox claim-summary__title">🎉 While you were away</span>
          <button class="claim-summary__close" @click="autoBattleSummary = null">✕</button>
        </div>
        <div class="claim-summary__rows">
          <span class="claim-summary__chip">⚔ {{ autoBattleSummary.fights }} fought</span>
          <span class="claim-summary__chip">🏆 {{ autoBattleSummary.wins }} won</span>
          <span v-if="autoBattleSummary.losses" class="claim-summary__chip">💀 {{ autoBattleSummary.losses }} lost</span>
          <span v-if="autoBattleSummary.fled" class="claim-summary__chip">🏃 {{ autoBattleSummary.fled }} fled</span>
          <span class="claim-summary__chip">🪙 +{{ autoBattleSummary.gold }} gold</span>
          <span class="claim-summary__chip">✦ +{{ autoBattleSummary.xp }} xp</span>
          <span v-if="autoBattleSummary.gems" class="claim-summary__chip">💎 +{{ autoBattleSummary.gems }} gems</span>
        </div>
      </div>
    </div>

    <div class="battle-main">
    <div v-if="loading && !battle" class="battle-skeleton">
      <Skeleton height="120px" />
      <Skeleton height="220px" />
      <Skeleton height="60px" />
    </div>

    <!-- Fight view — one persistent screen for idle (no enemy yet), active combat, and a just-defeated
    enemy with rewards. Nothing here swaps to a different layout or overlay; only what's inside each
    panel changes. -->
    <div v-else class="fight-view">
      <div class="fight-view__main">
        <!-- Dims while a network round-trip is in flight (see act()) — deliberately scoped to just the
        cards/log above the action dock, NOT the dock itself: opacity on an ancestor still visually fades
        a position:fixed descendant (it composites the whole subtree at that alpha regardless of layout
        position), which on mobile — where .action-dock pins to the bottom of the screen — used to make
        its solid background translucent enough that whatever was scrolled underneath (the sidebar's
        auto-battle card, etc.) bled through the Attack button. -->
        <div class="fight-view__panel" :class="{ 'is-loading': loading }">
        <div class="fight-view__header">
          <div class="fight-view__status">
            <span class="fight-view__dot" :style="{ background: phaseDotColor }"></span>
            <span class="fight-view__phase" :style="{ color: phaseDotColor }">{{ phaseLabel }}</span>
            <span v-if="battle" class="fight-view__sep">·</span>
            <span v-if="battle" class="fight-view__round">Round {{ roundCount }}</span>
            <span v-if="dungeonRun" class="fight-view__sep">·</span>
            <span v-if="dungeonRun" class="fight-view__stage">Stage {{ dungeonRun.stage }} / {{ dungeonRun.total_stages }}</span>
          </div>
          <button
            class="flee-btn"
            :class="{ 'flee-btn--hidden': battle?.status !== 'active' }"
            @click="flee"
            :disabled="loading || animating || battle?.status !== 'active'"
          >
            Flee ↩
          </button>
        </div>

        <!-- Kept visible through the 'won'/'lost' result screen too (not just 'active') — speedOrder
        naturally drops the now-dead monster on its own (MonsterAiService::speedOrder only lists living
        combatants), so this shrinks by one chip rather than collapsing to nothing. Removing this whole
        block the instant a fight resolves was yanking the primary button (and everything below it) up
        right as the player might click again — see the predicted-hit-bar reservation below for the same
        fix applied to the one piece here that genuinely can't stay visible after the fight ends. -->
        <div v-if="speedOrder.length" class="speed-order">
          <span class="speed-order__label">TURN ORDER</span>
          <div class="speed-order__list">
            <span
              v-for="entry in speedOrder"
              :key="entry.side + '-' + entry.id"
              class="speed-order__chip tt"
              :class="[`speed-order__chip--${entry.side}`, { 'speed-order__chip--active': (entry.side + '-' + entry.id) === currentTurnKey }]"
              :data-tip="entry.role ? `${roleMeta(entry.role).label} · speed ${entry.speed}` : `speed ${entry.speed}`"
            >
              <span class="speed-order__glyph">{{ entry.glyph }}</span>
              <span class="speed-order__name">{{ entry.name }}</span>
              <span class="speed-order__spd">{{ entry.speed }}</span>
            </span>
          </div>
        </div>

        <div
          v-if="!(battle?.status === 'active' && monster)"
          class="monster-panel"
          :class="{ 'monster-panel--result': result }"
        >
          <!-- Same panel, same slot: once the fight resolves, the victory/defeat screen replaces the
          monster display in place rather than appearing as extra content further down the page — the
          rest of the layout (player panel, action grid, log) never shifts. -->
          <template v-if="result">
            <div class="reward-banner__head">
              <span class="reward-banner__icon">{{ resultIcon }}</span>
              <span class="ox reward-banner__title" :style="{ color: resultColor }">{{ resultTitle }}</span>
            </div>

            <div v-if="result.outcome === 'won'" class="reward-chips">
              <div class="reward-chip--gold">+{{ result.gold }} Gold</div>
              <div class="reward-chip--xp">+{{ result.xp }} XP</div>
              <div v-if="result.gems" class="reward-chip--gems">+{{ result.gems }} Gems</div>
              <div v-if="result.pet_food_dropped" class="reward-chip--gold">🍖 {{ result.pet_food_dropped }} +1</div>
              <div v-if="result.crate_dropped" class="reward-chip--gold">📦 {{ result.crate_dropped }} +1</div>
              <div v-for="drop in result.monster_loot_dropped" :key="drop" class="reward-chip--gold">✨ {{ drop }} +1</div>
              <div v-if="result.leveled_up" class="reward-chip--level">
                Level up! +{{ result.leveled_up * 3 }} attr · +{{ result.leveled_up }} skill pts
              </div>
              <div v-if="dungeonRun?.completed" class="reward-chip--gold">
                Dungeon cleared!<span v-if="dungeonRun.bonus?.gold"> +{{ dungeonRun.bonus.gold }}g</span><span v-if="dungeonRun.bonus?.gems"> +{{ dungeonRun.bonus.gems }} gems</span>
              </div>
            </div>

            <div v-else-if="result.outcome === 'tamed'" class="reward-chips">
              <div class="reward-chip--gold">{{ result.companion.glyph }} {{ result.companion.name }} joined your Companions!</div>
              <div class="reward-banner__subtitle">Caught, not killed — no gold/xp/gems from this one.</div>
            </div>

            <div v-else class="reward-chips">
              <div class="reward-banner__subtitle">Revived at 40% HP.</div>
              <div v-if="result.gold_lost" class="reward-chip--loss">-{{ result.gold_lost }} Gold</div>
              <div v-if="result.xp_lost" class="reward-chip--loss">-{{ result.xp_lost }} XP</div>
            </div>

            <!-- Reward breakdown (collapsible) -->
            <div v-if="result.outcome === 'won' && result.breakdown" class="result-reward-breakdown">
              <button class="result-reward-breakdown__toggle" @click="rewardBreakdownExpanded = !rewardBreakdownExpanded">
                {{ rewardBreakdownExpanded ? '▼' : '▶' }} Reward Breakdown
              </button>
              <div v-if="rewardBreakdownExpanded" class="reward-breakdown">
                <div class="reward-breakdown__section">
                  <div class="reward-breakdown__label">🪙 Gold Breakdown</div>
                  <div class="reward-breakdown__line">Base: {{ result.breakdown.gold.base }}g</div>
                  <div v-if="result.breakdown.gold.grade_mult > 1" class="reward-breakdown__line">Grade: ×{{ result.breakdown.gold.grade_mult }}</div>
                  <div v-if="result.breakdown.gold.luck_pct" class="reward-breakdown__line reward-breakdown__line--luck">Luck: +{{ result.breakdown.gold.luck_pct }}%</div>
                  <div v-if="result.breakdown.gold.vip_pct" class="reward-breakdown__line">Rank: +{{ result.breakdown.gold.vip_pct }}%</div>
                  <div v-if="result.breakdown.gold.guild_pct" class="reward-breakdown__line">Guild: +{{ result.breakdown.gold.guild_pct }}%</div>
                  <div v-if="result.breakdown.gold.zone_penalty_pct" class="reward-breakdown__line reward-breakdown__line--penalty">Below your zone: -{{ result.breakdown.gold.zone_penalty_pct }}%</div>
                </div>
                <div class="reward-breakdown__section">
                  <div class="reward-breakdown__label">✦ XP Breakdown</div>
                  <div class="reward-breakdown__line">Base: {{ result.breakdown.xp.base }} xp</div>
                  <div v-if="result.breakdown.xp.grade_mult > 1" class="reward-breakdown__line">Grade: ×{{ result.breakdown.xp.grade_mult }}</div>
                  <div v-if="result.breakdown.xp.luck_pct" class="reward-breakdown__line reward-breakdown__line--luck">Luck: +{{ result.breakdown.xp.luck_pct }}%</div>
                  <div v-if="result.breakdown.xp.vip_pct" class="reward-breakdown__line">Rank: +{{ result.breakdown.xp.vip_pct }}%</div>
                  <div v-if="result.breakdown.xp.guild_pct" class="reward-breakdown__line">Guild: +{{ result.breakdown.xp.guild_pct }}%</div>
                  <div v-if="result.breakdown.xp.pet_pct" class="reward-breakdown__line">Pet: +{{ result.breakdown.xp.pet_pct }}%</div>
                  <div v-if="result.breakdown.xp.zone_penalty_pct" class="reward-breakdown__line reward-breakdown__line--penalty">Below your zone: -{{ result.breakdown.xp.zone_penalty_pct }}%</div>
                </div>
                <div v-if="result.breakdown.gems" class="reward-breakdown__section">
                  <div class="reward-breakdown__label">💎 Gems Breakdown</div>
                  <div class="reward-breakdown__line">Base: {{ result.breakdown.gems.base }} gems</div>
                  <div v-if="result.breakdown.gems.grade_mult > 1" class="reward-breakdown__line">Grade: ×{{ result.breakdown.gems.grade_mult }}</div>
                  <div v-if="result.breakdown.gems.luck_pct" class="reward-breakdown__line reward-breakdown__line--luck">Luck: +{{ result.breakdown.gems.luck_pct }}%</div>
                  <div v-if="result.breakdown.gems.zone_penalty_pct" class="reward-breakdown__line reward-breakdown__line--penalty">Below your zone: -{{ result.breakdown.gems.zone_penalty_pct }}%</div>
                </div>
              </div>
            </div>

          </template>

          <template v-else>
            <div class="monster-art-stage monster-art-stage--empty">
              <div class="monster-art">❔</div>
            </div>
            <div class="ox monster-name">No enemy nearby</div>
            <p class="monster-panel__hint">Take a walk to find a fight in {{ currentZoneName }}.</p>
          </template>
        </div>

        <!-- Active fight — every living enemy (primary + any adds) renders as a uniform card in one
        grid, mirroring the imported "Solyx Battle Multi" design's pack layout instead of a distinct
        boss card with a separate list of adds below it. A solo fight is just a 1-card grid. -->
        <template v-else>
          <div class="battle-line__label battle-line__label--enemy">ENEMY LINE</div>
          <div class="enemy-grid-header">
            <span class="enemy-grid-header__zone">{{ currentZoneName }} <span class="enemy-grid-header__encounter">· {{ encounterLabel }}</span></span>
            <span v-if="hasAdds" class="enemy-grid-header__count">{{ aliveCount }} of {{ totalEnemyCount }} standing</span>
          </div>
          <!-- Battlefield backdrop — themed per the zone's danger tier (same palette WorldMapPage uses
          for this zone) rather than a real location photo/illustration, since no per-zone artwork
          assets exist anywhere in this game; this keeps it accurate to the zone you're actually in
          without inventing image assets that would need hosting/uploading. -->
          <div class="battlefield" :style="{ background: battlefieldTheme.art }">
            <span class="battlefield__watermark" :style="{ color: battlefieldTheme.color }">{{ characterStore.character?.zone?.glyph ?? '⚔' }}</span>
            <div class="enemy-grid" :class="{ 'enemy-grid--solo': !hasAdds }">
              <div
                v-for="e in enemyCards"
                :key="e.id ?? 'primary'"
                class="enemy-card"
                :class="{
                  'enemy-card--selectable': hasAdds && !e.isDead,
                  'enemy-card--selected': hasAdds && !e.isDead && effectiveTarget?.id === e.id,
                  'enemy-card--dead': e.isDead,
                  'is-hit': isFlashing(`monster-${e.id ?? 0}`, 'shake'),
                  'is-glow': isFlashing(`monster-${e.id ?? 0}`, 'glow'),
                  'is-attacking': isFlashing(`monster-${e.id ?? 0}`, 'attacking'),
                }"
                @click="hasAdds && !e.isDead && selectTarget(e.id)"
              >
                <div v-for="f in floatersFor(`monster-${e.id ?? 0}`)" :key="f.id" class="combat-floater" :class="`combat-floater--${f.tone}`">{{ f.text }}</div>
                <div class="enemy-card__head">
                  <span v-if="e.isDead" class="enemy-card__defeated-pill">DEFEATED</span>
                  <template v-else>
                    <span class="role-pill tt" :data-tip="roleMeta(e.role).desc" :style="{ color: roleMeta(e.role).color, borderColor: roleMeta(e.role).color }">{{ roleMeta(e.role).label }}</span>
                    <!-- Grade pill only in solo view — a pack card's head row already carries role +
                    (rank) + target pills with no room to spare, and the mockup's own group cards never
                    show a grade pill either (only the solo spotlight card does). -->
                    <span v-if="e.id === null && !hasAdds" class="monster-name__grade tt" :data-tip="`${gradeMeta.label} grade — the rarity roll for this encounter`" :style="{ color: gradeMeta.color, borderColor: gradeMeta.color }">{{ gradeMeta.label }}</span>
                    <span v-if="e.id === null && rankMeta" class="monster-name__grade tt" :data-tip="e.id === null && monster?.is_boss ? 'Boss — a much tougher fixed encounter' : 'Elite — a stronger-than-usual roll on this monster'" :style="{ color: rankMeta.color, borderColor: rankMeta.color }">{{ rankMeta.label }}</span>
                    <span v-if="hasAdds && effectiveTarget?.id === e.id" class="enemy-card__target-pill tt" data-tip="Your next Attack or single-target skill will hit this one">TARGET</span>
                  </template>
                </div>
                <div class="enemy-card__art">
                  <span class="enemy-card__art-ring"></span>
                  <img v-if="e.sprite" class="enemy-card__art-sprite" :src="spriteSrc(e.sprite)" :alt="e.name" @error="markSpriteBroken(e.sprite)" />
                  <template v-else>{{ e.glyph }}</template>
                </div>
                <div class="ox enemy-card__name">
                  {{ e.name }}
                  <span class="enemy-card__level">Lv.{{ e.level }}</span>
                </div>
                <div class="stat-bar-track enemy-card__bar">
                  <div class="stat-bar-fill--hp" :style="{ width: hpPct(e.hp, e.hpMax) + '%' }"></div>
                </div>
                <div class="enemy-card__stats">
                  <span class="ox">{{ e.hp }} / {{ e.hpMax }}</span>
                  <span class="enemy-card__atk">ATK {{ e.atk }}</span>
                </div>
                <div class="monster-intent monster-intent--sm monster-intent--reserved tt" :data-tip="e.intent?.desc ?? ''">{{ e.intent ? `${e.intent.glyph} ${e.intent.text}` : '' }}</div>
                <div v-if="e.threat" class="aggro-tag tt" data-tip="Who this enemy's attacks are currently focused on — taunt and threat generated by damage/healing decide this every round">🎯 {{ e.threat.label }}</div>
                <div class="status-pill-row status-pill-row--sm status-pill-row--reserved">
                  <span
                    v-for="p in dedupeStatusPills(e.statuses)"
                    :key="p.key"
                    class="status-pill status-pill--sm tt"
                    :data-tip="p.desc"
                    :style="{ color: statusToneColor(p.tone), borderColor: statusToneColor(p.tone) }"
                  >{{ statusPillLabel(p) }}</span>
                </div>
              </div>
            </div>
          </div>

          <!-- Compact single-line summary of whatever's currently targeted — mainly useful once the
          enemy grid is several small cards (a pack fight): each card's own intent line is easy to miss
          on a narrow phone screen, so this repeats just the targeted one big and legible. -->
          <div v-if="hasAdds && effectiveTarget" class="target-intent-bar">
            <span class="target-intent-bar__glyph">{{ effectiveTarget.intent?.glyph ?? '🎯' }}</span>
            <span class="target-intent-bar__text"><span class="ox target-intent-bar__name">{{ effectiveTarget.name }}</span> — {{ effectiveTarget.intent?.text ?? 'No telegraphed action yet.' }}</span>
          </div>
        </template>

        <!-- A "VS" divider bridges the enemy line above to the party line below, only while a fight is
        actually in progress — the two sides read as one facing-off battlefield instead of an enemy
        section and a wholly separate party section. -->
        <!-- Stays up through the result screen too (see the speed-order comment above) — the enemy
        panel already swaps to the reward banner "in the same slot", so a VS divider between it and the
        party line still reads fine, and removing it here no longer yanks the button below upward. -->
        <div v-if="!!battle" class="vs-divider"><span>VS</span></div>

        <!-- Your line — the player rendered as the SAME shape/size card as an enemy/companion card
        (circular art, HP bar, stat line, status pills), sitting in the same row as any tamed
        companions, so "cards, us vs enemy" is literally true instead of the player getting one big
        distinct panel above a smaller companions row. -->
        <div class="battle-line battle-line--party">
          <div v-if="!!battle" class="battle-line__label battle-line__label--party">YOUR LINE</div>
          <div class="party-grid">
            <div class="player-card" :class="{ 'is-hit': isFlashing('player', 'shake'), 'is-glow': isFlashing('player', 'glow'), 'is-attacking': isFlashing('player', 'attacking') }">
              <div v-for="f in floatersFor('player')" :key="f.id" class="combat-floater" :class="`combat-floater--${f.tone}`">{{ f.text }}</div>
              <div class="player-card__art"><span class="player-card__art-ring"></span>{{ playerGlyph }}</div>
              <div class="ox player-card__name">
                {{ characterStore.character?.name }}
                <span class="enemy-card__level">Lv.{{ characterStore.character?.level }}</span>
              </div>
              <div class="stat-bar-track player-card__bar">
                <div class="stat-bar-fill--hp" :class="{ 'stat-bar-fill--regenerating': isRegeneratingHp }" :style="{ width: hpBarPct + '%' }"></div>
              </div>
              <div class="player-card__stats">
                <span class="hp tt" data-tip="Restored between fights, or by heal skills/potions mid-battle">{{ displayCharacterHp }} / {{ playerHpMax }} HP</span>
              </div>
              <div class="stat-bar-track player-card__bar player-card__bar--mp">
                <div class="stat-bar-fill--mp" :class="{ 'stat-bar-fill--regenerating': isRegeneratingMana }" :style="{ width: mpBarPct + '%' }"></div>
              </div>
              <div class="player-card__stats">
                <span class="mp tt" data-tip="Spent on active skills — regenerates over time, including mid-battle">{{ displayCharacterMana }} / {{ playerMpMax }} MP</span>
              </div>
              <div class="player-card__combat-stats">
                <span class="tt" data-tip="Effective Attack: your damage stat after gear, attributes, and skills">ATK <span class="ox player-card__stat-value--atk">{{ playerAtk }}</span></span>
                <span class="tt" data-tip="Effective Defense — reduces incoming damage after gear, attributes, and skills">DEF <span class="ox player-card__stat-value--def">{{ playerDef }}</span></span>
              </div>
              <div class="status-pill-row status-pill-row--sm status-pill-row--reserved">
                <span
                  v-for="p in dedupeStatusPills(playerStatuses)"
                  :key="p.key"
                  class="status-pill status-pill--sm tt"
                  :data-tip="p.desc"
                  :style="{ color: statusToneColor(p.tone), borderColor: statusToneColor(p.tone) }"
                >{{ statusPillLabel(p) }}</span>
              </div>
            </div>

            <div
              v-for="c in companions"
              :key="c.id"
              class="add-card"
              :class="{
                'add-card--dead': c.hp <= 0,
                'is-hit': isFlashing(`companion-${c.id}`, 'shake'),
                'is-glow': isFlashing(`companion-${c.id}`, 'glow'),
                'is-attacking': isFlashing(`companion-${c.id}`, 'attacking'),
              }"
            >
              <div v-for="f in floatersFor(`companion-${c.id}`)" :key="f.id" class="combat-floater" :class="`combat-floater--${f.tone}`">{{ f.text }}</div>
              <div class="add-card__art"><span class="add-card__art-ring"></span>{{ c.tamed_companion?.glyph }}</div>
              <div class="add-card__name">
                {{ c.hp > 0 ? c.tamed_companion?.name : `${c.tamed_companion?.name} (down)` }}
                <span v-if="c.hp > 0" class="role-pill role-pill--sm tt" :data-tip="roleMeta(c.tamed_companion?.role).desc" :style="{ color: roleMeta(c.tamed_companion?.role).color, borderColor: roleMeta(c.tamed_companion?.role).color }">{{ roleMeta(c.tamed_companion?.role).label }}</span>
                <span
                  v-if="c.tamed_companion?.grade"
                  class="add-card__grade tt"
                  :data-tip="`${gradeMetaFor(c.tamed_companion.grade).label} grade — caught at a rarer roll, permanently stronger`"
                  :style="{ color: gradeMetaFor(c.tamed_companion.grade).color, borderColor: gradeMetaFor(c.tamed_companion.grade).color }"
                >{{ gradeMetaFor(c.tamed_companion.grade).label }}</span>
              </div>
              <div class="stat-bar-track add-card__bar">
                <div class="stat-bar-fill--hp" :style="{ width: hpPct(c.hp, c.hp_max) + '%' }"></div>
              </div>
              <div v-if="c.hp > 0" class="stat-bar-track add-card__bar add-card__bar--mp">
                <div class="stat-bar-fill--mp" :style="{ width: hpPct(c.mp, c.mp_max) + '%' }"></div>
              </div>
              <div class="add-card__hp">
                {{ c.hp }} / {{ c.hp_max }} HP · {{ c.tamed_companion?.effective_atk }} ATK · {{ c.tamed_companion?.effective_def }} DEF
                <template v-if="c.hp > 0">· {{ c.mp }} / {{ c.mp_max }} MP</template>
              </div>
              <div v-if="c.hp > 0 && c.ability" class="companion-ability tt" :data-tip="c.ability.desc">{{ c.ability.glyph }} {{ c.ability.name }}<span class="companion-ability__cost">{{ c.ability.mp_cost }} MP</span></div>
              <div class="status-pill-row status-pill-row--sm status-pill-row--reserved">
                <span
                  v-for="p in dedupeStatusPills(c.statuses)"
                  :key="p.key"
                  class="status-pill status-pill--sm tt"
                  :data-tip="p.desc"
                  :style="{ color: statusToneColor(p.tone), borderColor: statusToneColor(p.tone) }"
                >{{ statusPillLabel(p) }}</span>
              </div>
            </div>
          </div>
        </div>
        </div>
        <!-- End fight-view__panel -->

        <!-- Predicted-damage preview for whichever enemy is currently targeted — every number mirrors
        CombatService::act()'s real formula (see predictedHit above), so it previews
        what will actually happen rather than guessing. Unlike the turn-order strip/VS divider above,
        this genuinely stops making sense once the fight resolves (there's no more live target to predict
        a hit against) — so instead of removing it (which used to yank the primary button up the instant
        a fight ended, right as the player might click again), it always renders and only its VISIBILITY
        toggles, via .predicted-hit-bar--hidden below. That keeps its box's height reserved either way. -->
        <!-- Everything a player actually acts through — predicted hit, primary button, skills,
        consumables — grouped into one dock so it can pin to the bottom of the screen on mobile (see
        .action-dock's mobile breakpoint) instead of scrolling away above the fold. -->
        <div class="action-dock">
        <div class="predicted-hit-bar" :class="{ 'predicted-hit-bar--hidden': !(battle?.status === 'active' && predictedHit) }">
          <template v-if="battle?.status === 'active' && predictedHit">
            <span class="predicted-hit-bar__target">TARGET <span class="ox">{{ effectiveTarget?.name }}</span></span>
            <span class="predicted-hit-bar__stat">Predicted hit <span class="ox">{{ predictedHit.lo }}–{{ predictedHit.hi }}</span></span>
            <span class="predicted-hit-bar__stat">crit <span class="ox">{{ predictedHit.critChance }}%</span></span>
          </template>
          <span v-else>&nbsp;</span>
        </div>

        <!-- Same full-width primary button always — Attack mid-fight, and Walk/Fight again/Continue
        once there's no live fight to act in, so starting the next encounter never requires jumping to
        a different part of the screen or a differently-sized button. -->
        <button
          v-if="battle?.status === 'active'"
          class="btn-attack"
          @click="act('attack', { target_monster_id: effectiveTarget?.id ?? null })"
          :disabled="loading || animating"
        >
          {{ primaryLabel }}
        </button>
        <button v-else class="btn-attack" @click="walk" :disabled="loading || animating">{{ primaryLabel }}</button>

        <button
          v-if="canAttemptTame"
          class="btn-attack btn-tame"
          @click="act('tame')"
          :disabled="loading || animating"
        >
          🐾 Attempt to Tame
        </button>

        <!-- Skills while actually fighting — full-width rows, color-coded by effect (blue = damage,
        violet = AOE, green = heal/support), with Consumables as its own centered button below rather
        than crowded into the same grid. -->
        <div v-if="battle?.status === 'active'" class="skill-grid">
          <button
            v-for="row in activeSkillRows"
            :key="row.skill.id"
            class="skill-tile"
            :class="`skill-tile--${skillTone(row.skill)}`"
            @click="act('skill', { skill_id: row.skill.id, target_monster_id: effectiveTarget?.id ?? null })"
            :disabled="loading || animating || characterStore.character?.mana < row.skill.mp_cost || (skillCooldowns[row.skill.id] ?? 0) > 0"
          >
            <div class="skill-tile__top">
              <span class="skill-tile__glyph">{{ row.skill.glyph }}</span>
              <span class="skill-tile__name">{{ row.skill.name }}</span>
            </div>
            <!-- Exact, rank-scaled numbers combat actually uses (CharacterSkill::effect_description) —
            e.g. "1.9x ATK damage on use" or "Vulnerable +40% on enemy for 2 rounds" — not just the MP cost. -->
            <span v-if="row.effect_description" class="skill-tile__info">{{ row.effect_description }}</span>
            <div class="skill-tile__bottom">
              <template v-if="(skillCooldowns[row.skill.id] ?? 0) > 0">
                <span class="skill-tile__cost">{{ skillCooldowns[row.skill.id] }} round{{ skillCooldowns[row.skill.id] > 1 ? 's' : '' }}</span>
              </template>
              <template v-else>
                <span class="skill-tile__cost">{{ row.skill.mp_cost }} MP</span>
              </template>
              <span class="scope-pill tt" :data-tip="skillScope(row.skill).desc" :style="{ color: skillScope(row.skill).color, borderColor: skillScope(row.skill).color }">{{ skillScope(row.skill).label }}</span>
            </div>
          </button>
        </div>
        <div v-if="battle?.status === 'active'" class="consumables-row">
          <button
            class="btn-consumables"
            @click="consumableGridOpen = true"
            :disabled="loading || animating || !usableConsumables.length"
          >
            🧪 Consumables{{ usableConsumables.length ? ` (${usableConsumables.length})` : '' }}
          </button>
          <p v-if="!usableConsumables.length" class="no-consumables-hint">No usable potions or elixirs.</p>
        </div>
        </div>
        <!-- End action-dock -->

        <!-- Quick links once the fight's resolved (or before the first one) — real destinations, not
        a re-fight of "the same foe" which the game doesn't actually track as a distinct action. Its own
        v-if (not v-else) since the dock wrapper above now sits between it and the consumables-row it
        used to sit right next to. -->
        <div v-if="battle?.status !== 'active'" class="action-tiles">
          <router-link
            v-for="t in postBattleTiles"
            :key="t.to"
            :to="t.to"
            class="action-tile"
            :class="`action-tile--${t.tone}`"
          >
            <div class="action-tile__glyph">{{ t.glyph }}</div>
            <div class="action-tile__name">{{ t.name }}</div>
            <div class="action-tile__meta">{{ t.meta }}</div>
          </router-link>
        </div>

        <div v-if="consumableGridOpen" class="consumable-modal-overlay" @click.self="consumableGridOpen = false">
          <div class="consumable-modal">
            <div class="consumable-modal__head">
              <span class="ox consumable-modal__title">Use a consumable</span>
              <button class="consumable-modal__close" @click="consumableGridOpen = false">✕</button>
            </div>
            <p class="consumable-modal__hint">Doesn't use your turn — pick freely, then act.</p>
            <div class="consumable-grid">
              <button
                v-for="row in usableConsumables"
                :key="row.item_id"
                class="consumable-card"
                @click="useConsumable(row)"
                :disabled="loading || animating"
              >
                <span class="consumable-card__glyph">{{ row.item.glyph || '🧪' }}</span>
                <span class="ox consumable-card__name">{{ row.item.name }}</span>
                <span class="consumable-card__effect">{{ consumableLabel(row).split('(')[1]?.replace(')', '') }}</span>
                <span class="consumable-card__qty">×{{ row.qty }}</span>
              </button>
            </div>
          </div>
        </div>

        <div v-if="battle?.log_json?.length" class="battle-log">
          <button class="battle-log__toggle" @click="battleLogExpanded = !battleLogExpanded">
            <span class="battle-log__toggle-label">BATTLE LOG</span>
            <span v-if="!battleLogExpanded" class="battle-log__peek">{{ displayedBattleLog[displayedBattleLog.length - 1] }}</span>
            <span class="battle-log__chevron" :class="{ 'battle-log__chevron--open': battleLogExpanded }">▾</span>
          </button>
          <div v-if="battleLogExpanded" v-twemoji class="battle-log__body">
            <div
              v-for="(line, i) in displayedBattleLog"
              :key="i"
              class="battle-log__line"
              :style="{ color: logColor(line) }"
            >
              {{ line }}
            </div>
          </div>
        </div>
      </div>

      <div class="fight-view__intel">
        <!-- Moved here from above the fight view (battle-top-row) — it used to sit between the header
        and the combat cards, pushing the whole fight down and forcing a scroll on shorter viewports just
        to see the actual battle. Living in the sidebar means it's visible alongside the fight instead of
        above it. -->
        <div class="auto-battle-card">
          <p v-if="autoBattleMessage" class="auto-battle-card__summary">{{ autoBattleMessage }}</p>
          <div v-if="autoBattle.active" class="auto-battle-card__status">
            <span class="auto-battle-card__label">🤖 Training active</span>
            <span class="auto-battle-card__timer">{{ formatDuration(autoBattle.seconds_remaining) }} remaining</span>
          </div>
          <div class="auto-battle-card__buy">
            <span class="auto-battle-card__label">
              {{ autoBattle.active ? '🤖 Buy more time' : '🤖 Training — fights for you, fully risk-free' }}
            </span>
            <div class="auto-battle-card__options">
              <button
                v-for="minutes in [15, 30, 60]"
                :key="minutes"
                class="auto-battle-card__option"
                :disabled="(characterStore.character?.account_gems ?? 0) < (autoBattle.costs[minutes] ?? 0)"
                @click="buyAutoBattle(minutes)"
              >
                {{ minutes }}m · 💎{{ autoBattle.costs[minutes] ?? '—' }}
              </button>
              <button class="auto-battle-card__option auto-battle-card__option--cash" @click="buyAutoBattleCash('auto_battle_480')">
                8h · €0.99
              </button>
            </div>
          </div>
        </div>

        <div v-if="battle?.status === 'active' && Object.keys(visibleStatusMeta).length" class="intel-panel">
          <div class="intel-panel__title">STATUS EFFECTS</div>
          <p class="intel-panel__desc">Buffs/debuffs your skills, your companion, or this fight's enemies can actually apply here — hover a tag for how repeated applications stack.</p>
          <div class="status-glossary-table">
            <div v-for="(meta, key) in visibleStatusMeta" :key="key" class="status-glossary-row">
              <span class="status-glossary-row__cell status-glossary-row__cell--pill">
                <span
                  class="status-pill status-pill--sm tt"
                  :data-tip="meta.stacking"
                  :style="{ color: statusToneColor(meta.tone), borderColor: statusToneColor(meta.tone) }"
                >{{ meta.glyph }} {{ meta.label }}</span>
              </span>
              <span class="status-glossary-row__cell status-glossary-row__desc">{{ meta.desc }}</span>
            </div>
          </div>
        </div>

        <div v-if="battle?.status === 'active' && hasCompanions" class="intel-panel">
          <div class="intel-panel__title">AGGRO</div>
          <p class="intel-panel__desc">Who each enemy's attack actually lands on next — a taunting companion draws everything; a Skirmisher always goes after whoever's lowest HP%; otherwise it's whoever's built up the most threat against that enemy (damage generates threat on the enemy hit; healing spreads a share of it to every enemy at once).</p>
          <div class="threat-rows">
            <div v-for="(row, i) in threatRows" :key="i" class="threat-row">
              <span class="threat-row__from">{{ row.from }}</span>
              <span class="threat-row__arrow">→</span>
              <span class="threat-row__to">{{ row.to }}</span>
            </div>
          </div>
        </div>

        <div v-if="battle?.status === 'active' && hasAdds" class="intel-panel">
          <div class="intel-panel__title">PACK REWARD</div>
          <div class="pack-reward-rows">
            <div class="pack-reward-row">
              <span class="pack-reward-row__label">Enemies remaining</span>
              <span class="ox pack-reward-row__value">{{ aliveCount }} of {{ totalEnemyCount }}</span>
            </div>
          </div>
          <p class="intel-panel__desc intel-panel__desc--bottom">
            The gold/XP payout scales with how many of the pack you clear — the full breakdown shows once the fight ends.
          </p>
        </div>

        <div v-if="battle?.status === 'active' && readTheFightTips.length" class="intel-panel">
          <div class="intel-panel__title">READ THE FIGHT</div>
          <div class="tip-rows">
            <div v-for="(tip, i) in readTheFightTips" :key="i" class="tip-row">
              <span class="tip-row__dot" :style="{ background: statusToneColor(tip.tone) }"></span>
              <span class="tip-row__text">{{ tip.text }}</span>
            </div>
          </div>
        </div>

        <div
          v-if="notice && (notice.includes('BROKEN') || notice.includes('Low durability'))"
          class="battle-alert-bar"
          :class="{ 'battle-alert-bar--danger': notice.includes('BROKEN') }"
        >
          <span class="battle-alert-bar__icon">⚠</span>
          <span class="battle-alert-bar__text">{{ notice.replace('⚠️ ', '') }}</span>
          <router-link v-if="navUnlocked('/inventory')" to="/inventory" class="battle-alert-bar__cta">Repair →</router-link>
        </div>

        <AdBanner variant="sidebar" />
      </div>
    </div>
    <!-- End fight view -->

    </div>
    <!-- End battle-main -->

    <TutorialOverlay v-if="characterStore.character && !characterStore.character.tutorial_seen" />
  </div>
</template>

<style lang="scss" src="./BattlePage.scss" scoped></style>
