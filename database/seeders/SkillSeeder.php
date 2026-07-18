<?php

namespace Database\Seeders;

use App\Models\Skill;
use Illuminate\Database\Seeder;

class SkillSeeder extends Seeder
{
    public function run(): void
    {
        $skills = [
            ['branch' => 'Warfare', 'key' => 'power_strike', 'name' => 'Power Strike', 'glyph' => '⚔', 'description' => '+15% attack damage on your basic attack.', 'tier' => 1, 'level_req' => 1, 'mp_cost' => 0, 'effect_json' => ['atk_pct' => 15], 'class_scope' => 'warrior'],
            ['branch' => 'Warfare', 'key' => 'cleave', 'name' => 'Cleave', 'glyph' => '🪓', 'description' => 'Strike all enemies at once. Costs MP.', 'tier' => 2, 'level_req' => 15, 'mp_cost' => 20, 'effect_json' => ['aoe' => true], 'class_scope' => 'warrior'],
            ['branch' => 'Sorcery', 'key' => 'shadow_bolt', 'name' => 'Shadow Bolt', 'glyph' => '✷', 'description' => 'The core burst spell — heavy single-target damage.', 'tier' => 1, 'level_req' => 1, 'mp_cost' => 40, 'effect_json' => ['dmg_mult' => 1.9], 'class_scope' => 'mage'],
            ['branch' => 'Sorcery', 'key' => 'chain_cast', 'name' => 'Chain Cast', 'glyph' => '⚡', 'description' => 'Your spells hit twice. Costs MP.', 'tier' => 3, 'level_req' => 30, 'mp_cost' => 60, 'effect_json' => ['hits' => 2], 'class_scope' => 'mage'],
            ['branch' => 'Sorcery', 'key' => 'void_nova', 'name' => 'Void Nova', 'glyph' => '🌀', 'description' => 'A massive AoE ultimate that devastates the battlefield.', 'tier' => 4, 'level_req' => 50, 'mp_cost' => 90, 'effect_json' => ['aoe' => true, 'dmg_mult' => 2.5], 'class_scope' => 'mage'],
            ['branch' => 'Survival', 'key' => 'tough_skin', 'name' => 'Tough Skin', 'glyph' => '🛡', 'description' => '+20% defense, permanently.', 'tier' => 1, 'level_req' => 1, 'mp_cost' => 0, 'effect_json' => ['def_pct' => 20], 'class_scope' => null],
            ['branch' => 'Survival', 'key' => 'undying', 'name' => 'Undying', 'glyph' => '✨', 'description' => 'Survive one otherwise-fatal hit per battle.', 'tier' => 4, 'level_req' => 50, 'mp_cost' => 0, 'effect_json' => ['revive_once' => true], 'class_scope' => null],
        ];

        foreach ($skills as $skill) {
            Skill::updateOrCreate(['key' => $skill['key']], $skill);
        }
    }
}
