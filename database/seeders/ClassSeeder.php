<?php

namespace Database\Seeders;

use App\Models\ClassProgression;
use Illuminate\Database\Seeder;

class ClassSeeder extends Seeder
{
    public function run(): void
    {
        $progressions = [
            ['base_class' => 'warrior', 'tier' => 't20', 'key' => 'berserker', 'name' => 'Berserker', 'glyph' => '🪓', 'description' => 'Trades defense for relentless offense.', 'level_cap' => 20],
            ['base_class' => 'warrior', 'tier' => 't20', 'key' => 'guardian', 'name' => 'Guardian', 'glyph' => '🛡', 'description' => 'A wall between allies and danger.', 'level_cap' => 20],
            ['base_class' => 'warrior', 'tier' => 't40', 'key' => 'warlord', 'name' => 'Warlord', 'glyph' => '👑', 'description' => 'A battlefield commander that buffs allies and cleaves foes.', 'level_cap' => 40],
            ['base_class' => 'warrior', 'tier' => 't60', 'key' => 'titan', 'name' => 'Titan', 'glyph' => '⛰', 'description' => 'Ascended warrior, immovable and unstoppable.', 'level_cap' => 60],

            ['base_class' => 'mage', 'tier' => 't20', 'key' => 'shadowmage', 'name' => 'Shadow Mage', 'glyph' => '🌑', 'description' => 'Bends dark energy into devastating bursts.', 'level_cap' => 20],
            ['base_class' => 'mage', 'tier' => 't20', 'key' => 'elementalist', 'name' => 'Elementalist', 'glyph' => '🔥', 'description' => 'Masters fire, ice and lightning.', 'level_cap' => 20],
            ['base_class' => 'mage', 'tier' => 't40', 'key' => 'necromancer', 'name' => 'Necromancer', 'glyph' => '💀', 'description' => 'A Mage profession that raises undead minions and drains life.', 'level_cap' => 40],
            ['base_class' => 'mage', 'tier' => 't60', 'key' => 'archmage', 'name' => 'Archmage', 'glyph' => '✨', 'description' => 'Ascended mage wielding reality-bending power.', 'level_cap' => 60],

            ['base_class' => 'rogue', 'tier' => 't20', 'key' => 'assassin', 'name' => 'Assassin', 'glyph' => '🥷', 'description' => 'Strikes from the shadows for massive burst.', 'level_cap' => 20],
            ['base_class' => 'rogue', 'tier' => 't20', 'key' => 'trickster', 'name' => 'Trickster', 'glyph' => '🃏', 'description' => 'Unpredictable, evasive, always one step ahead.', 'level_cap' => 20],
            ['base_class' => 'rogue', 'tier' => 't40', 'key' => 'shadowblade', 'name' => 'Shadowblade', 'glyph' => '🗡', 'description' => 'A Rogue profession specializing in critical execution.', 'level_cap' => 40],
            ['base_class' => 'rogue', 'tier' => 't60', 'key' => 'wraith', 'name' => 'Wraith', 'glyph' => '👻', 'description' => 'Ascended rogue, half-phased between worlds.', 'level_cap' => 60],

            ['base_class' => 'ranger', 'tier' => 't20', 'key' => 'hunter', 'name' => 'Hunter', 'glyph' => '🏹', 'description' => 'Tracks and takes down prey with precision.', 'level_cap' => 20],
            ['base_class' => 'ranger', 'tier' => 't20', 'key' => 'beastmaster', 'name' => 'Beastmaster', 'glyph' => '🐾', 'description' => 'Fights alongside a bonded companion.', 'level_cap' => 20],
            ['base_class' => 'ranger', 'tier' => 't40', 'key' => 'sharpshooter', 'name' => 'Sharpshooter', 'glyph' => '🎯', 'description' => 'A Ranger profession with unmatched critical range damage.', 'level_cap' => 40],
            ['base_class' => 'ranger', 'tier' => 't60', 'key' => 'stormcaller', 'name' => 'Stormcaller', 'glyph' => '⚡', 'description' => 'Ascended ranger who commands the storm itself.', 'level_cap' => 60],
        ];

        foreach ($progressions as $p) {
            ClassProgression::updateOrCreate(
                ['base_class' => $p['base_class'], 'tier' => $p['tier'], 'key' => $p['key']],
                $p
            );
        }
    }
}
