<?php

namespace Database\Seeders;

use App\Models\Skill;
use Illuminate\Database\Seeder;

class SkillSeeder extends Seeder
{
    /** Every class gets 3 skills: an early passive stat buff, a mid-game active nuke, and a Lv.40 signature
     * ultimate. Mage additionally gets Healing Light, a standalone Restoration-branch heal — the only
     * class with a self-heal, fitting its caster identity. Every class also gets one "Mastery" branch
     * skill gated on actually picking a t20 profession (see requires_profession below), not just reaching
     * the level. */
    public function run(): void
    {
        // Rank-unlock levels are spread across each tier's own window (before the next tier unlocks),
        // instead of all ranks being purchasable back-to-back the moment enough points are banked —
        // e.g. a max_level-5 tier-1 skill used to be fully maxed by level 5; now its 5th rank waits until Lv.19.
        // Ranks 6-8 (and the 4th/5th ultimate rank) exist to give the Lv.60-150 post-content grind
        // somewhere real to spend skill points — see Character::MAX_LEVEL.
        $t1of5 = [1, 6, 11, 16, 19, 45, 70, 95];
        $t2of5 = [20, 25, 30, 35, 39, 55, 80, 105];
        $t3of3 = [40, 50, 60, 85, 120];
        $healRanks = [15, 20, 25, 30, 35, 45, 70, 95];

        $skills = [
            // Warrior — Warfare: tanky melee, leans on Cleave's AoE and Undying's survivability.
            ['branch' => 'Warfare', 'key' => 'power_strike', 'name' => 'Power Strike', 'glyph' => '⚔', 'description' => '+15% attack damage on your basic attack.', 'tier' => 1, 'level_req' => 1, 'mp_cost' => 0, 'cooldown_seconds' => 0, 'cooldown_rounds' => 0, 'max_level' => 8, 'rank_levels' => $t1of5, 'effect_json' => ['atk_pct' => 15], 'class_scope' => 'warrior'],
            ['branch' => 'Warfare', 'key' => 'cleave', 'name' => 'Cleave', 'glyph' => '🪓', 'description' => 'Strike all enemies at once. Costs MP.', 'tier' => 2, 'level_req' => 20, 'mp_cost' => 20, 'cooldown_seconds' => 12, 'cooldown_rounds' => 3, 'max_level' => 8, 'rank_levels' => $t2of5, 'effect_json' => ['aoe' => true], 'class_scope' => 'warrior'],
            ['branch' => 'Warfare', 'key' => 'undying', 'name' => 'Undying', 'glyph' => '✨', 'description' => 'Survive one otherwise-fatal hit per battle.', 'tier' => 3, 'level_req' => 40, 'mp_cost' => 0, 'cooldown_seconds' => 0, 'cooldown_rounds' => 0, 'max_level' => 1, 'rank_levels' => [40], 'effect_json' => ['revive_once' => true], 'class_scope' => 'warrior'],

            // Mage — Sorcery: pure burst caster, spends MP for bigger and bigger nukes.
            ['branch' => 'Sorcery', 'key' => 'shadow_bolt', 'name' => 'Shadow Bolt', 'glyph' => '✷', 'description' => 'The core burst spell — heavy single-target damage.', 'tier' => 1, 'level_req' => 1, 'mp_cost' => 40, 'cooldown_seconds' => 6, 'cooldown_rounds' => 2, 'max_level' => 8, 'rank_levels' => $t1of5, 'effect_json' => ['dmg_mult' => 1.9], 'class_scope' => 'mage'],
            ['branch' => 'Sorcery', 'key' => 'chain_cast', 'name' => 'Chain Cast', 'glyph' => '⚡', 'description' => 'Your spells hit twice. Costs MP.', 'tier' => 2, 'level_req' => 20, 'mp_cost' => 60, 'cooldown_seconds' => 12, 'cooldown_rounds' => 3, 'max_level' => 8, 'rank_levels' => $t2of5, 'effect_json' => ['hits' => 2], 'class_scope' => 'mage'],
            ['branch' => 'Sorcery', 'key' => 'void_nova', 'name' => 'Void Nova', 'glyph' => '🌀', 'description' => 'A massive AoE ultimate that devastates the battlefield.', 'tier' => 3, 'level_req' => 40, 'mp_cost' => 90, 'cooldown_seconds' => 25, 'cooldown_rounds' => 5, 'max_level' => 5, 'rank_levels' => $t3of3, 'effect_json' => ['aoe' => true, 'dmg_mult' => 2.5], 'class_scope' => 'mage'],

            // Mage — Restoration: a standalone utility branch (not chained to Sorcery) — mage is the only
            // class with a self-heal, fitting its caster identity. Costs a full turn like any other skill,
            // so it's a real tradeoff against just nuking, not a free lifeline.
            ['branch' => 'Restoration', 'key' => 'healing_light', 'name' => 'Healing Light', 'glyph' => '💚', 'description' => 'Restore a portion of your max HP. Costs MP.', 'tier' => 1, 'level_req' => 15, 'mp_cost' => 35, 'cooldown_seconds' => 15, 'cooldown_rounds' => 3, 'max_level' => 8, 'rank_levels' => $healRanks, 'effect_json' => ['heal_hp_pct' => 30], 'class_scope' => 'mage'],

            // Rogue — Shadowcraft: crit/burst assassin, escalating hit count on the ultimate.
            ['branch' => 'Shadowcraft', 'key' => 'precision_strikes', 'name' => 'Precision Strikes', 'glyph' => '🗡', 'description' => '+15% attack damage on your basic attack.', 'tier' => 1, 'level_req' => 1, 'mp_cost' => 0, 'cooldown_seconds' => 0, 'cooldown_rounds' => 0, 'max_level' => 8, 'rank_levels' => $t1of5, 'effect_json' => ['atk_pct' => 15], 'class_scope' => 'rogue'],
            ['branch' => 'Shadowcraft', 'key' => 'shadow_strike', 'name' => 'Shadow Strike', 'glyph' => '🥷', 'description' => 'A single devastating strike from the shadows. Costs MP.', 'tier' => 2, 'level_req' => 20, 'mp_cost' => 30, 'cooldown_seconds' => 12, 'cooldown_rounds' => 3, 'max_level' => 8, 'rank_levels' => $t2of5, 'effect_json' => ['dmg_mult' => 2.0], 'class_scope' => 'rogue'],
            ['branch' => 'Shadowcraft', 'key' => 'thousand_cuts', 'name' => 'Thousand Cuts', 'glyph' => '🌪', 'description' => 'A flurry of three rapid strikes. Costs MP.', 'tier' => 3, 'level_req' => 40, 'mp_cost' => 65, 'cooldown_seconds' => 25, 'cooldown_rounds' => 5, 'max_level' => 5, 'rank_levels' => $t3of3, 'effect_json' => ['dmg_mult' => 1.6, 'hits' => 3], 'class_scope' => 'rogue'],

            // Ranger — Marksmanship: precise ranged damage building to a raining-arrows AoE ultimate.
            ['branch' => 'Marksmanship', 'key' => 'focused_aim', 'name' => 'Focused Aim', 'glyph' => '🎯', 'description' => '+15% attack damage on your basic attack.', 'tier' => 1, 'level_req' => 1, 'mp_cost' => 0, 'cooldown_seconds' => 0, 'cooldown_rounds' => 0, 'max_level' => 8, 'rank_levels' => $t1of5, 'effect_json' => ['atk_pct' => 15], 'class_scope' => 'ranger'],
            ['branch' => 'Marksmanship', 'key' => 'piercing_shot', 'name' => 'Piercing Shot', 'glyph' => '🏹', 'description' => 'A precise shot that punches through armor. Costs MP.', 'tier' => 2, 'level_req' => 20, 'mp_cost' => 30, 'cooldown_seconds' => 12, 'cooldown_rounds' => 3, 'max_level' => 8, 'rank_levels' => $t2of5, 'effect_json' => ['dmg_mult' => 2.1], 'class_scope' => 'ranger'],
            ['branch' => 'Marksmanship', 'key' => 'rain_of_arrows', 'name' => 'Rain of Arrows', 'glyph' => '🌧', 'description' => 'A hail of arrows strikes all enemies at once. Costs MP.', 'tier' => 3, 'level_req' => 40, 'mp_cost' => 75, 'cooldown_seconds' => 25, 'cooldown_rounds' => 5, 'max_level' => 5, 'rank_levels' => $t3of3, 'effect_json' => ['aoe' => true, 'dmg_mult' => 2.3], 'class_scope' => 'ranger'],

            // "Mastery" branch — one skill per class, standalone (no prior-skill prerequisite within the
            // branch, single rank) and gated on having actually chosen a t20 profession (Character::spec_class,
            // see CharacterController::unlockSkill()) rather than just reaching Lv.20, so picking a profession
            // is a real unlock moment, not just a name change.
            ['branch' => 'Mastery', 'key' => 'warlords_resolve', 'name' => "Warlord's Resolve", 'glyph' => '🏰', 'description' => 'Your training as a warlord hardens your stance. +12% defense, permanently.', 'tier' => 1, 'level_req' => 20, 'mp_cost' => 0, 'cooldown_seconds' => 0, 'cooldown_rounds' => 0, 'max_level' => 1, 'rank_levels' => [20], 'effect_json' => ['def_pct' => 12], 'class_scope' => 'warrior', 'requires_profession' => true],
            ['branch' => 'Mastery', 'key' => 'archmagi_surge', 'name' => 'Archmagi Surge', 'glyph' => '🌠', 'description' => 'Channel your profession\'s arcane mastery into one devastating burst. Costs MP.', 'tier' => 1, 'level_req' => 20, 'mp_cost' => 70, 'cooldown_seconds' => 20, 'cooldown_rounds' => 4, 'max_level' => 1, 'rank_levels' => [20], 'effect_json' => ['dmg_mult' => 2.6], 'class_scope' => 'mage', 'requires_profession' => true],
            ['branch' => 'Mastery', 'key' => 'killer_instinct', 'name' => 'Killer Instinct', 'glyph' => '🔪', 'description' => 'Everything your profession taught you converges on one lethal opening. Costs MP.', 'tier' => 1, 'level_req' => 20, 'mp_cost' => 35, 'cooldown_seconds' => 14, 'cooldown_rounds' => 3, 'max_level' => 1, 'rank_levels' => [20], 'effect_json' => ['dmg_mult' => 2.2], 'class_scope' => 'rogue', 'requires_profession' => true],
            ['branch' => 'Mastery', 'key' => 'trueshot', 'name' => 'Trueshot', 'glyph' => '🎯', 'description' => 'A profession-trained shot that never misses its mark. Costs MP.', 'tier' => 1, 'level_req' => 20, 'mp_cost' => 35, 'cooldown_seconds' => 14, 'cooldown_rounds' => 3, 'max_level' => 1, 'rank_levels' => [20], 'effect_json' => ['dmg_mult' => 2.2], 'class_scope' => 'ranger', 'requires_profession' => true],
        ];

        $keys = collect($skills)->pluck('key');
        Skill::whereNotIn('key', $keys)->delete();

        foreach ($skills as $skill) {
            Skill::updateOrCreate(['key' => $skill['key']], $skill);
        }
    }
}
