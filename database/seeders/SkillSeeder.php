<?php

namespace Database\Seeders;

use App\Models\Skill;
use Illuminate\Database\Seeder;

class SkillSeeder extends Seeder
{
    /** Every class gets exactly 3 skills: an early passive stat buff, a mid-game active nuke, and a Lv.40 signature ultimate. */
    public function run(): void
    {
        $skills = [
            // Warrior — Warfare: tanky melee, leans on Cleave's AoE and Undying's survivability.
            ['branch' => 'Warfare', 'key' => 'power_strike', 'name' => 'Power Strike', 'glyph' => '⚔', 'description' => '+15% attack damage on your basic attack.', 'tier' => 1, 'level_req' => 1, 'mp_cost' => 0, 'max_level' => 5, 'effect_json' => ['atk_pct' => 15], 'class_scope' => 'warrior'],
            ['branch' => 'Warfare', 'key' => 'cleave', 'name' => 'Cleave', 'glyph' => '🪓', 'description' => 'Strike all enemies at once. Costs MP.', 'tier' => 2, 'level_req' => 20, 'mp_cost' => 20, 'max_level' => 5, 'effect_json' => ['aoe' => true], 'class_scope' => 'warrior'],
            ['branch' => 'Warfare', 'key' => 'undying', 'name' => 'Undying', 'glyph' => '✨', 'description' => 'Survive one otherwise-fatal hit per battle.', 'tier' => 3, 'level_req' => 40, 'mp_cost' => 0, 'max_level' => 1, 'effect_json' => ['revive_once' => true], 'class_scope' => 'warrior'],

            // Mage — Sorcery: pure burst caster, spends MP for bigger and bigger nukes.
            ['branch' => 'Sorcery', 'key' => 'shadow_bolt', 'name' => 'Shadow Bolt', 'glyph' => '✷', 'description' => 'The core burst spell — heavy single-target damage.', 'tier' => 1, 'level_req' => 1, 'mp_cost' => 40, 'max_level' => 5, 'effect_json' => ['dmg_mult' => 1.9], 'class_scope' => 'mage'],
            ['branch' => 'Sorcery', 'key' => 'chain_cast', 'name' => 'Chain Cast', 'glyph' => '⚡', 'description' => 'Your spells hit twice. Costs MP.', 'tier' => 2, 'level_req' => 20, 'mp_cost' => 60, 'max_level' => 5, 'effect_json' => ['hits' => 2], 'class_scope' => 'mage'],
            ['branch' => 'Sorcery', 'key' => 'void_nova', 'name' => 'Void Nova', 'glyph' => '🌀', 'description' => 'A massive AoE ultimate that devastates the battlefield.', 'tier' => 3, 'level_req' => 40, 'mp_cost' => 90, 'max_level' => 3, 'effect_json' => ['aoe' => true, 'dmg_mult' => 2.5], 'class_scope' => 'mage'],

            // Rogue — Shadowcraft: crit/burst assassin, escalating hit count on the ultimate.
            ['branch' => 'Shadowcraft', 'key' => 'precision_strikes', 'name' => 'Precision Strikes', 'glyph' => '🗡', 'description' => '+15% attack damage on your basic attack.', 'tier' => 1, 'level_req' => 1, 'mp_cost' => 0, 'max_level' => 5, 'effect_json' => ['atk_pct' => 15], 'class_scope' => 'rogue'],
            ['branch' => 'Shadowcraft', 'key' => 'shadow_strike', 'name' => 'Shadow Strike', 'glyph' => '🥷', 'description' => 'A single devastating strike from the shadows. Costs MP.', 'tier' => 2, 'level_req' => 20, 'mp_cost' => 30, 'max_level' => 5, 'effect_json' => ['dmg_mult' => 2.0], 'class_scope' => 'rogue'],
            ['branch' => 'Shadowcraft', 'key' => 'thousand_cuts', 'name' => 'Thousand Cuts', 'glyph' => '🌪', 'description' => 'A flurry of three rapid strikes. Costs MP.', 'tier' => 3, 'level_req' => 40, 'mp_cost' => 65, 'max_level' => 3, 'effect_json' => ['dmg_mult' => 1.6, 'hits' => 3], 'class_scope' => 'rogue'],

            // Ranger — Marksmanship: precise ranged damage building to a raining-arrows AoE ultimate.
            ['branch' => 'Marksmanship', 'key' => 'focused_aim', 'name' => 'Focused Aim', 'glyph' => '🎯', 'description' => '+15% attack damage on your basic attack.', 'tier' => 1, 'level_req' => 1, 'mp_cost' => 0, 'max_level' => 5, 'effect_json' => ['atk_pct' => 15], 'class_scope' => 'ranger'],
            ['branch' => 'Marksmanship', 'key' => 'piercing_shot', 'name' => 'Piercing Shot', 'glyph' => '🏹', 'description' => 'A precise shot that punches through armor. Costs MP.', 'tier' => 2, 'level_req' => 20, 'mp_cost' => 30, 'max_level' => 5, 'effect_json' => ['dmg_mult' => 2.1], 'class_scope' => 'ranger'],
            ['branch' => 'Marksmanship', 'key' => 'rain_of_arrows', 'name' => 'Rain of Arrows', 'glyph' => '🌧', 'description' => 'A hail of arrows strikes all enemies at once. Costs MP.', 'tier' => 3, 'level_req' => 40, 'mp_cost' => 75, 'max_level' => 3, 'effect_json' => ['aoe' => true, 'dmg_mult' => 2.3], 'class_scope' => 'ranger'],
        ];

        $keys = collect($skills)->pluck('key');
        Skill::whereNotIn('key', $keys)->delete();

        foreach ($skills as $skill) {
            Skill::updateOrCreate(['key' => $skill['key']], $skill);
        }
    }
}
