<?php

namespace App\Services;

use App\Models\CharacterPet;
use App\Models\Item;
use App\Models\Monster;
use App\Models\Pet;
use App\Models\WikiEntry;

/**
 * Keeps the 'monsters', 'items', and 'pets' wiki categories derived directly from live game
 * data, instead of the hand-typed snapshot the wiki started from — so a GM edit in the content
 * editor can never leave the wiki showing stale stats.
 */
class WikiSyncService
{
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

    public function removeSource(string $type, int $id): void
    {
        WikiEntry::where('source_type', $type)->where('source_id', $id)->delete();
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
