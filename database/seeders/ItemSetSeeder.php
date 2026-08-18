<?php

namespace Database\Seeders;

use App\Models\ItemSet;
use Illuminate\Database\Seeder;

class ItemSetSeeder extends Seeder
{
    /** One Legendary-tier (level 35) set per class, tagged onto the existing $gearFor items in
     * ItemSeeder via `set_key` — a real reason to wear the matching weapon/armor/shield-or-quiver
     * together instead of mixing tiers, on top of each piece's own stats. Warrior/Rogue/Ranger only
     * have 3 set-taggable slots each (weapon, armor, shield-or-quiver — their trinket is the universal
     * Charm, shared across all 3 classes, so it can't belong to any one class's set); Mage's dedicated
     * Focus trinket line IS class-exclusive, so Mage gets a real 4th piece (its 2 trinket slots both
     * holding a Gilded Focus). Bonuses are deliberately modest — a nice-to-have for going all-in on one
     * tier, not a mandatory min-max requirement. */
    public function run(): void
    {
        $sets = [
            [
                'key' => 'warrior_vanguard',
                'name' => "Vanguard's Legendary Set",
                'class_key' => 'warrior',
                'bonus_2pc_json' => ['atk_pct' => 4],
                'bonus_3pc_json' => ['atk_pct' => 8, 'def_pct' => 6],
            ],
            [
                'key' => 'rogue_shadowblade',
                'name' => 'Shadowblade Legendary Set',
                'class_key' => 'rogue',
                'bonus_2pc_json' => ['atk_pct' => 5],
                'bonus_3pc_json' => ['atk_pct' => 10, 'crit_chance_flat' => 4],
            ],
            [
                'key' => 'ranger_hunter',
                'name' => "Hunter's Legendary Set",
                'class_key' => 'ranger',
                'bonus_2pc_json' => ['atk_pct' => 5],
                'bonus_3pc_json' => ['atk_pct' => 10, 'crit_chance_flat' => 4],
            ],
            [
                'key' => 'mage_archmagi',
                'name' => "Archmagi's Legendary Set",
                'class_key' => 'mage',
                'bonus_2pc_json' => ['atk_pct' => 5],
                'bonus_4pc_json' => ['atk_pct' => 12, 'def_pct' => 6],
            ],
        ];

        foreach ($sets as $set) {
            ItemSet::updateOrCreate(['key' => $set['key']], $set);
        }
    }
}
