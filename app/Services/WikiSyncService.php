<?php

namespace App\Services;

use App\Models\CharacterPet;
use App\Models\ClassProgression;
use App\Models\Dungeon;
use App\Models\Event;
use App\Models\Item;
use App\Models\Monster;
use App\Models\Pet;
use App\Models\Skill;
use App\Models\WikiEntry;
use App\Models\Zone;

/**
 * Keeps every wiki category derived directly from live game data, instead of the hand-typed
 * snapshot the wiki started from — so a GM edit in the content editor (or a seeder rebalance)
 * can never leave the wiki showing stale zones/dungeons/skills/stats.
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
        'luck' => ['label' => 'Luck', 'color' => '#4ade80'],
        'lifesteal_pct' => ['label' => 'lifesteal', 'color' => '#a78bfa', 'pct' => true],
        'dodge_pct' => ['label' => 'dodge', 'color' => '#4ade80', 'pct' => true],
    ];

    private const PET_STAT_LABELS = [
        'atk_pct' => 'ATK',
        'def_pct' => 'DEF',
        'crit_pct' => 'Crit',
        'xp_pct' => 'XP',
    ];

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
            $stats[] = $this->chip('Stats scale with rolled Grade (Common → Legendary)', '#cbd5e1', true);
        }

        WikiEntry::updateOrCreate(
            ['source_type' => 'monster', 'source_id' => $monster->id],
            [
                'category' => 'monsters',
                'group_label' => $monster->zone?->name ?? 'Unknown zone',
                'glyph' => $monster->glyph,
                'name' => $monster->name,
                'sub' => ($monster->zone?->name ?? 'Unknown zone').' · '.($monster->is_boss ? 'BOSS' : "Lv.{$monster->min_level}"),
                'rarity' => $this->monsterRarity($monster),
                'description' => $monster->name.' — a monster encountered in '.($monster->zone?->name ?? 'the wilds').'.',
                'stats' => $stats,
                'enabled' => $monster->enabled,
            ]
        );
    }

    public function syncItems(): void
    {
        Item::all()->each(fn (Item $i) => $this->syncItem($i));
    }

    public function syncItem(Item $item): void
    {
        WikiEntry::updateOrCreate(
            ['source_type' => 'item', 'source_id' => $item->id],
            [
                'category' => 'items',
                'group_label' => $item->class_key ? ucfirst($item->class_key) : 'Any Class',
                'glyph' => $item->glyph,
                'name' => $item->name,
                'sub' => ucfirst($item->type).' · '.ucfirst($item->rarity),
                'rarity' => ucfirst($item->rarity),
                'description' => $item->description,
                'stats' => $this->itemStats($item),
                'enabled' => $item->enabled,
            ]
        );
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
        $stats[] = $this->chip('Levels up to '.CharacterPet::MAX_LEVEL.' (bonus scales with level)', '#cbd5e1', true);

        WikiEntry::updateOrCreate(
            ['source_type' => 'pet', 'source_id' => $pet->id],
            [
                'category' => 'pets',
                'glyph' => $pet->glyph,
                'name' => $pet->name,
                'sub' => 'Companion · '.$pet->unlock_gems.'◆',
                'rarity' => $rarity,
                'description' => $pet->description,
                'stats' => $stats,
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

        WikiEntry::updateOrCreate(
            ['source_type' => 'zone', 'source_id' => $zone->id],
            [
                'category' => 'zones',
                'glyph' => $zone->glyph,
                'name' => $zone->name,
                'sub' => 'Danger: '.ucfirst($zone->danger).' · Lv.'.$zone->min_level.'+',
                'rarity' => $this->dangerRarity($zone->danger),
                'description' => $zone->name.' — a region reachable from level '.$zone->min_level.' onward.',
                'stats' => $stats,
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

        $stats = [
            $this->chip(ucfirst($dungeon->difficulty), $this->difficultyColor($dungeon->difficulty)),
            $this->chip("Lv.{$dungeon->min_level}+ · party of {$dungeon->party_size}", '#cbd5e1', true),
        ];
        $drops = $dungeon->drops_json ?? [];
        if (! empty($drops['gold'])) {
            $stats[] = $this->chip($drops['gold'].'g', '#eab308', true);
        }
        if (! empty($drops['gems'])) {
            $stats[] = $this->chip($drops['gems'].'◆', '#a78bfa', true);
        }

        WikiEntry::updateOrCreate(
            ['source_type' => 'dungeon', 'source_id' => $dungeon->id],
            [
                'category' => 'dungeons',
                'glyph' => $dungeon->glyph,
                'name' => $dungeon->name,
                'sub' => ucfirst($dungeon->difficulty).' · '.$dungeon->party_size.($dungeon->party_size > 1 ? '-player party' : ' player'),
                'rarity' => $this->difficultyRarity($dungeon->difficulty),
                'description' => "A {$dungeon->difficulty} dungeon culminating in {$bossName}.",
                'stats' => $stats,
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
        $stats = [$skill->mp_cost > 0
            ? $this->chip("{$skill->mp_cost} MP", '#38bdf8')
            : $this->chip('Passive', '#cbd5e1', true)];
        $stats[] = $this->chip('Max rank '.$skill->max_level, '#eab308', true);

        WikiEntry::updateOrCreate(
            ['source_type' => 'skill', 'source_id' => $skill->id],
            [
                'category' => 'skills',
                'glyph' => $skill->glyph,
                'name' => $skill->name,
                'sub' => ucfirst($skill->class_scope).' · '.$skill->branch.' · Lv.'.$skill->level_req,
                'rarity' => $this->tierRarity($skill->tier),
                'description' => $skill->description,
                'stats' => $stats,
                'enabled' => true,
            ]
        );
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
                    'enabled' => true,
                ]
            );
        }

        ClassProgression::all()->each(fn (ClassProgression $p) => $this->syncClassProgression($p));
    }

    private function syncClassProgression(ClassProgression $progression): void
    {
        WikiEntry::updateOrCreate(
            ['source_type' => 'class_progression', 'source_id' => $progression->id],
            [
                'category' => 'classes',
                'glyph' => $progression->glyph,
                'name' => $progression->name,
                'sub' => ucfirst($progression->base_class).' profession · Lv.'.$progression->level_cap,
                'rarity' => 'Epic',
                'description' => $progression->description,
                'stats' => [$this->chip('Profession', '#a78bfa', true)],
                'enabled' => true,
            ]
        );
    }

    public function removeSource(string $type, int $id): void
    {
        WikiEntry::where('source_type', $type)->where('source_id', $id)->delete();
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

    private function itemStats(Item $item): array
    {
        $statJson = $item->stat_json ?? [];
        if (empty($statJson)) {
            return [$this->chip(ucfirst($item->type), '#cbd5e1', true)];
        }

        $chips = [];

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

        return $chips ?: [$this->chip(ucfirst($item->type), '#cbd5e1', true)];
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

    private function chip(string $text, string $color, bool $muted = false): array
    {
        return ['t' => $text, 'color' => $color, 'muted' => $muted];
    }
}
