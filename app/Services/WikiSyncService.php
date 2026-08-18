<?php

namespace App\Services;

use App\Http\Controllers\Api\CosmeticController;
use App\Models\ArtAsset;
use App\Models\Character;
use App\Models\CharacterPet;
use App\Models\ClassProgression;
use App\Models\Cosmetic;
use App\Models\Dungeon;
use App\Models\Event;
use App\Models\GameConfig;
use App\Models\Item;
use App\Models\ItemSet;
use App\Models\Monster;
use App\Models\Pet;
use App\Models\Quest;
use App\Models\Recipe;
use App\Models\Skill;
use App\Models\WikiEntry;
use App\Models\Zone;
use Illuminate\Support\Str;

/**
 * Keeps every wiki category derived directly from live game data, instead of the hand-typed
 * snapshot the wiki started from — so a GM edit in the content editor (or a seeder rebalance)
 * can never leave the wiki showing stale zones/dungeons/skills/stats.
 *
 * Each syncXxx() writes two things onto the WikiEntry: `stats` (a short, card-sized headline —
 * 3-5 chips max) and `details` (everything else — full stat breakdowns, tier/grade/rank pickers,
 * acquisition info, zone rosters — meant for a per-entry detail view, not the card grid). `details`
 * is always shaped `['tier_kind' => ..., 'tiers' => [...], 'acquisition' => [...], 'full_stats' =>
 * [...], 'found_here' => [...]]`, with `tier_kind` null (and `tiers` empty) for categories that have
 * no real tier/grade/rank concept, so the frontend can render one generic detail view for every
 * category rather than one bespoke component per type.
 */
class WikiSyncService
{
    /** [hp, base_atk, mp, base_def] — mirrors CharacterController::store()'s starting stats per base class. */
    private const BASE_CLASS_STATS = [
        'warrior' => [230, 12, 90, 14],
        'mage' => [155, 11, 240, 8],
        'rogue' => [180, 13, 120, 10],
        'ranger' => [195, 12, 140, 11],
    ];

    private const BASE_CLASS_META = [
        'warrior' => ['glyph' => '⚔', 'sub' => 'Tank', 'desc' => 'High HP and defense; branches into a profession at level 20.'],
        'mage' => ['glyph' => '✷', 'sub' => 'Caster', 'desc' => 'Fragile burst caster with the deepest mana pool; branches into a profession at level 20.'],
        'rogue' => ['glyph' => '🗡', 'sub' => 'Crit', 'desc' => 'Fast, evasive and crit-focused; branches into a profession at level 20.'],
        'ranger' => ['glyph' => '🏹', 'sub' => 'Ranged', 'desc' => 'Precise ranged DPS; branches into a profession at level 20.'],
    ];

    public function __construct(private GradeService $grades = new GradeService()) {}

    private const ITEM_STAT_META = [
        'atk' => ['label' => 'ATK', 'color' => '#ff8163'],
        'def' => ['label' => 'DEF', 'color' => '#5cc7f5'],
        'crit' => ['label' => 'crit', 'color' => '#eab308', 'pct' => true],
        'mp' => ['label' => 'MP', 'color' => '#38bdf8'],
        'mana_regen_flat' => ['label' => 'Mana Regen/tick', 'color' => '#38bdf8'],
        'luck' => ['label' => 'Luck', 'color' => '#4ade80'],
        'lifesteal_pct' => ['label' => 'lifesteal', 'color' => '#a78bfa', 'pct' => true],
        'dodge_pct' => ['label' => 'dodge', 'color' => '#4ade80', 'pct' => true],
    ];

    private const PET_STAT_LABELS = [
        'atk_pct' => 'ATK',
        'def_pct' => 'DEF',
        'crit_pct' => 'Crit',
        'xp_pct' => 'XP',
        'hp_pct' => 'HP',
        'dodge_flat' => 'Dodge Chance',
        'gather_speed_pct' => 'Gathering Speed',
        'craft_speed_pct' => 'Crafting Speed',
    ];

    /** Monsters roll one of these 4 art grades at random per encounter (see GmArtController::GRADES) —
     * the wiki shows whichever is the best one an artist has actually finished, highest tier first. */
    private const MONSTER_ART_GRADES = ['legendary', 'champion', 'elite', 'common'];

    /** Human labels for MonsterAiService::ROLES — drives the AI ability/targeting behavior shown here for flavor. */
    private const MONSTER_ROLE_LABELS = [
        'brute' => 'Brute',
        'skirmisher' => 'Skirmisher',
        'caster' => 'Caster',
        'mender' => 'Mender',
        'elite_brute' => 'Elite Brute',
    ];

    /** Representative pet rank checkpoints — CharacterPet::MAX_RANK is 50, far too many to list one by
     * one, so these 4 give an honest sense of the curve (base, a third of the way, halfway, capped). */
    private const PET_RANK_CHECKPOINTS = [0, 10, 25, 50];

    public function syncMonsters(): void
    {
        Monster::with('zone')->get()->each(fn (Monster $m) => $this->syncMonster($m));
    }

    public function syncMonster(Monster $monster): void
    {
        $monster->loadMissing('zone');

        // Bosses always fight at fixed stats; trash/elite monsters roll a Grade (Common-Legendary) on
        // every Walk encounter that scales HP/ATK/rewards, so their real stats are a range, not one number.
        if ($monster->is_boss) {
            $stats = [
                $this->chip("HP {$monster->hp}", '#4ade80'),
                $this->chip("ATK {$monster->atk}", '#ff8163'),
                $this->chip("{$monster->gold}g · {$monster->xp}xp", '#eab308', true),
            ];
            if ($monster->gems > 0) {
                $stats[] = $this->chip("{$monster->gems}◆", '#a78bfa', true);
            }
            $stats[] = $this->chip('BOSS', '#e8482f');
        } else {
            $tiers = $this->grades->all();
            $hpMult = array_column($tiers, 'hp_mult');
            $atkMult = array_column($tiers, 'atk_mult');
            $rewardMult = array_column($tiers, 'reward_mult');

            $stats = [
                $this->chip('HP '.round($monster->hp * min($hpMult)).'–'.round($monster->hp * max($hpMult)), '#4ade80'),
                $this->chip('ATK '.round($monster->atk * min($atkMult)).'–'.round($monster->atk * max($atkMult)), '#ff8163'),
                $this->chip(
                    round($monster->gold * min($rewardMult)).'–'.round($monster->gold * max($rewardMult)).'g · '.
                    round($monster->xp * min($rewardMult)).'–'.round($monster->xp * max($rewardMult)).'xp',
                    '#eab308',
                    true
                ),
            ];
            if ($monster->gems > 0) {
                $stats[] = $this->chip(round($monster->gems * min($rewardMult)).'–'.round($monster->gems * max($rewardMult)).'◆', '#a78bfa', true);
            }
        }

        // AI role drives which ability weighting/targeting behavior the monster actually uses in battle
        // (see MonsterAiService::ROLES) — worth surfacing so players know what kind of fight to expect.
        $roleLabel = self::MONSTER_ROLE_LABELS[$monster->role] ?? ucfirst(str_replace('_', ' ', $monster->role));
        $stats[] = $this->chip("Role: {$roleLabel}", '#a78bfa', true);
        if ($monster->is_elite) {
            $stats[] = $this->chip('ELITE', '#eab308');
        }

        $gradeTiers = $this->monsterGradeTiers($monster);
        $fullStats = array_merge($stats, $this->monsterAbilityStats($monster));
        if (! $monster->is_tutorial) {
            $fullStats[] = ['label' => 'Tameable', 'value' => "Lv.{$monster->tameUnlockLevel()}"];
        }

        [$image, $artist] = $this->resolveMonsterArt($monster);

        // Zone-less monsters are either real drift (an orphaned monster whose zone got deleted) or the
        // two tutorial encounters (startled_hare/slime, zone_id=null by design — no real "Tutorial" Zone
        // row exists to travel to) — distinguish the two instead of lumping both under "Unknown zone".
        $zoneLabel = $monster->zone?->name ?? ($monster->is_tutorial ? 'Tutorial' : 'Unknown zone');

        WikiEntry::updateOrCreate(
            ['source_type' => 'monster', 'source_id' => $monster->id],
            [
                'category' => 'monsters',
                'group_label' => $zoneLabel,
                'glyph' => $monster->glyph,
                'image_path' => $image,
                'artist_name' => $artist,
                'name' => $monster->name,
                'sub' => $zoneLabel.' · '.($monster->is_boss ? 'BOSS' : "Lv.{$monster->min_level}"),
                'rarity' => $this->monsterRarity($monster),
                'description' => $monster->name." — a {$roleLabel} encountered in ".($monster->zone?->name ?? ($monster->is_tutorial ? 'the tutorial' : 'the wilds')).'.',
                'stats' => $stats,
                'details' => [
                    'tier_kind' => $gradeTiers ? 'grade' : null,
                    'tiers' => $gradeTiers,
                    'acquisition' => $this->monsterAcquisition($monster),
                    'full_stats' => $fullStats,
                    'found_here' => [],
                ],
                'enabled' => $monster->enabled,
            ]
        );
    }

    public function syncItems(): void
    {
        // Crafted-variant rows (rolled-stat gear, key "{baseKey}_crafted_{random}" — see
        // CleanupStaleData::purgeOrphanedCraftedVariants()) back exactly one player's Inventory row each;
        // they're ephemeral instances, not real catalog content, so the wiki never shows them.
        Item::where('key', 'not like', '%\_crafted\_%')->get()->each(fn (Item $i) => $this->syncItem($i));
    }

    public function syncItem(Item $item): void
    {
        $stats = $this->itemStats($item);
        $tiers = $this->itemSiblingTiers($item);
        $tierKind = null;
        if ($tiers) {
            $tierKind = $this->isSignatureItem($item) ? 'signature' : 'rarity';
        }
        [$image, $artist] = $this->resolveArt('items', $item->id, $item->sprite);

        // Grouped by class first, categorized by type within that (same "{class} · {type}" pattern as
        // syncRecipe()'s group_label), ordered by level — mirrors how nearly every other category sorts.
        $classLabel = $item->class_key ? ucfirst($item->class_key) : 'Any Class';
        $itemGroupLabel = "{$classLabel} · ".Str::headline($item->type);

        WikiEntry::updateOrCreate(
            ['source_type' => 'item', 'source_id' => $item->id],
            [
                'category' => 'items',
                'group_label' => $itemGroupLabel,
                'glyph' => $item->glyph,
                'image_path' => $image,
                'artist_name' => $artist,
                'name' => $item->name,
                'sub' => Str::headline($item->type).' · '.ucfirst($item->rarity),
                'rarity' => ucfirst($item->rarity),
                'description' => $item->description,
                'stats' => $stats,
                'sort_order' => $item->min_level ?? 0,
                'details' => [
                    'tier_kind' => $tierKind,
                    'tiers' => $tiers,
                    'acquisition' => $this->itemAcquisition($item),
                    'full_stats' => $stats,
                    'found_here' => $this->usedIn($item),
                ],
                'enabled' => $item->enabled,
            ]
        );
    }

    /** The other side of itemAcquisition(): every real Recipe that consumes this item as a material
     * (materials_json), so a monster-drop/crate-only material's wiki entry answers "what do I actually
     * craft with this" instead of only ever showing where it comes from. Empty for anything no recipe
     * requires — most equippable gear, since only materials/trophies feed back into RecipeSeeder. */
    private function usedIn(Item $item): array
    {
        return Recipe::query()->where('enabled', true)->get(['id', 'name', 'result_item_id', 'materials_json', 'min_level'])
            ->map(function (Recipe $recipe) use ($item) {
                $entry = collect($recipe->materials_json ?? [])->first(fn ($m) => ($m['item_id'] ?? null) === $item->id);
                if (! $entry) {
                    return null;
                }
                $result = Item::find($recipe->result_item_id);

                return [
                    'glyph' => $result?->glyph ?? '🛠',
                    'name' => $recipe->name,
                    'detail' => "{$entry['qty']}x needed · Lv.{$recipe->min_level}",
                    'link' => ['category' => 'recipes', 'id' => $this->wikiEntryId('recipe', $recipe->id)],
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    public function syncPets(): void
    {
        Pet::all()->each(fn (Pet $p) => $this->syncPet($p));
    }

    public function syncPet(Pet $pet): void
    {
        $rarity = match (true) {
            $pet->unlock_gems >= 300 => 'Epic',
            $pet->unlock_gems >= 200 => 'Rare',
            default => 'Common',
        };

        $stats = collect($pet->bonus_json ?? [])
            ->map(fn ($value, $key) => $this->chip('+'.$value.'% '.(self::PET_STAT_LABELS[$key] ?? $key), '#ff8163'))
            ->values()
            ->all();
        $stats[] = $this->chip('Levels up to '.CharacterPet::BASE_MAX_LEVEL.' per rank, up to Rank '.CharacterPet::MAX_RANK.' (bonus scales with level and rank)', '#cbd5e1', true);

        WikiEntry::updateOrCreate(
            ['source_type' => 'pet', 'source_id' => $pet->id],
            [
                'category' => 'pets',
                'group_label' => $rarity,
                'glyph' => $pet->glyph,
                'name' => $pet->name,
                'sub' => 'Companion · '.$pet->unlock_gems.'◆',
                'rarity' => $rarity,
                'description' => $pet->description,
                'stats' => $stats,
                'details' => [
                    'tier_kind' => 'rank',
                    'tiers' => $this->petRankTiers($pet),
                    'acquisition' => [['glyph' => '💎', 'label' => 'Gem Store', 'detail' => number_format($pet->unlock_gems).'◆', 'kind' => 'shop']],
                    'full_stats' => [...$stats, $this->chip('Unlock cost: '.number_format($pet->unlock_gems).'◆', '#a78bfa', true)],
                    'found_here' => [],
                ],
                'enabled' => $pet->enabled,
            ]
        );
    }

    public function syncZones(): void
    {
        Zone::all()->each(fn (Zone $z) => $this->syncZone($z));
    }

    public function syncZone(Zone $zone): void
    {
        $stats = [$this->chip(ucfirst($zone->danger), $this->dangerColor($zone->danger))];
        $stats[] = $zone->min_level > 1
            ? $this->chip("Requires level {$zone->min_level}", '#a78bfa', true)
            : $this->chip('No level requirement', '#4ade80', true);
        if ($zone->tester_only) {
            $stats[] = $this->chip('Tester only', '#e8482f');
        }
        // Multi-enemy "pack" encounters only ever roll outside safe zones (see CombatService::rollPackMonsters()),
        // with a real group size/chance per danger tier — the exact GameConfig numbers that roll actually
        // reads, not a fabricated "can happen" flag.
        if ($zone->danger !== 'safe') {
            $packMin = (int) GameConfig::number("pack_group_size_min_{$zone->danger}", 2);
            $packMax = (int) GameConfig::number("pack_group_size_max_{$zone->danger}", 2);
            $packChance = (int) GameConfig::number("pack_encounter_chance_pct_{$zone->danger}", 20);
            $packSize = $packMin === $packMax ? "{$packMin} enemies" : "{$packMin}-{$packMax} enemies";
            $stats[] = $this->chip("Pack encounters: {$packSize}, ~{$packChance}% chance", '#ff8163', true);
        }

        [$image, $artist] = $this->resolveArt('zones', $zone->id, $zone->sprite);

        $foundHere = Monster::where('zone_id', $zone->id)->where('enabled', true)->orderBy('min_level')->get()
            ->map(fn (Monster $m) => [
                'glyph' => $m->glyph, 'name' => $m->name, 'detail' => $m->is_boss ? 'BOSS' : "Lv.{$m->min_level}",
                'link' => ['category' => 'monsters', 'id' => $this->wikiEntryId('monster', $m->id)],
            ])
            ->all();

        WikiEntry::updateOrCreate(
            ['source_type' => 'zone', 'source_id' => $zone->id],
            [
                'category' => 'zones',
                'group_label' => ucfirst($zone->danger).' danger',
                'glyph' => $zone->glyph,
                'image_path' => $image,
                'artist_name' => $artist,
                'name' => $zone->name,
                'sub' => 'Danger: '.ucfirst($zone->danger).' · Lv.'.$zone->min_level.'+',
                'rarity' => $this->dangerRarity($zone->danger),
                'description' => $zone->name.' — a region reachable from level '.$zone->min_level.' onward.',
                'stats' => $stats,
                'details' => [
                    'tier_kind' => null,
                    'tiers' => [],
                    'acquisition' => [],
                    'full_stats' => $stats,
                    'found_here' => $foundHere,
                ],
                'enabled' => $zone->enabled,
            ]
        );
    }

    public function syncDungeons(): void
    {
        Dungeon::with('bossMonster')->get()->each(fn (Dungeon $d) => $this->syncDungeon($d));
    }

    public function syncDungeon(Dungeon $dungeon): void
    {
        $dungeon->loadMissing('bossMonster');
        $bossName = $dungeon->bossMonster?->name ?? 'an unknown boss';
        $totalStages = DungeonService::stageCountFor($dungeon->difficulty);
        $addCount = DungeonService::bossAddCountFor($dungeon->difficulty);

        $stats = [
            $this->chip(ucfirst($dungeon->difficulty), $this->difficultyColor($dungeon->difficulty)),
            $this->chip("Lv.{$dungeon->min_level}+ · party of {$dungeon->party_size}", '#cbd5e1', true),
            $this->chip($totalStages > 1 ? "{$totalStages} stages" : '1 stage (boss only)', '#5cc7f5', true),
        ];
        if ($addCount > 0) {
            $stats[] = $this->chip('Boss brings '.$addCount.' add'.($addCount > 1 ? 's' : ''), '#ff8163', true);
        }
        $drops = $dungeon->drops_json ?? [];
        if (! empty($drops['gold'])) {
            $stats[] = $this->chip($drops['gold'].'g', '#eab308', true);
        }
        if (! empty($drops['gems'])) {
            $stats[] = $this->chip($drops['gems'].'◆', '#a78bfa', true);
        }

        [$image, $artist] = $this->resolveArt('dungeons', $dungeon->id, $dungeon->sprite);

        $foundHere = [];
        if ($dungeon->bossMonster) {
            $foundHere[] = [
                'glyph' => $dungeon->bossMonster->glyph,
                'name' => $dungeon->bossMonster->name,
                'detail' => 'BOSS',
                'link' => ['category' => 'monsters', 'id' => $this->wikiEntryId('monster', $dungeon->bossMonster->id)],
            ];
        }
        if ($totalStages > 1) {
            // The real level-range pool DungeonService::pickStageMonster() draws its "trash" encounter
            // from — a genuine possible-encounter list, not a fixed roster (each run picks randomly).
            $trashPool = Monster::where('enabled', true)
                ->where('id', '!=', $dungeon->boss_monster_id)
                ->whereBetween('min_level', [max(1, $dungeon->min_level - 15), $dungeon->min_level + 5])
                ->orderBy('min_level')
                ->get();
            foreach ($trashPool as $m) {
                $foundHere[] = [
                    'glyph' => $m->glyph,
                    'name' => $m->name,
                    'detail' => "Lv.{$m->min_level} · possible stage encounter",
                    'link' => ['category' => 'monsters', 'id' => $this->wikiEntryId('monster', $m->id)],
                ];
            }
        }

        WikiEntry::updateOrCreate(
            ['source_type' => 'dungeon', 'source_id' => $dungeon->id],
            [
                'category' => 'dungeons',
                'group_label' => ucfirst($dungeon->difficulty),
                'glyph' => $dungeon->glyph,
                'image_path' => $image,
                'artist_name' => $artist,
                'name' => $dungeon->name,
                'sub' => ucfirst($dungeon->difficulty).' · '.$dungeon->party_size.($dungeon->party_size > 1 ? '-player party' : ' player'),
                'rarity' => $this->difficultyRarity($dungeon->difficulty),
                'description' => "A {$dungeon->difficulty} dungeon culminating in {$bossName}.",
                'stats' => $stats,
                'details' => [
                    'tier_kind' => null,
                    'tiers' => [],
                    'acquisition' => [],
                    'full_stats' => $stats,
                    'found_here' => $foundHere,
                ],
                'enabled' => $dungeon->enabled,
            ]
        );
    }

    public function syncSkills(): void
    {
        Skill::all()->each(fn (Skill $s) => $this->syncSkill($s));
    }

    public function syncSkill(Skill $skill): void
    {
        // Passive/Active is easy to miss as just one muted chip among several in a 380-entry flat grid —
        // made unmissable here by also leading `sub` (visible on the card without opening the modal) and
        // giving the chip a real, non-muted color per state instead of a decorative gray default.
        $isActive = $skill->mp_cost > 0;
        $stats = [$isActive
            ? $this->chip("⚡ Active · {$skill->mp_cost} MP", '#38bdf8')
            : $this->chip('◆ Passive', '#a78bfa')];
        $stats[] = $this->chip('Max rank '.$skill->max_level, '#eab308', true);

        // SkillService::describe() is the single source of truth for "what does this skill actually do"
        // (damage/heal/passive numbers plus any applies_status buff/debuff, see StatusEffectService::CATALOG)
        // — reused here (and per-rank in skillRankTiers()) instead of re-deriving the same text so the
        // wiki can never drift from real combat math.
        $effect = (new SkillService())->describe($skill, 1);

        [$image, $artist] = $this->resolveArt('skills', $skill->id, $skill->sprite);

        WikiEntry::updateOrCreate(
            ['source_type' => 'skill', 'source_id' => $skill->id],
            [
                'category' => 'skills',
                'group_label' => ucfirst($skill->class_scope).' · '.$skill->branch,
                'glyph' => $skill->glyph,
                'image_path' => $image,
                'artist_name' => $artist,
                'name' => $skill->name,
                'sub' => ($isActive ? '⚡ Active' : '◆ Passive').' · '.ucfirst($skill->class_scope).' · '.$skill->branch.' · Lv.'.$skill->level_req,
                'rarity' => $this->tierRarity($skill->tier),
                'description' => $skill->description.' ('.$effect.')',
                'stats' => $stats,
                'details' => [
                    'tier_kind' => 'rank',
                    'tiers' => $this->skillRankTiers($skill),
                    'acquisition' => [],
                    'full_stats' => $stats,
                    'found_here' => [],
                ],
                'enabled' => true,
            ]
        );
    }

    /** The buff/debuff catalog (StatusEffectService::CATALOG) is a PHP const, not a DB table, so there's
     * no GM-editable row and no per-entry sync method to hang off a GmContentController edit hook — this
     * just rebuilds the whole small catalog every time, called from the same full-resync entry points as
     * every other syncXxx() method (see WikiEntrySeeder). */
    public function syncStatusEffects(): void
    {
        $sourceId = 0;
        foreach (StatusEffectService::CATALOG as $meta) {
            $sourceId++;
            $unitLabel = match ($meta['unit']) {
                'pct' => 'Percent',
                'flat' => 'Flat amount',
                'flag' => 'On/off',
                default => ucfirst($meta['unit']),
            };
            $polarity = $meta['polarity'] ?? 'debuff';
            $stats = [$this->chip(ucfirst($polarity), $this->polarityColor($polarity))];

            WikiEntry::updateOrCreate(
                ['source_type' => 'status_effect', 'source_id' => $sourceId],
                [
                    'category' => 'status_effects',
                    'group_label' => ucfirst($polarity),
                    'glyph' => $meta['glyph'],
                    'name' => $meta['label'],
                    'sub' => $unitLabel.'-based effect',
                    'rarity' => 'Common',
                    'description' => $meta['desc'],
                    'stats' => $stats,
                    'details' => ['tier_kind' => null, 'tiers' => [], 'acquisition' => [], 'full_stats' => $stats, 'found_here' => []],
                    'enabled' => true,
                ]
            );
        }
    }

    public function syncEvents(): void
    {
        Event::all()->each(fn (Event $e) => $this->syncEvent($e));
    }

    public function syncEvent(Event $event): void
    {
        WikiEntry::updateOrCreate(
            ['source_type' => 'event', 'source_id' => $event->id],
            [
                'category' => 'events',
                'group_label' => ucfirst(str_replace('_', ' ', $event->type)),
                'glyph' => match ($event->type) {
                    'world_boss' => '🐉',
                    'bonus_xp' => '⚡',
                    default => '📅',
                },
                'name' => $event->name,
                'sub' => ucfirst(str_replace('_', ' ', $event->type)).' · '.($event->active ? 'Live' : 'Scheduled'),
                'rarity' => $event->active ? 'Legendary' : 'Rare',
                'description' => $event->reward ? "Reward: {$event->reward}." : $event->name,
                'stats' => [$event->active ? $this->chip('LIVE', '#4ade80') : $this->chip('Scheduled', '#eab308', true)],
                'details' => ['tier_kind' => null, 'tiers' => [], 'acquisition' => [], 'full_stats' => [], 'found_here' => []],
                'enabled' => true,
            ]
        );
    }

    /** Base classes (fixed starting stats + a profession at each of Lv.20/40/60, from ClassProgression)
     * — the only wiki category not sourced from a single GM-editable table, since a base class's starting
     * stats live in CharacterController::store() rather than a database row. */
    public function syncClasses(): void
    {
        $sourceId = 0;
        foreach (self::BASE_CLASS_STATS as $baseClass => [$hp, $atk, $mp, $def]) {
            $sourceId++;
            $meta = self::BASE_CLASS_META[$baseClass];
            WikiEntry::updateOrCreate(
                ['source_type' => 'class_base', 'source_id' => $sourceId],
                [
                    'category' => 'classes',
                    'group_label' => ucfirst($baseClass),
                    'glyph' => $meta['glyph'],
                    'name' => ucfirst($baseClass),
                    'sub' => 'Base class · '.$meta['sub'],
                    'rarity' => 'Common',
                    'description' => $meta['desc'],
                    'stats' => [
                        $this->chip("HP {$hp}", '#4ade80'),
                        $this->chip("ATK {$atk}", '#ff8163'),
                        $this->chip("DEF {$def}", '#5cc7f5'),
                        $this->chip("MP {$mp}", '#38bdf8'),
                    ],
                    'details' => ['tier_kind' => null, 'tiers' => [], 'acquisition' => [], 'full_stats' => [
                        $this->chip("HP {$hp}", '#4ade80'),
                        $this->chip("ATK {$atk}", '#ff8163'),
                        $this->chip("DEF {$def}", '#5cc7f5'),
                        $this->chip("MP {$mp}", '#38bdf8'),
                    ], 'found_here' => []],
                    'sort_order' => 0,
                    'enabled' => true,
                ]
            );
        }

        ClassProgression::all()->each(fn (ClassProgression $p) => $this->syncClassProgression($p));
    }

    /** Every branch's real mechanical bonus, straight from Character::BRANCH_BONUSES via its own
     * public branchBonusText() — the exact same numbers Character::branchPassiveBonuses() actually
     * applies in combat, so "what does picking this profession give me" is never a guess. */
    private function syncClassProgression(ClassProgression $progression): void
    {
        $bonusText = Character::branchBonusText($progression->key);
        $stats = $bonusText
            ? collect(explode(', ', $bonusText))->map(fn ($part) => $this->chip($part, '#4ade80', true))->all()
            : [$this->chip('Profession', '#a78bfa', true)];

        WikiEntry::updateOrCreate(
            ['source_type' => 'class_progression', 'source_id' => $progression->id],
            [
                'category' => 'classes',
                'group_label' => ucfirst($progression->base_class),
                'glyph' => $progression->glyph,
                'name' => $progression->name,
                'sub' => ucfirst($progression->base_class).' profession · Lv.'.$progression->level_cap,
                'rarity' => 'Epic',
                'description' => $progression->description,
                'stats' => $stats,
                'sort_order' => $progression->level_cap,
                'details' => ['tier_kind' => null, 'tiers' => [], 'acquisition' => [], 'full_stats' => $stats, 'found_here' => []],
                'enabled' => true,
            ]
        );
    }

    public function syncQuests(): void
    {
        Quest::all()->each(fn (Quest $q) => $this->syncQuest($q));
    }

    public function syncQuest(Quest $quest): void
    {
        $reward = $quest->reward_json ?? [];
        $stats = [];
        if (! empty($reward['gold'])) {
            $stats[] = $this->chip('+'.number_format($reward['gold']).'g', '#eab308', true);
        }
        if (! empty($reward['xp'])) {
            $stats[] = $this->chip("+{$reward['xp']} XP", '#4ade80', true);
        }
        if (! empty($reward['gems'])) {
            $stats[] = $this->chip("+{$reward['gems']}◆", '#a78bfa', true);
        }
        if (! empty($reward['bp_xp'])) {
            $stats[] = $this->chip("+{$reward['bp_xp']} Battle Pass", '#5cc7f5', true);
        }
        if (! empty($reward['item_id'])) {
            $item = Item::find($reward['item_id']);
            if ($item) {
                $stats[] = $this->chip('+'.($reward['item_qty'] ?? 1).'x '.$item->name, '#f472b6', true);
            }
        }

        WikiEntry::updateOrCreate(
            ['source_type' => 'quest', 'source_id' => $quest->id],
            [
                'category' => 'quests',
                'group_label' => ucfirst($quest->type),
                'glyph' => match ($quest->type) {
                    'raid' => '🐉',
                    'monthly' => '📅',
                    'weekly' => '🗓',
                    'daily' => '📆',
                    default => '🎯',
                },
                'name' => $quest->name,
                'sub' => ucfirst($quest->type).($quest->class_key ? ' · '.ucfirst($quest->class_key) : ''),
                'rarity' => match ($quest->type) {
                    'raid' => 'Legendary',
                    'monthly' => 'Epic',
                    'weekly' => 'Rare',
                    default => 'Common',
                },
                'description' => $quest->description,
                'stats' => $stats ?: [$this->chip('No reward configured', '#cbd5e1', true)],
                'details' => ['tier_kind' => null, 'tiers' => [], 'acquisition' => [], 'full_stats' => $stats, 'found_here' => []],
                'enabled' => $quest->enabled,
            ]
        );
    }

    public function syncRecipes(): void
    {
        Recipe::with('resultItem')->get()->each(fn (Recipe $r) => $this->syncRecipe($r));
    }

    public function syncRecipe(Recipe $recipe): void
    {
        $recipe->loadMissing('resultItem');
        $result = $recipe->resultItem;

        $materials = collect($recipe->materials_json ?? [])
            ->map(function ($m) {
                $item = Item::find($m['item_id'] ?? null);

                return $item ? $this->chip("{$m['qty']}x {$item->name}", '#cbd5e1', true) : null;
            })
            ->filter()
            ->values()
            ->all();

        if ($recipe->gold_cost > 0) {
            $materials[] = $this->chip(number_format($recipe->gold_cost).'g', '#eab308', true);
        }
        $materials[] = $this->chip(round($recipe->craft_seconds / 60, 1).' min craft time', '#a78bfa', true);

        // Where to actually get each material — the same real facts an item's own acquisition() shows,
        // so a recipe explains why it needs a specific kill/crate/shop trip, not just "3x Iron Ore".
        $materialSources = collect($recipe->materials_json ?? [])
            ->map(function ($m) {
                $item = Item::find($m['item_id'] ?? null);

                return $item ? ['label' => "{$m['qty']}x {$item->name}", 'value' => $this->materialSourceLabel($item)] : null;
            })
            ->filter()
            ->values()
            ->all();

        $recipeGroupLabel = 'Misc';
        if ($result) {
            $classLabel = $result->class_key ? ucfirst($result->class_key) : 'Any Class';
            $recipeGroupLabel = "{$classLabel} · ".Str::headline($result->type);
        }

        WikiEntry::updateOrCreate(
            ['source_type' => 'recipe', 'source_id' => $recipe->id],
            [
                'category' => 'recipes',
                'group_label' => $recipeGroupLabel,
                'glyph' => $result?->glyph ?? '🛠',
                'name' => $recipe->name,
                'sub' => 'Lv.'.$recipe->min_level.($result ? ' · Produces '.$result->name : ''),
                'rarity' => $result ? ucfirst($result->rarity) : 'Common',
                'description' => 'Crafts '.($recipe->result_qty ?? 1).'x '.($result?->name ?? 'an item').'.',
                'stats' => $materials,
                'sort_order' => $recipe->min_level,
                'details' => ['tier_kind' => null, 'tiers' => [], 'acquisition' => [], 'full_stats' => array_merge($materials, $materialSources), 'found_here' => []],
                'enabled' => $recipe->enabled,
            ]
        );
    }

    public function syncCosmetics(): void
    {
        Cosmetic::all()->each(fn (Cosmetic $c) => $this->syncCosmetic($c));
    }

    public function syncCosmetic(Cosmetic $cosmetic): void
    {
        $acquisition = [];
        if ($cosmetic->cost_gems > 0) {
            $acquisition[] = $this->chip("{$cosmetic->cost_gems}◆ Gem Store", '#a78bfa', true);
        }
        if ($cosmetic->unlock_quest_key) {
            // Real quest name (CosmeticController::index() already resolves this the same way) instead
            // of a generic "Quest reward" chip that doesn't say which quest.
            $questName = Quest::where('key', $cosmetic->unlock_quest_key)->value('name');
            $acquisition[] = $this->chip($questName ? "Quest: {$questName}" : 'Quest reward', '#4ade80', true);
        }
        if ($cosmetic->unlock_event) {
            // Reuses CosmeticController::eventLabel() (the same human-sentence parser the Customize page
            // already uses) instead of a raw slug like "Event: pvp_season_1_platinum".
            $eventLabel = CosmeticController::eventLabel($cosmetic->unlock_event) ?? ucfirst(str_replace('_', ' ', $cosmetic->unlock_event));
            $acquisition[] = $this->chip($eventLabel, '#eab308', true);
        }
        if (! $acquisition) {
            $acquisition[] = $this->chip('Special unlock', '#cbd5e1', true);
        }

        WikiEntry::updateOrCreate(
            ['source_type' => 'cosmetic', 'source_id' => $cosmetic->id],
            [
                'category' => 'cosmetics',
                'group_label' => Str::headline($cosmetic->category ?: $cosmetic->type),
                'glyph' => $cosmetic->icon ?? '🎨',
                'name' => $cosmetic->name,
                'sub' => ucfirst($cosmetic->type),
                'rarity' => ucfirst($cosmetic->rarity),
                'description' => $cosmetic->name.' — a '.$cosmetic->rarity.' cosmetic.',
                'stats' => $acquisition,
                'details' => ['tier_kind' => null, 'tiers' => [], 'acquisition' => $acquisition, 'full_stats' => $acquisition, 'found_here' => []],
                'enabled' => $cosmetic->enabled,
            ]
        );
    }

    public function removeSource(string $type, int $id): void
    {
        WikiEntry::where('source_type', $type)->where('source_id', $id)->delete();
    }

    /** Deletes any wiki entry whose live source row is gone — catches drift from any edit path that
     * bypasses GmContentController (a direct seeder run, tinker, raw SQL), since that's the only place
     * that currently calls removeSource(). Categories with no backing model (classes, status effects)
     * are fully rebuilt by their own syncXxx() every time instead, so they're not included here. */
    public function pruneOrphans(): void
    {
        $modelBySourceType = [
            'monster' => Monster::class,
            'item' => Item::class,
            'pet' => Pet::class,
            'zone' => Zone::class,
            'dungeon' => Dungeon::class,
            'skill' => Skill::class,
            'event' => Event::class,
            'quest' => Quest::class,
            'recipe' => Recipe::class,
            'cosmetic' => Cosmetic::class,
        ];

        foreach ($modelBySourceType as $sourceType => $modelClass) {
            WikiEntry::where('source_type', $sourceType)->whereNotIn('source_id', $modelClass::pluck('id'))->delete();
        }
    }

    private function dangerColor(string $danger): string
    {
        return match ($danger) {
            'safe' => '#4ade80',
            'medium' => '#eab308',
            'high' => '#ff8163',
            default => '#a78bfa',
        };
    }

    private function dangerRarity(string $danger): string
    {
        return match ($danger) {
            'safe' => 'Common',
            'medium' => 'Rare',
            'high' => 'Epic',
            default => 'Legendary',
        };
    }

    private function difficultyColor(string $difficulty): string
    {
        return match ($difficulty) {
            'normal' => '#4ade80',
            'hard' => '#eab308',
            'raid' => '#ff8163',
            default => '#a78bfa',
        };
    }

    private function difficultyRarity(string $difficulty): string
    {
        return match ($difficulty) {
            'normal' => 'Rare',
            'hard' => 'Epic',
            'raid' => 'Legendary',
            default => 'Mythic',
        };
    }

    private function tierRarity(int $tier): string
    {
        return match ($tier) {
            1 => 'Common',
            2 => 'Rare',
            default => 'Epic',
        };
    }

    private function rarityColor(string $rarity): string
    {
        return match ($rarity) {
            'common' => '#cbd5e1',
            'rare' => '#5cc7f5',
            'epic' => '#a78bfa',
            'legendary' => '#eab308',
            'mythic' => '#f472b6',
            default => '#cbd5e1',
        };
    }

    private function itemStats(Item $item): array
    {
        $statJson = $item->stat_json ?? [];
        if (empty($statJson)) {
            return [$this->chip(Str::headline($item->type), '#cbd5e1', true)];
        }

        $chips = [];

        if (isset($statJson['heal_hp_flat'])) {
            $chips[] = $this->chip("Restore {$statJson['heal_hp_flat']} HP", '#4ade80', true);
        }
        if (isset($statJson['heal_mp_flat'])) {
            $chips[] = $this->chip("Restore {$statJson['heal_mp_flat']} MP", '#38bdf8', true);
        }
        if (isset($statJson['heal_hp_pct'])) {
            $chips[] = $this->chip("Restore {$statJson['heal_hp_pct']}% HP", '#4ade80', true);
        }
        if (isset($statJson['heal_mp_pct'])) {
            $chips[] = $this->chip("Restore {$statJson['heal_mp_pct']}% MP", '#38bdf8', true);
        }
        if (isset($statJson['atk_pct_buff'])) {
            $fights = $statJson['buff_fights'] ?? null;
            $chips[] = $this->chip("+{$statJson['atk_pct_buff']}% ATK".($fights ? " {$fights} fights" : ''), '#eab308');
        }

        foreach (self::ITEM_STAT_META as $key => $meta) {
            if (! isset($statJson[$key])) {
                continue;
            }
            $suffix = ! empty($meta['pct']) ? '%' : '';
            $chips[] = $this->chip("+{$statJson[$key]}{$suffix} {$meta['label']}", $meta['color']);
        }

        if ($item->set_key) {
            $set = ItemSet::where('key', $item->set_key)->first();
            if ($set) {
                $chips[] = $this->chip("Set: {$set->name}", '#f472b6', true);
            }
        }

        return $chips ?: [$this->chip(Str::headline($item->type), '#cbd5e1', true)];
    }

    /** ItemSeeder::specialtyGearItems() keys every "signature gear" item "{branch}_signature_weapon" or
     * "{branch}_signature_armor" — a separate per-class-per-level-checkpoint progression (Berserker's
     * Blade, Godslayer's Blade, ...) that isn't a rarity reskin of the normal gear ladder, so it needs
     * its own tier picker instead of being mixed into one confusing "Rarity Line" with unrelated items. */
    private function isSignatureItem(Item $item): bool
    {
        return str_contains($item->key, '_signature_');
    }

    /** Every other item sharing this item's (type, class_key) — the natural tier ladder every gear line
     * follows. A signature item's siblings are the OTHER signature items of the same (type, class_key),
     * sorted by level checkpoint (its own separate progression); a normal item's siblings exclude
     * signature items entirely, sorted by rarity as before — the two ladders never mix. Each sibling's
     * own full stat block is precomputed here (not a client-side multiply) since gear is hand-tuned per
     * tier, not linear. */
    private function itemSiblingTiers(Item $item): array
    {
        $isSignature = $this->isSignatureItem($item);

        $query = Item::where('type', $item->type)
            ->when($item->class_key, fn ($q) => $q->where('class_key', $item->class_key), fn ($q) => $q->whereNull('class_key'));

        if ($isSignature) {
            $siblings = $query->where('key', 'like', '%\_signature\_%')
                ->get()
                ->sortBy('min_level')
                ->values();
        } else {
            $siblings = $query->where('key', 'not like', '%\_crafted\_%')
                ->where('key', 'not like', '%\_signature\_%')
                ->get()
                ->sortBy(fn (Item $i) => array_search($i->rarity, ['common', 'rare', 'epic', 'legendary', 'mythic']))
                ->values();
        }

        if ($siblings->count() < 2) {
            return [];
        }

        return $siblings->map(fn (Item $sibling) => [
            'key' => $isSignature ? (string) $sibling->min_level : $sibling->rarity,
            'label' => $isSignature ? "Lv.{$sibling->min_level}" : ucfirst($sibling->rarity),
            'color' => $this->rarityColor($sibling->rarity),
            'detail' => $sibling->id === $item->id ? 'Current item' : $sibling->name,
            'full_stats' => $this->itemStats($sibling),
        ])->all();
    }

    /** Real, honest acquisition info — shop price, craftable-via-recipe, the specific monster(s) that
     * drop it (see MonsterSeeder's loot_table_json), or (for a lootbox item itself) the real reward
     * table it rolls from. Deliberately never invents a per-item drop rate that doesn't exist in code. */
    private function itemAcquisition(Item $item): array
    {
        if ($item->type === 'loot_crate') {
            return $this->crateAcquisition($item);
        }

        $acquisition = [];
        if ($item->price_gold > 0) {
            $acquisition[] = ['glyph' => '💰', 'label' => 'Shop', 'detail' => number_format($item->price_gold).'g', 'kind' => 'shop'];
        }
        if ($item->price_gems > 0) {
            $acquisition[] = ['glyph' => '💎', 'label' => 'Gem Store', 'detail' => number_format($item->price_gems).'◆', 'kind' => 'shop'];
        }

        $recipe = Recipe::where('result_item_id', $item->id)->first();
        if ($recipe) {
            $materials = collect($recipe->materials_json ?? [])
                ->map(fn ($m) => ($mat = Item::find($m['item_id'] ?? null)) ? "{$m['qty']}x {$mat->name}" : null)
                ->filter()
                ->implode(', ');
            $acquisition[] = ['glyph' => '🛠', 'label' => 'Craftable', 'detail' => $materials ?: 'See Recipes', 'kind' => 'craft', 'link' => ['category' => 'recipes', 'id' => $this->wikiEntryId('recipe', $recipe->id)]];
        }

        if (! $acquisition) {
            $droppers = Monster::query()->get(['id', 'name', 'glyph', 'loot_table_json'])
                ->map(function (Monster $m) use ($item) {
                    $entry = collect($m->loot_table_json ?? [])->first(fn ($e) => ($e['item_id'] ?? null) === $item->id);

                    return $entry ? [$m, $entry] : null;
                })
                ->filter();

            foreach ($droppers as [$monster, $entry]) {
                $acquisition[] = ['glyph' => $monster->glyph, 'label' => "Dropped by {$monster->name}", 'detail' => "{$entry['chance_pct']}% chance on win", 'kind' => 'monster', 'link' => ['category' => 'monsters', 'id' => $this->wikiEntryId('monster', $monster->id)]];
            }
        }

        if (! $acquisition) {
            $acquisition[] = ['glyph' => '📦', 'label' => 'Crate-only', 'detail' => 'Only obtainable from Loot Crates', 'kind' => 'crate'];
        }

        return $acquisition;
    }

    /** The real CrateService::REWARD_TABLE row for this crate's rarity — gold/gem ranges and the
     * chance a material/pet-food/reforge-catalyst rolls at all (never a fabricated per-item %, since
     * the actual material picked is uniform-random within a zone-danger-dependent rarity — see
     * CrateService::ZONE_MATERIAL_TABLE). */
    private function crateAcquisition(Item $item): array
    {
        $rarity = str_replace('_lootbox', '', $item->key);
        $table = CrateService::rewardTableFor($rarity);
        if (! $table) {
            return [];
        }

        $acquisition = [
            ['glyph' => '💰', 'label' => 'Gold', 'detail' => number_format($table['gold'][0]).'–'.number_format($table['gold'][1]), 'kind' => 'crate'],
        ];
        if (isset($table['gems'])) {
            $acquisition[] = ['glyph' => '💎', 'label' => 'Gems', 'detail' => number_format($table['gems'][0]).'–'.number_format($table['gems'][1]), 'kind' => 'crate'];
        }
        $acquisition[] = ['glyph' => '🪨', 'label' => 'Material', 'detail' => "{$table['material_chance']}% chance — rarity depends on your zone's danger when you open it", 'kind' => 'crate'];
        $acquisition[] = ['glyph' => '🍖', 'label' => 'Pet Food', 'detail' => "{$table['pet_food_chance']}% chance", 'kind' => 'crate'];
        if (($table['reforge_material_chance'] ?? 0) > 0) {
            $acquisition[] = ['glyph' => '⚗', 'label' => 'Reforging Catalyst', 'detail' => "{$table['reforge_material_chance']}% chance", 'kind' => 'crate'];
        }

        return $acquisition;
    }

    /** Honest per-monster loot: the real lootbox-tier chance (trash/elite/boss, see
     * CombatService::rollLootboxRarity()), the real pet-food chance (zone-danger based, see
     * maybeDropPetFood()), and any real named drops from loot_table_json — never a fabricated
     * "Item X: Y%" line for a mechanic that doesn't exist. */
    private function monsterAcquisition(Monster $monster): array
    {
        $tierLabel = match (true) {
            $monster->is_boss => ['pct' => GameConfig::number('loot_crate_drop_chance_boss_pct', 100), 'floor' => 'Epic'],
            $monster->is_elite => ['pct' => GameConfig::number('loot_crate_drop_chance_elite_pct', 20), 'floor' => 'Rare'],
            default => ['pct' => GameConfig::number('loot_crate_drop_chance_trash_pct', 8), 'floor' => 'Common'],
        };

        $acquisition = [
            ['glyph' => '📦', 'label' => 'Loot Crate', 'detail' => "{$tierLabel['pct']}% chance of a {$tierLabel['floor']}+ crate", 'kind' => 'combat'],
            ['glyph' => '🍖', 'label' => 'Pet Food', 'detail' => GameConfig::number('pet_food_drop_chance_pct', 15).'%+ chance (higher in more dangerous zones)', 'kind' => 'combat'],
        ];

        foreach ($monster->loot_table_json ?? [] as $entry) {
            $item = Item::find($entry['item_id'] ?? null);
            if ($item) {
                $acquisition[] = ['glyph' => $item->glyph, 'label' => $item->name, 'detail' => "{$entry['chance_pct']}% chance on win", 'kind' => 'combat', 'link' => ['category' => 'items', 'id' => $this->wikiEntryId('item', $item->id)]];
            }
        }

        return $acquisition;
    }

    /** GradeService's 4 real tiers, with this monster's actual stats/rewards scaled by each tier's real
     * multiplier — no fabrication, just the same numbers CombatService rolls at encounter time. */
    private function monsterGradeTiers(Monster $monster): array
    {
        if ($monster->is_boss) {
            return [];
        }

        $tiers = [];
        foreach ($this->grades->all() as $key => $meta) {
            $tiers[] = [
                'key' => $key,
                'label' => $meta['label'],
                'color' => $meta['color'],
                'detail' => 'HP '.round($monster->hp * $meta['hp_mult']).' · ATK '.round($monster->atk * $meta['atk_mult']).' · '.round($monster->gold * $meta['reward_mult']).'g · '.round($monster->xp * $meta['reward_mult']).'xp',
            ];
        }

        return $tiers;
    }

    /** Named abilities actually used in battle (see MonsterAiService), skipping the generic basic attack
     * every monster has — this is the real "moveset" a player will see mid-fight. */
    private function monsterAbilityStats(Monster $monster): array
    {
        return collect($monster->skills_json ?? [])
            ->filter(fn ($a) => ($a['key'] ?? null) !== 'basic_attack' && ! empty($a['name']))
            ->map(fn ($a) => ['label' => $a['name'], 'value' => $this->abilityValueLabel($a)])
            ->values()
            ->all();
    }

    private function abilityValueLabel(array $ability): string
    {
        if (($ability['type'] ?? null) === 'regen') {
            return 'Heals '.($ability['heal_pct'] ?? 0).'% · every '.($ability['cooldown'] ?? 0).' rounds';
        }

        $hits = $ability['hits'] ?? 1;
        $mult = $ability['dmg_mult'] ?? 1;

        return ($hits > 1 ? "{$hits}x " : '').round($mult * 100).'% dmg · every '.($ability['cooldown'] ?? 0).' rounds';
    }

    /** Every rank 1..max_level, using the real SkillService::describe() text per rank — never a
     * fabricated "+N% per rank" line, since not every skill scales linearly. */
    private function skillRankTiers(Skill $skill): array
    {
        if ($skill->max_level <= 1) {
            return [];
        }

        $service = new SkillService();
        $tiers = [];
        for ($level = 1; $level <= $skill->max_level; $level++) {
            $tiers[] = [
                'key' => (string) $level,
                'label' => "Rank {$level}",
                'color' => $level === $skill->max_level ? '#eab308' : '#5cc7f5',
                'detail' => $service->describe($skill, $level),
            ];
        }

        return $tiers;
    }

    /** Representative rank checkpoints using CharacterPet's real rankMultiplier() formula (an unsaved
     * instance with just `rank` set — the method only reads that column) so this can never drift from
     * the real per-rank scaling even though MAX_RANK (50) is too many to list one-by-one. */
    private function petRankTiers(Pet $pet): array
    {
        $tiers = [];
        foreach (self::PET_RANK_CHECKPOINTS as $rank) {
            $mult = (new CharacterPet(['rank' => $rank]))->rankMultiplier();
            $detail = collect($pet->bonus_json ?? [])
                ->map(fn ($value, $key) => '+'.round($value * $mult, 1).'% '.(self::PET_STAT_LABELS[$key] ?? $key))
                ->implode(', ');

            $tiers[] = [
                'key' => (string) $rank,
                'label' => $rank === 0 ? 'Rank 0 (base)' : "Rank {$rank}",
                'color' => $rank >= CharacterPet::MAX_RANK ? '#eab308' : ($rank >= 25 ? '#a78bfa' : '#cbd5e1'),
                'detail' => $detail,
            ];
        }

        return $tiers;
    }

    private function monsterRarity(Monster $monster): string
    {
        return match (true) {
            $monster->is_boss && $monster->min_level >= 60 => 'Mythic',
            $monster->is_boss => 'Legendary',
            $monster->min_level >= 45 => 'Epic',
            $monster->min_level >= 30 => 'Rare',
            default => 'Common',
        };
    }

    /** Best-available art grade for a monster (see MONSTER_ART_GRADES) — a monster can have partial
     * coverage across its 4 grade slots, so this shows whichever finished one ranks highest. */
    private function resolveMonsterArt(Monster $monster): array
    {
        if (! $monster->sprite) {
            return [null, null];
        }

        foreach (self::MONSTER_ART_GRADES as $grade) {
            [$image, $artist] = $this->resolveArt('monsters', $monster->id, $monster->sprite, $grade);
            if ($image) {
                return [$image, $artist];
            }
        }

        return [null, null];
    }

    /** [image_url|null, artist_name|null] for a live, approved piece of art — same file-existence check
     * GmArtController::gradeStatus()/singleStatus() already use as the single source of truth for "is
     * this entity's art actually done," plus the submitter of whichever APPROVED ArtAsset most recently
     * filled that exact (entity_type, entity_id, grade) slot. */
    private function resolveArt(string $type, int $entityId, ?string $sprite, ?string $grade = null): array
    {
        if (! $sprite) {
            return [null, null];
        }

        $suffix = $grade ? "_{$grade}" : '';
        $relative = "{$type}/{$sprite}{$suffix}.png";
        if (! file_exists(public_path("images/{$relative}"))) {
            return [null, null];
        }

        $artistName = ArtAsset::where('entity_type', $type)
            ->where('entity_id', $entityId)
            ->where('grade', $grade)
            ->where('status', 'approved')
            ->orderByDesc('id')
            ->with('submitter')
            ->first()
            ?->submitter?->name;

        return [asset("images/{$relative}"), $artistName];
    }

    private function chip(string $text, string $color, bool $muted = false): array
    {
        return ['t' => $text, 'color' => $color, 'muted' => $muted];
    }

    /** Wiki entry id for a live source row, or null if it hasn't been synced yet (e.g. brand-new
     * content in the same pass as its cross-linked counterpart) — the frontend just renders the row as
     * plain text when null, never a broken link. Self-heals on the next full reseed either way. */
    private function wikiEntryId(string $sourceType, int $sourceId): ?int
    {
        return WikiEntry::where('source_type', $sourceType)->where('source_id', $sourceId)->value('id');
    }

    private function polarityColor(string $polarity): string
    {
        return match ($polarity) {
            'buff' => '#4ade80',
            'debuff' => '#e8482f',
            default => '#a78bfa',
        };
    }

    /** Short "where do I actually get this" label for one recipe material — the same real facts
     * itemAcquisition() already surfaces on the item's own page, condensed to its first (most relevant)
     * source so a recipe's material list doesn't need a second trip to each item's own wiki entry. */
    private function materialSourceLabel(Item $item): string
    {
        $acquisition = $this->itemAcquisition($item);

        return $acquisition ? "{$acquisition[0]['label']} · {$acquisition[0]['detail']}" : 'Unknown';
    }
}
