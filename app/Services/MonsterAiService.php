<?php

namespace App\Services;

use App\Models\Battle;
use App\Models\Monster;

class MonsterAiService
{
    public const ROLES = ['brute', 'skirmisher', 'caster', 'mender', 'elite_brute', 'tank', 'buffer'];

    /** Fallback kit for any monster seeded/created without its own skills_json — matches the old flat-attack behavior. */
    private const DEFAULT_KIT = [
        ['key' => 'basic_attack', 'name' => 'Attack', 'type' => 'attack', 'dmg_mult' => 1.0, 'hits' => 1, 'weight' => 100, 'cooldown' => 0],
    ];

    /** Nominal base speed per role, used only to rank the display-only "speed order" strip (see
     * speedOrder() below) — there is no persisted speed/initiative stat anywhere else in the game.
     * Agile roles (caster/skirmisher) rank above the player's flat baseline; heavy roles (elite_brute)
     * rank near the bottom, mirroring the imported design mockup's relative ordering. */
    private const ROLE_SPEED = [
        'skirmisher' => 112,
        'caster' => 108,
        'brute' => 95,
        'mender' => 90,
        'elite_brute' => 78,
    ];

    private const PLAYER_SPEED = 100;

    /** Build-identity multipliers per role — what makes some monsters play as a genuinely tankier build,
     * others a rally-support caster, etc., without a schema change (Monster only tracks a base hp/atk;
     * role reshapes how those numbers actually perform in a fight). hp_mult is applied once at spawn
     * (see CombatService::start()); atk_mult/dmg_taken_mult are applied live in resolveMonsterAction()/
     * finalizeDamageToTarget() every time that monster deals or takes a hit. */
    private const ROLE_BUILD = [
        'brute' => ['hp_mult' => 1.0, 'atk_mult' => 1.0, 'dmg_taken_mult' => 1.0],
        'skirmisher' => ['hp_mult' => 0.85, 'atk_mult' => 1.15, 'dmg_taken_mult' => 1.1],
        'caster' => ['hp_mult' => 0.8, 'atk_mult' => 1.1, 'dmg_taken_mult' => 1.15],
        'mender' => ['hp_mult' => 0.9, 'atk_mult' => 0.85, 'dmg_taken_mult' => 1.0],
        'elite_brute' => ['hp_mult' => 1.3, 'atk_mult' => 1.1, 'dmg_taken_mult' => 0.85],
        // A genuinely tanky build — much higher HP and noticeably reduced incoming damage, in exchange
        // for hitting softer. The monster-variety half of "certain monsters are more tanky."
        'tank' => ['hp_mult' => 1.6, 'atk_mult' => 0.7, 'dmg_taken_mult' => 0.6],
        // Rallies its allies instead of reliably attacking (see roleSpecial()'s 'role_rally') — a mild,
        // slightly-fragile support build rather than a real damage threat on its own.
        'buffer' => ['hp_mult' => 0.9, 'atk_mult' => 0.9, 'dmg_taken_mult' => 1.0],
    ];

    /** A themed bonus status a specific named monster's attack has a chance to also apply, on top of
     * whatever its role/kit already does — keyed by Monster::key, so it fires identically whether that
     * monster is fought live (see CombatService::resolveMonsterAction()) or fighting FOR you as a tamed
     * companion (see CombatService::applyCompanionAttacks()). This is what makes "Fire Imp" actually
     * apply Burn, "Giant Spider"/"Sand Reaver" actually Poison, etc. — matching the enemy's own name/
     * flavor rather than every monster just being a flat-damage stat stick regardless of identity. */
    private const SPECIES_STATUS = [
        // Tutorial-only roster
        'meadow_rat' => ['effect' => 'weaken', 'magnitude' => 6, 'rounds' => 2, 'chance' => 30],
        'startled_hare' => ['effect' => 'slow', 'magnitude' => 10, 'rounds' => 2, 'chance' => 30],
        'brook_sprite' => ['effect' => 'crit_down', 'magnitude' => 4, 'rounds' => 2, 'chance' => 30],

        // Whispering Meadows
        'slime' => ['effect' => 'armor_shred', 'magnitude' => 8, 'rounds' => 2, 'chance' => 35],
        'wild_boar' => ['effect' => 'vulnerable', 'magnitude' => 10, 'rounds' => 2, 'chance' => 35],
        'bandit_scout' => ['effect' => 'weaken', 'magnitude' => 10, 'rounds' => 2, 'chance' => 35],
        'giant_spider' => ['effect' => 'poison', 'magnitude' => 1, 'rounds' => 1, 'chance' => 40],

        // Dark Forest
        'forest_stalker' => ['effect' => 'crit_down', 'magnitude' => 6, 'rounds' => 2, 'chance' => 30],
        'shadow_wolf' => ['effect' => 'armor_shred', 'magnitude' => 10, 'rounds' => 2, 'chance' => 30],
        'dark_spirit' => ['effect' => 'slow', 'magnitude' => 15, 'rounds' => 2, 'chance' => 30],
        'rogue_knight' => ['effect' => 'crit_down', 'magnitude' => 10, 'rounds' => 2, 'chance' => 40],

        // Frostpeak Caverns
        'frost_bat' => ['effect' => 'freeze', 'magnitude' => 1, 'rounds' => 1, 'chance' => 25],
        'stone_golem' => ['effect' => 'break', 'magnitude' => 15, 'rounds' => 2, 'chance' => 30],
        'ice_golem' => ['effect' => 'slow', 'magnitude' => 18, 'rounds' => 2, 'chance' => 35],
        'frost_wyrm' => ['effect' => 'freeze', 'magnitude' => 1, 'rounds' => 1, 'chance' => 30],

        // Emberpeak Volcano
        'magma_slug' => ['effect' => 'armor_shred', 'magnitude' => 12, 'rounds' => 2, 'chance' => 35],
        'fire_imp' => ['effect' => 'burn', 'magnitude_pct_of_damage' => 25, 'rounds' => 2, 'chance' => 35],
        'cinder_hound' => ['effect' => 'burn', 'magnitude_pct_of_damage' => 20, 'rounds' => 2, 'chance' => 30],
        'ashfang_dragon' => ['effect' => 'burn', 'magnitude_pct_of_damage' => 30, 'rounds' => 3, 'chance' => 40],

        // Sunken Abyss
        'deep_eel' => ['effect' => 'stun', 'magnitude' => 1, 'rounds' => 1, 'chance' => 25],
        'tide_serpent' => ['effect' => 'slow', 'magnitude' => 18, 'rounds' => 2, 'chance' => 30],
        'drowned_wretch' => ['effect' => 'root', 'magnitude' => 1, 'rounds' => 2, 'chance' => 35],
        'abyss_kraken' => ['effect' => 'root', 'magnitude' => 1, 'rounds' => 2, 'chance' => 40],

        // The Void
        'void_wraith' => ['effect' => 'weaken', 'magnitude' => 14, 'rounds' => 2, 'chance' => 30],
        'nether_horror' => ['effect' => 'vulnerable', 'magnitude' => 14, 'rounds' => 2, 'chance' => 30],
        'void_stalker' => ['effect' => 'crit_down', 'magnitude' => 10, 'rounds' => 2, 'chance' => 30],
        'void_sovereign' => ['effect' => 'stun', 'magnitude' => 1, 'rounds' => 1, 'chance' => 30],

        // Crimson Wastes
        'sand_reaver' => ['effect' => 'poison', 'magnitude' => 1, 'rounds' => 1, 'chance' => 40],
        'dust_wraith' => ['effect' => 'slow', 'magnitude' => 20, 'rounds' => 2, 'chance' => 30],
        'bone_colossus' => ['effect' => 'armor_shred', 'magnitude' => 16, 'rounds' => 2, 'chance' => 35],
        'sandstorm_titan' => ['effect' => 'vulnerable', 'magnitude' => 18, 'rounds' => 2, 'chance' => 40],

        // Astral Expanse
        'star_leech' => ['effect' => 'weaken', 'magnitude' => 16, 'rounds' => 2, 'chance' => 30],
        'astral_seraph' => ['effect' => 'crit_down', 'magnitude' => 10, 'rounds' => 2, 'chance' => 30],
        'comet_devourer' => ['effect' => 'burn', 'magnitude_pct_of_damage' => 25, 'rounds' => 2, 'chance' => 35],
        'astral_emperor' => ['effect' => 'vulnerable', 'magnitude' => 20, 'rounds' => 3, 'chance' => 40],
    ];

    /** A tamed companion's role-flavored special move — same role identity system as
     * ROLE_BUILD/roleSpecial() above, mirrored onto the player's own side of the fight (see
     * CombatService::resolveCompanionTurn()). Gated by the companion's own small MP pool
     * (BattleCompanion::mp/mp_max, regenerating a flat amount per round) instead of firing every turn —
     * when MP is short of `mp_cost`, the companion just attacks plainly instead. `mender` has no
     * dmg_mult (it heals, never attacks, when it fires — see resolveCompanionTurn's existing menderHeal
     * branch) and `buffer` targets itself rather than the enemy. */
    public const ROLE_COMPANION_ABILITY = [
        'brute' => ['name' => 'Savage Bite', 'glyph' => '🦷', 'mp_cost' => 40, 'mp_regen_per_round' => 15, 'dmg_mult' => 1.5, 'desc' => '1.5x damage, applies Vulnerable +15% for 2 rounds.', 'applies_status' => ['effect' => 'vulnerable', 'magnitude' => 15, 'rounds' => 2]],
        'skirmisher' => ['name' => 'Puncture', 'glyph' => '🗡', 'mp_cost' => 35, 'mp_regen_per_round' => 18, 'dmg_mult' => 1.3, 'desc' => '1.3x damage, applies Crit Down 6 for 2 rounds.', 'applies_status' => ['effect' => 'crit_down', 'magnitude' => 6, 'rounds' => 2]],
        'caster' => ['name' => 'Hex Bolt', 'glyph' => '☄', 'mp_cost' => 35, 'mp_regen_per_round' => 18, 'dmg_mult' => 1.1, 'desc' => '1.1x damage, applies Crit Down 8 for 2 rounds.', 'applies_status' => ['effect' => 'crit_down', 'magnitude' => 8, 'rounds' => 2]],
        'elite_brute' => ['name' => 'Heavy Slam', 'glyph' => '💥', 'mp_cost' => 50, 'mp_regen_per_round' => 12, 'dmg_mult' => 1.8, 'desc' => '1.8x damage, applies Armor Shred 15% for 2 rounds.', 'applies_status' => ['effect' => 'armor_shred', 'magnitude' => 15, 'rounds' => 2]],
        'tank' => ['name' => 'Guard Bash', 'glyph' => '🛡', 'mp_cost' => 45, 'mp_regen_per_round' => 14, 'dmg_mult' => 1.2, 'desc' => '1.2x damage, shields itself for 2 rounds.', 'applies_status' => ['effect' => 'shield', 'magnitude' => 60, 'rounds' => 2, 'target' => 'self']],
        'buffer' => ['name' => 'Rally Cry', 'glyph' => '📣', 'mp_cost' => 45, 'mp_regen_per_round' => 14, 'desc' => 'Empowers you — Attack +15% for 2 rounds.', 'buff' => ['effect' => 'atk_up', 'magnitude' => 15, 'rounds' => 2, 'target' => 'player']],
        'mender' => ['name' => 'Mend', 'glyph' => '💚', 'mp_cost' => 30, 'mp_regen_per_round' => 20, 'heal_pct' => 18, 'desc' => 'Heals the weakest ally for 18% of its max HP.'],
    ];

    public function companionAbility(?string $role): array
    {
        return self::ROLE_COMPANION_ABILITY[$role ?? 'brute'] ?? self::ROLE_COMPANION_ABILITY['brute'];
    }

    public function __construct(private StatusEffectService $statuses) {}

    /** @return array{effect: string, magnitude?: float, magnitude_pct_of_damage?: float, rounds: int, chance: int}|null */
    public function speciesStatus(?string $monsterKey): ?array
    {
        return self::SPECIES_STATUS[$monsterKey] ?? null;
    }

    /** Just the status key half of speciesStatus() above, callable without instantiating the service —
     * see Monster::getStatusEffectKeyAttribute(), which appends this to every serialized Monster so the
     * frontend can know what a monster can inflict (e.g. BattlePage's Status Effects glossary) without
     * duplicating this whole table client-side. */
    public static function speciesStatusKey(?string $monsterKey): ?string
    {
        return self::SPECIES_STATUS[$monsterKey]['effect'] ?? null;
    }

    public function buildFor(?string $role): array
    {
        return self::ROLE_BUILD[$role ?? 'brute'] ?? self::ROLE_BUILD['brute'];
    }

    /**
     * Picks the monster's next ability for this turn, weighted among whatever's off cooldown, then advances
     * the cooldown clock. $cooldowns is the battle's persisted per-ability-key "turns remaining" map.
     *
     * @return array{0: array, 1: array} [chosen ability, updated cooldowns]
     */
    public function choose(Monster $monster, array $cooldowns): array
    {
        foreach ($cooldowns as $key => $remaining) {
            $cooldowns[$key] = max(0, $remaining - 1);
        }

        $kit = $this->kitFor($monster);
        $available = array_values(array_filter($kit, fn (array $a) => ($cooldowns[$a['key']] ?? 0) <= 0));
        if (! $available) {
            // Every ability happened to be on cooldown (shouldn't happen if a 0-cooldown attack exists) — fall
            // back to whatever has no cooldown at all, then to the universal default so the monster always acts.
            $available = array_values(array_filter($kit, fn (array $a) => ($a['cooldown'] ?? 0) === 0));
        }
        if (! $available) {
            $available = self::DEFAULT_KIT;
        }

        $total = array_sum(array_map(fn (array $a) => $a['weight'] ?? 1, $available));
        $roll = mt_rand(1, max(1, $total));
        $cumulative = 0;
        $chosen = $available[0];
        foreach ($available as $ability) {
            $cumulative += $ability['weight'] ?? 1;
            if ($roll <= $cumulative) {
                $chosen = $ability;
                break;
            }
        }

        if (($chosen['cooldown'] ?? 0) > 0) {
            $cooldowns[$chosen['key']] = $chosen['cooldown'];
        }

        return [$chosen, $cooldowns];
    }

    /** Read-only preview of the ability that would fire right now — used to show a player the enemy's
     * "intent" line before they act (see Battle::getMonsterIntentAttribute()/BattleMonster's mirror).
     * Deliberately deterministic (highest-weight off-cooldown ability) rather than replaying choose()'s
     * random roll, since a preview has to be stable across repeated reads of the same battle state.
     *
     * A plain filler attack (key 'basic_attack', 0 cooldown so always "available") is ALWAYS sorted
     * last here regardless of its own weight — every monster's real special (Hex, Crushing Blow, Ice
     * Shard Barrage, ...) is authored with a lower weight than its own basic attack precisely because
     * it's meant to be the rarer, more dangerous move, but that same weight gap meant this preview could
     * never surface it: the filler is available every single round, so a plain highest-weight sort
     * picked it forever and a telegraphed special could never actually be telegraphed. Once a special
     * clears its cooldown, it's the more useful thing to warn about, so it now wins the preview outright
     * whenever it's available; "Attack" is only shown once every real special is on cooldown. */
    public function predictIntent(Monster $monster, array $cooldowns): array
    {
        $kit = $this->kitFor($monster);
        $available = array_values(array_filter($kit, fn (array $a) => ($cooldowns[$a['key']] ?? 0) <= 0));
        if (! $available) {
            $available = self::DEFAULT_KIT;
        }
        usort($available, function (array $a, array $b) {
            $aFiller = ($a['key'] ?? null) === 'basic_attack' ? 1 : 0;
            $bFiller = ($b['key'] ?? null) === 'basic_attack' ? 1 : 0;

            return $aFiller <=> $bFiller ?: ($b['weight'] ?? 1) <=> ($a['weight'] ?? 1);
        });
        $top = $available[0];

        $glyph = match (true) {
            $top['type'] === 'regen' => '💚',
            $top['type'] === 'buff' => '✨',
            ! empty($top['applies_status']) => '⚠',
            ($top['hits'] ?? 1) > 1 || ! empty($top['aoe']) => '💥',
            default => '⚔',
        };

        return ['glyph' => $glyph, 'text' => $top['name'] ?? 'Attack', 'desc' => $this->describeAbility($top)];
    }

    /** Plain-English mechanical summary of one ability, shown as the intent line's tooltip — built from
     * the exact numbers CombatService will actually resolve (dmg_mult/hits/aoe/applies_status/heal_pct/
     * buff_*), reusing StatusEffectService::CATALOG's labels so a status name here can never drift from
     * what the status pill itself says once it lands. */
    private function describeAbility(array $ability): string
    {
        if ($ability['type'] === 'regen') {
            return "Heals itself for {$ability['heal_pct']}% of its max HP.";
        }

        if ($ability['type'] === 'buff') {
            $meta = StatusEffectService::CATALOG[$ability['buff_effect']] ?? null;
            $label = $meta['label'] ?? $ability['buff_effect'];

            return "Empowers itself and every living ally — {$label} +{$ability['buff_magnitude']}% for {$ability['buff_rounds']} rounds.";
        }

        $hits = $ability['hits'] ?? 1;
        $mult = $ability['dmg_mult'] ?? 1.0;
        $parts = [
            ! empty($ability['aoe']) ? "Hits everyone at once, {$mult}x damage."
                : ($hits > 1 ? "{$hits} hits at {$mult}x damage each." : "{$mult}x damage."),
        ];

        foreach ($ability['applies_status'] ?? [] as $status) {
            $meta = StatusEffectService::CATALOG[$status['effect']] ?? null;
            $label = $meta['label'] ?? $status['effect'];
            $unit = $meta['unit'] ?? 'flat';
            $amount = $unit === 'pct' ? "{$status['magnitude']}%" : ($unit === 'flag' ? '' : $status['magnitude']);
            $parts[] = trim("Applies {$label}".($amount !== '' ? " {$amount}" : '')." for {$status['rounds']} rounds.");
        }

        return implode(' ', $parts);
    }

    /** The REAL per-unit turn queue for this round — every living participant (player, companions,
     * primary monster, adds), fastest first, ties broken toward the party side then by original spawn
     * order (stable, never random). This is what CombatService::act() actually walks to resolve a round
     * (see its buildQueue()/resolveRestOfRound()) — not just a display ranking — so the turn-order strip
     * BattlePage.vue renders from this same array can never drift from what really happens next. Mirrors
     * the imported "Solyx Battle Multi" design's own queue-sort rule exactly:
     * `alive.sort(by -speed, then party first, then spawn index)`. */
    public function speedOrder(Battle $battle): array
    {
        $speedFor = fn (string $targetType, ?int $targetId, ?string $role) => (int) round(
            ($role === null ? self::PLAYER_SPEED : (self::ROLE_SPEED[$role] ?? self::ROLE_SPEED['brute']))
            * $this->statuses->modifiers($battle, $targetType, $targetId)['speed_mult']
        );

        $entries = [[
            'side' => 'player', 'id' => null, 'role' => null,
            'name' => $battle->character?->name ?? 'You', 'glyph' => '✷',
            'speed' => $speedFor('player', null, null),
        ]];

        foreach ($battle->battleCompanions as $bc) {
            if ($bc->hp <= 0) {
                continue;
            }
            $role = $bc->tamedCompanion?->role ?? 'brute';
            $entries[] = [
                'side' => 'player', 'id' => $bc->id, 'role' => $role,
                'name' => $bc->tamedCompanion?->name ?? 'Companion', 'glyph' => $bc->tamedCompanion?->glyph ?? '◔',
                'speed' => $speedFor('companion', $bc->id, $role),
            ];
        }

        if ($battle->monster_hp > 0) {
            $role = $battle->monster?->role ?? 'brute';
            $entries[] = [
                'side' => 'foe', 'id' => 0, 'role' => $role,
                'name' => $battle->monster?->name ?? 'Enemy', 'glyph' => $battle->monster?->glyph ?? '◆',
                'speed' => $speedFor('monster', 0, $role),
            ];
        }

        foreach ($battle->battleMonsters as $bm) {
            if ($bm->hp <= 0) {
                continue;
            }
            $role = $bm->monster?->role ?? 'brute';
            $entries[] = [
                'side' => 'foe', 'id' => $bm->id, 'role' => $role,
                'name' => $bm->monster?->name ?? 'Enemy', 'glyph' => $bm->monster?->glyph ?? '◆',
                'speed' => $speedFor('monster', $bm->id, $role),
            ];
        }

        for ($i = 0; $i < count($entries); $i++) {
            $entries[$i]['_idx'] = $i;
        }
        usort($entries, fn (array $a, array $b) => $b['speed'] <=> $a['speed']
            ?: ($a['side'] === $b['side'] ? 0 : ($a['side'] === 'player' ? -1 : 1))
            ?: $a['_idx'] <=> $b['_idx']);

        return array_values(array_map(function (array $e) {
            unset($e['_idx']);

            return $e;
        }, $entries));
    }

    /** The monster's own skills_json/DEFAULT_KIT plus one extra role-granted "special" move layered on
     * top — this is what makes a `caster` hex or an `elite_brute` crushing blow available on every
     * monster with that role without hand-authoring effect_json into 28+ individual seed rows. */
    private function kitFor(Monster $monster): array
    {
        $kit = ! empty($monster->skills_json) ? $monster->skills_json : self::DEFAULT_KIT;
        $special = $this->roleSpecial($monster);

        return $special ? [...$kit, $special] : $kit;
    }

    private function roleSpecial(Monster $monster): ?array
    {
        $scale = $this->debuffScaleForZone($monster->zone?->danger);

        return match ($monster->role ?? 'brute') {
            // Hexes the player with a crit-chance debuff (per the user's own example: "less crit chance")
            // instead of just hitting harder — a lighter hit than a plain attack, trading damage for utility.
            'caster' => [
                'key' => 'role_hex', 'name' => 'Hex', 'type' => 'attack', 'dmg_mult' => 0.7, 'hits' => 1, 'weight' => 45, 'cooldown' => 2,
                'applies_status' => $scale ? [['effect' => 'crit_down', 'magnitude' => $scale['crit_down'], 'rounds' => 2]] : [],
            ],
            // A stronger, more reliable self-heal than the generic 'regen' kit entry — meaningfully
            // distinct in a fight even without allies to mend (adds/pack support comes in later phases).
            'mender' => ['key' => 'role_mend', 'name' => 'Mend', 'type' => 'regen', 'heal_pct' => 18, 'weight' => 70, 'cooldown' => 2],
            // Strips armor (per the user's own example: "weaken armor") on top of a heavy hit — a slower,
            // more telegraphed attack (longer cooldown) that hits much harder than the monster's basic kit.
            'elite_brute' => [
                'key' => 'role_crushing_blow', 'name' => 'Crushing Blow', 'type' => 'attack', 'dmg_mult' => 1.6, 'hits' => 1, 'weight' => 30, 'cooldown' => 3,
                'applies_status' => $scale ? [['effect' => 'armor_shred', 'magnitude' => $scale['armor_shred'], 'rounds' => 2]] : [],
            ],
            // Empowers itself and every other living monster on its side instead of attacking — a real
            // support behavior (mirrors 'mender' healing instead of attacking), giving packs a rally
            // threat that makes leaving it alive actually cost something. See
            // CombatService::applyMonsterSelfBuff().
            'buffer' => [
                'key' => 'role_rally', 'name' => 'Rally', 'type' => 'buff', 'weight' => 70, 'cooldown' => 3,
                'buff_effect' => 'atk_up', 'buff_magnitude' => 20, 'buff_rounds' => 2,
            ],
            // Pins the target down with a heavier hit — a slower, more telegraphed attack that saps
            // outgoing damage instead of raw defense (distinct flavor from elite_brute's armor-shred).
            'brute' => [
                'key' => 'role_pin_down', 'name' => 'Pin Down', 'type' => 'attack', 'dmg_mult' => 1.3, 'hits' => 1, 'weight' => 35, 'cooldown' => 3,
                'applies_status' => $scale ? [['effect' => 'weaken', 'magnitude' => $scale['weaken'], 'rounds' => 2]] : [],
            ],
            // A quick, lighter strike that hobbles the target's footing rather than hitting hard —
            // fits the role's speed/evasion identity better than a raw damage bump would.
            'skirmisher' => [
                'key' => 'role_hamstring', 'name' => 'Hamstring', 'type' => 'attack', 'dmg_mult' => 1.2, 'hits' => 1, 'weight' => 35, 'cooldown' => 2,
                'applies_status' => $scale ? [['effect' => 'slow', 'magnitude' => $scale['slow'], 'rounds' => 2]] : [],
            ],
            default => null,
        };
    }

    /** Enemy-applied debuffs are off entirely in the starter (`safe`) zone, then scale up with danger —
     * "only starting in the 2nd zone and increasing... the stronger the enemies get" per the user's ask.
     * GM-tunable so a balance pass never needs another code change. */
    private function debuffScaleForZone(?string $danger): ?array
    {
        return match ($danger) {
            'medium' => [
                'crit_down' => \App\Models\GameConfig::number('enemy_debuff_crit_down_medium', 5),
                'armor_shred' => \App\Models\GameConfig::number('enemy_debuff_armor_shred_medium', 10),
                'weaken' => \App\Models\GameConfig::number('enemy_debuff_weaken_medium', 8),
                'slow' => \App\Models\GameConfig::number('enemy_debuff_slow_medium', 10),
            ],
            'high' => [
                'crit_down' => \App\Models\GameConfig::number('enemy_debuff_crit_down_high', 8),
                'armor_shred' => \App\Models\GameConfig::number('enemy_debuff_armor_shred_high', 20),
                'weaken' => \App\Models\GameConfig::number('enemy_debuff_weaken_high', 14),
                'slow' => \App\Models\GameConfig::number('enemy_debuff_slow_high', 16),
            ],
            'deadly' => [
                'crit_down' => \App\Models\GameConfig::number('enemy_debuff_crit_down_deadly', 12),
                'armor_shred' => \App\Models\GameConfig::number('enemy_debuff_armor_shred_deadly', 30),
                'weaken' => \App\Models\GameConfig::number('enemy_debuff_weaken_deadly', 20),
                'slow' => \App\Models\GameConfig::number('enemy_debuff_slow_deadly', 22),
            ],
            default => null,
        };
    }
}
