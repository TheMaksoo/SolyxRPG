<?php

namespace Database\Seeders;

use App\Models\Character;
use App\Models\Skill;
use Illuminate\Database\Seeder;

class SkillSeeder extends Seeder
{
    /** One unique LEFT-arm word pair and one unique RIGHT-arm capstone word per signature branch —
     * fixes the "every branch's tree reads the same" complaint: before this, EVERY branch's left arm
     * used the exact same two words ('Adept'/'Zealot') and EVERY right arm capstone used one of only two
     * shared names ('Bloodlust'/'Riposte') across all 40 branches. `left` feeds left_1/left_2's node
     * names AND the arm-path-name badge shown in the UI (see treeNodes()'s `arm_path_name` effect_json
     * key, read by SkillsPage.vue's arm-footer); `rightCap` replaces the shared archetype capName with a
     * branch-unique one while keeping the archetype's actual mechanic (mend_over_time for offense
     * branches, evasion for everyone else) exactly as-is. */
    private const ARM_WORDS = [
        // Warrior
        'berserker' => ['left' => ['Rage', 'Fury'], 'rightCap' => 'Bloodrite'],
        'guardian' => ['left' => ['Ward', 'Bastion'], 'rightCap' => 'Parry'],
        'warlord' => ['left' => ['Conquest', 'Dominion'], 'rightCap' => 'Reckoning'],
        'vanguard' => ['left' => ['Bulwark', 'Rampart'], 'rightCap' => 'Riposte'],
        'warmonger' => ['left' => ['Wrath', 'Carnage'], 'rightCap' => 'Warcry'],
        'titan' => ['left' => ['Ascendance', 'Colossus'], 'rightCap' => 'Rebuke'],
        'bloodfury' => ['left' => ['Bloodfury', 'Butchery'], 'rightCap' => 'Exsanguinate'],
        'aegis_warden' => ['left' => ['Aegis', 'Sanctum'], 'rightCap' => 'Deflection'],
        'godslayer' => ['left' => ['Godslaying', 'Ruin'], 'rightCap' => 'Apotheosis'],
        'immortal_bulwark' => ['left' => ['Immortal', 'Eternity'], 'rightCap' => 'Transcendence'],
        // Mage
        'shadowmage' => ['left' => ['Drain', 'Unlife'], 'rightCap' => 'Terror'],
        'elementalist' => ['left' => ['Elemental', 'Tempest'], 'rightCap' => 'Backdraft'],
        'necromancer' => ['left' => ['Blight', 'Rot'], 'rightCap' => 'Deathgrip'],
        'stormweaver' => ['left' => ['Stormward', 'Gale'], 'rightCap' => 'Backlash'],
        'archmage' => ['left' => ['Arcane', 'Ruinous'], 'rightCap' => 'Overload'],
        'voidcaller' => ['left' => ['Void', 'Abyss'], 'rightCap' => 'Displace'],
        'dreadlich' => ['left' => ['Dread', 'Wither'], 'rightCap' => 'Soulrend'],
        'stormsovereign' => ['left' => ['Sovereign', 'Skyrend'], 'rightCap' => 'Stormshield'],
        'worldender' => ['left' => ['Apocalypse', 'Oblivion'], 'rightCap' => 'Cataclysm'],
        'astral_oracle' => ['left' => ['Fate', 'Prophecy'], 'rightCap' => 'Foresight'],
        // Rogue
        'assassin' => ['left' => ['Killzone', 'Ambush'], 'rightCap' => 'Coldblood'],
        'trickster' => ['left' => ['Feint', 'Mirage'], 'rightCap' => 'Sleight'],
        'shadowblade' => ['left' => ['Cripple', 'Maim'], 'rightCap' => 'Bloodrend'],
        'duskrunner' => ['left' => ['Duskstep', 'Nightfall'], 'rightCap' => 'Vanish'],
        'nightblade' => ['left' => ['Bloodfall', 'Reave'], 'rightCap' => 'Bloodfeast'],
        'wraith' => ['left' => ['Wraithform', 'Hollow'], 'rightCap' => 'Phasewalk'],
        'bloodreaper' => ['left' => ['Harvest', 'Slaughter'], 'rightCap' => 'Reaping'],
        'phantom_dancer' => ['left' => ['Phantom', 'Umbra'], 'rightCap' => 'Afterimage'],
        'deathbringer' => ['left' => ['Deathmark', 'Perdition'], 'rightCap' => 'Executioner'],
        'shadowveil_sovereign' => ['left' => ['Shadowveil', 'Eclipse'], 'rightCap' => 'Voidstep'],
        // Ranger
        'hunter' => ['left' => ['Huntersmark', 'Quarry'], 'rightCap' => 'Bloodtrail'],
        'beastmaster' => ['left' => ['Bonded', 'Wildcall'], 'rightCap' => 'Featherstep'],
        'sharpshooter' => ['left' => ['Deadeye', 'Trueshot'], 'rightCap' => 'Ricochet'],
        'pathfinder' => ['left' => ['Wayfinder', 'Trailblaze'], 'rightCap' => 'Quickstep'],
        'stormcaller' => ['left' => ['Stormcall', 'Thunderhead'], 'rightCap' => 'Overcharge'],
        'beastlord' => ['left' => ['Packbond', 'Feral'], 'rightCap' => 'Wildstep'],
        'windrunner' => ['left' => ['Windrunning', 'Galeforce'], 'rightCap' => 'Skybreak'],
        'wildwarden' => ['left' => ['Wildwarden', 'Rootbond'], 'rightCap' => 'Thornstep'],
        'skysovereign' => ['left' => ['Skysovereign', 'Stormcrown'], 'rightCap' => 'Falcondive'],
        'primal_avatar' => ['left' => ['Primal', 'Wildheart'], 'rightCap' => 'Ferality'],
    ];

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
        // Trailing 3 entries beyond each skill's own max_level are the Skill Mastery bonus ranks (see
        // GameConfig 'skill_mastery_unlock_level'/'skill_mastery_bonus_levels' and
        // CharacterController::unlockSkill()'s effective-max-level check) — a level-150+ character can
        // keep raising these skills past their normal cap, so rank_levels needs real level gates defined
        // that far out even though max_level itself never changes.
        $t1of5 = [1, 6, 11, 16, 19, 45, 70, 95, 130, 145, 160];
        $t3of3 = [40, 50, 60, 85, 120, 140, 160, 180];
        $healRanks = [15, 20, 25, 30, 35, 45, 70, 95, 130, 145, 160];

        // Every class's own foundation now has a REAL branch point at Lv.20 too (mirroring the 40
        // signature branches' hub/arm mechanic), instead of every core skill being mandatory/sequential:
        // trunk (T1, always) -> hub -> commit to LEFT ("more damage" — the old T2 skill) or RIGHT
        // ("more debuffs" — the old T3 skill, rethemed with a rank-scaling applies_status; see
        // CombatService::applySkillStatuses()'s new `scales_with_rank` flag) — permanently, until a
        // respec, exactly like every signature branch. A stray utility skill each class doesn't have a
        // natural offense/debuff partner for (Undying, Healing Light) becomes node_slot 'utility' —
        // always available on its own, outside the branch choice entirely.
        // Staggered off level 20 (hub 15, arms 18) rather than clustering with every signature branch's
        // own level-20 tree — see treeNodes()'s identical per-node staggering for why: a player should
        // feel their core class tree finish taking shape BEFORE the level-20 specialization choice lands,
        // not all at once alongside it.
        $foundHubRanks = [15, 25, 35];
        $foundCapRanks = [18, 23, 28, 33, 37, 53, 78, 103, 138, 153, 168]; // $t2of5 shifted -2 to match the new level_req=18.

        $skills = [
            // Warrior — Warfare: tanky melee. LEFT = Cleave (more AoE damage), RIGHT = Sunder (a new
            // armor-shredding strike — "more debuffs"). Undying stays a standalone always-on utility,
            // since a guaranteed-revive passive doesn't fit either offense arm.
            ['branch' => 'Warfare', 'key' => 'power_strike', 'name' => 'Power Strike', 'glyph' => '⚔', 'description' => '+15% attack damage on your basic attack.', 'tier' => 1, 'level_req' => 1, 'mp_cost' => 0, 'cooldown_seconds' => 0, 'cooldown_rounds' => 0, 'max_level' => 8, 'rank_levels' => $t1of5, 'effect_json' => ['atk_pct' => 15], 'class_scope' => 'warrior', 'node_slot' => 'trunk_1'],
            ['branch' => 'Warfare', 'key' => 'cleave', 'name' => 'Cleave', 'glyph' => '🪓', 'description' => 'Strike all enemies at once. Costs MP.', 'tier' => 2, 'level_req' => 18, 'mp_cost' => 20, 'cooldown_seconds' => 12, 'cooldown_rounds' => 3, 'max_level' => 8, 'rank_levels' => $foundCapRanks, 'effect_json' => ['aoe' => true], 'class_scope' => 'warrior', 'node_slot' => 'left_cap', 'prereq_skill_key' => 'warfare_hub'],
            ['branch' => 'Warfare', 'key' => 'sunder', 'name' => 'Sunder', 'glyph' => '💢', 'description' => 'A crushing blow that cracks their guard wide open, shredding their armor. Costs MP.', 'tier' => 2, 'level_req' => 18, 'mp_cost' => 25, 'cooldown_seconds' => 12, 'cooldown_rounds' => 3, 'max_level' => 8, 'rank_levels' => $foundCapRanks, 'effect_json' => ['dmg_mult' => 1.6, 'applies_status' => [['effect' => 'armor_shred', 'target' => 'enemy', 'magnitude' => 15, 'rounds' => 3, 'scales_with_rank' => true]]], 'class_scope' => 'warrior', 'node_slot' => 'right_cap', 'prereq_skill_key' => 'warfare_hub'],
            ['branch' => 'Warfare', 'key' => 'undying', 'name' => 'Undying', 'glyph' => '✨', 'description' => 'Survive one otherwise-fatal hit per battle.', 'tier' => 3, 'level_req' => 40, 'mp_cost' => 0, 'cooldown_seconds' => 0, 'cooldown_rounds' => 0, 'max_level' => 1, 'rank_levels' => [40], 'effect_json' => ['revive_once' => true], 'class_scope' => 'warrior', 'node_slot' => 'utility'],
            ['branch' => 'Warfare', 'key' => 'warfare_hub', 'name' => 'Warfare Focus', 'glyph' => '⚔', 'description' => 'The branch point: commit to Cleave or Sunder from here. Also toughens you against enemy debuffs. Passive.', 'tier' => 1, 'level_req' => 15, 'mp_cost' => 0, 'cooldown_seconds' => 0, 'cooldown_rounds' => 0, 'max_level' => 3, 'rank_levels' => $foundHubRanks, 'effect_json' => ['hp_pct' => 3, 'debuff_resist_pct' => 4], 'class_scope' => 'warrior', 'node_slot' => 'hub', 'prereq_skill_key' => 'power_strike'],

            // Mage — Sorcery: pure burst caster. LEFT = Chain Cast (spells hit twice — more damage),
            // RIGHT = Void Nova (AoE ultimate that also spreads Vulnerable — more debuffs).
            ['branch' => 'Sorcery', 'key' => 'shadow_bolt', 'name' => 'Shadow Bolt', 'glyph' => '✷', 'description' => 'The core burst spell: heavy single-target damage.', 'tier' => 1, 'level_req' => 1, 'mp_cost' => 40, 'cooldown_seconds' => 6, 'cooldown_rounds' => 2, 'max_level' => 8, 'rank_levels' => $t1of5, 'effect_json' => ['dmg_mult' => 1.9], 'class_scope' => 'mage', 'node_slot' => 'trunk_1'],
            ['branch' => 'Sorcery', 'key' => 'chain_cast', 'name' => 'Chain Cast', 'glyph' => '⚡', 'description' => 'Your spell hits twice, jumping to a second enemy if the first one goes down. Costs MP.', 'tier' => 2, 'level_req' => 18, 'mp_cost' => 60, 'cooldown_seconds' => 12, 'cooldown_rounds' => 3, 'max_level' => 8, 'rank_levels' => $foundCapRanks, 'effect_json' => ['hits' => 2, 'cleave' => true], 'class_scope' => 'mage', 'node_slot' => 'left_cap', 'prereq_skill_key' => 'sorcery_hub'],
            ['branch' => 'Sorcery', 'key' => 'void_nova', 'name' => 'Void Nova', 'glyph' => '🌀', 'description' => 'A massive AoE ultimate that devastates the battlefield, leaving every survivor exposed. Costs MP.', 'tier' => 3, 'level_req' => 20, 'mp_cost' => 90, 'cooldown_seconds' => 25, 'cooldown_rounds' => 5, 'max_level' => 5, 'rank_levels' => $t3of3, 'effect_json' => ['aoe' => true, 'dmg_mult' => 2.5, 'applies_status' => [['effect' => 'vulnerable', 'target' => 'enemy', 'magnitude' => 12, 'rounds' => 3, 'scales_with_rank' => true]]], 'class_scope' => 'mage', 'node_slot' => 'right_cap', 'prereq_skill_key' => 'sorcery_hub'],
            ['branch' => 'Sorcery', 'key' => 'sorcery_hub', 'name' => 'Sorcery Focus', 'glyph' => '✷', 'description' => 'The branch point: commit to Chain Cast or Void Nova from here. Passive.', 'tier' => 1, 'level_req' => 15, 'mp_cost' => 0, 'cooldown_seconds' => 0, 'cooldown_rounds' => 0, 'max_level' => 3, 'rank_levels' => $foundHubRanks, 'effect_json' => ['crit_damage_pct' => 3], 'class_scope' => 'mage', 'node_slot' => 'hub', 'prereq_skill_key' => 'shadow_bolt'],

            // Mage — Restoration: a standalone utility branch (not chained to Sorcery) — mage is the only
            // class with a self-heal, fitting its caster identity. Costs a full turn like any other skill,
            // so it's a real tradeoff against just nuking, not a free lifeline.
            ['branch' => 'Restoration', 'key' => 'healing_light', 'name' => 'Healing Light', 'glyph' => '💚', 'description' => 'Restore a portion of your max HP. Costs MP.', 'tier' => 1, 'level_req' => 15, 'mp_cost' => 35, 'cooldown_seconds' => 15, 'cooldown_rounds' => 3, 'max_level' => 8, 'rank_levels' => $healRanks, 'effect_json' => ['heal_hp_pct' => 30], 'class_scope' => 'mage', 'node_slot' => 'utility'],

            // Rogue — Shadowcraft: crit/burst assassin. LEFT = Shadow Strike (more damage), RIGHT =
            // Thousand Cuts (a flurry that also Weakens their offense — more debuffs).
            ['branch' => 'Shadowcraft', 'key' => 'precision_strikes', 'name' => 'Precision Strikes', 'glyph' => '🗡', 'description' => '+15% attack damage on your basic attack.', 'tier' => 1, 'level_req' => 1, 'mp_cost' => 0, 'cooldown_seconds' => 0, 'cooldown_rounds' => 0, 'max_level' => 8, 'rank_levels' => $t1of5, 'effect_json' => ['atk_pct' => 15], 'class_scope' => 'rogue', 'node_slot' => 'trunk_1'],
            ['branch' => 'Shadowcraft', 'key' => 'shadow_strike', 'name' => 'Shadow Strike', 'glyph' => '🥷', 'description' => 'A single devastating strike from the shadows. Costs MP.', 'tier' => 2, 'level_req' => 18, 'mp_cost' => 30, 'cooldown_seconds' => 12, 'cooldown_rounds' => 3, 'max_level' => 8, 'rank_levels' => $foundCapRanks, 'effect_json' => ['dmg_mult' => 2.0], 'class_scope' => 'rogue', 'node_slot' => 'left_cap', 'prereq_skill_key' => 'shadowcraft_hub'],
            ['branch' => 'Shadowcraft', 'key' => 'thousand_cuts', 'name' => 'Thousand Cuts', 'glyph' => '🌪', 'description' => 'A flurry of three rapid strikes that wears down their offense, carving through to the next target if one falls. Costs MP.', 'tier' => 3, 'level_req' => 20, 'mp_cost' => 65, 'cooldown_seconds' => 25, 'cooldown_rounds' => 5, 'max_level' => 5, 'rank_levels' => $t3of3, 'effect_json' => ['dmg_mult' => 1.6, 'hits' => 3, 'cleave' => true, 'applies_status' => [['effect' => 'weaken', 'target' => 'enemy', 'magnitude' => 10, 'rounds' => 3, 'scales_with_rank' => true]]], 'class_scope' => 'rogue', 'node_slot' => 'right_cap', 'prereq_skill_key' => 'shadowcraft_hub'],
            ['branch' => 'Shadowcraft', 'key' => 'shadowcraft_hub', 'name' => 'Shadowcraft Focus', 'glyph' => '🗡', 'description' => 'The branch point: commit to Shadow Strike or Thousand Cuts from here. Passive.', 'tier' => 1, 'level_req' => 15, 'mp_cost' => 0, 'cooldown_seconds' => 0, 'cooldown_rounds' => 0, 'max_level' => 3, 'rank_levels' => $foundHubRanks, 'effect_json' => ['crit_chance_flat' => 2], 'class_scope' => 'rogue', 'node_slot' => 'hub', 'prereq_skill_key' => 'precision_strikes'],

            // Rogue's own standalone utility branch (not chained to Shadowcraft), same pattern as Mage's
            // Healing Light — Rogue was the only class with no true AOE answer (Shadow Strike/Thousand
            // Cuts are both single-target-flavored), so this fills that gap with a real "hit everyone"
            // spin attack, outside the exclusive arm choice.
            ['branch' => 'Shadowcraft', 'key' => 'whirling_blades', 'name' => 'Whirling Blades', 'glyph' => '🌀🗡', 'description' => 'Spin through every enemy within reach, cutting them all at once. Costs MP.', 'tier' => 1, 'level_req' => 15, 'mp_cost' => 35, 'cooldown_seconds' => 15, 'cooldown_rounds' => 3, 'max_level' => 8, 'rank_levels' => $healRanks, 'effect_json' => ['aoe' => true, 'dmg_mult' => 1.3], 'class_scope' => 'rogue', 'node_slot' => 'utility'],

            // Ranger — Marksmanship: precise ranged damage. LEFT = Piercing Shot (more damage), RIGHT =
            // Rain of Arrows (AoE hail that also Slows everyone it hits — more debuffs).
            ['branch' => 'Marksmanship', 'key' => 'focused_aim', 'name' => 'Focused Aim', 'glyph' => '🎯', 'description' => '+15% attack damage on your basic attack.', 'tier' => 1, 'level_req' => 1, 'mp_cost' => 0, 'cooldown_seconds' => 0, 'cooldown_rounds' => 0, 'max_level' => 8, 'rank_levels' => $t1of5, 'effect_json' => ['atk_pct' => 15], 'class_scope' => 'ranger', 'node_slot' => 'trunk_1'],
            ['branch' => 'Marksmanship', 'key' => 'piercing_shot', 'name' => 'Piercing Shot', 'glyph' => '🏹', 'description' => 'A precise shot that punches through armor. Costs MP.', 'tier' => 2, 'level_req' => 18, 'mp_cost' => 30, 'cooldown_seconds' => 12, 'cooldown_rounds' => 3, 'max_level' => 8, 'rank_levels' => $foundCapRanks, 'effect_json' => ['dmg_mult' => 2.1], 'class_scope' => 'ranger', 'node_slot' => 'left_cap', 'prereq_skill_key' => 'marksmanship_hub'],
            ['branch' => 'Marksmanship', 'key' => 'rain_of_arrows', 'name' => 'Rain of Arrows', 'glyph' => '🌧', 'description' => 'A hail of arrows strikes all enemies at once, pinning them down. Costs MP.', 'tier' => 3, 'level_req' => 20, 'mp_cost' => 75, 'cooldown_seconds' => 25, 'cooldown_rounds' => 5, 'max_level' => 5, 'rank_levels' => $t3of3, 'effect_json' => ['aoe' => true, 'dmg_mult' => 2.3, 'applies_status' => [['effect' => 'slow', 'target' => 'enemy', 'magnitude' => 10, 'rounds' => 3, 'scales_with_rank' => true]]], 'class_scope' => 'ranger', 'node_slot' => 'right_cap', 'prereq_skill_key' => 'marksmanship_hub'],
            ['branch' => 'Marksmanship', 'key' => 'marksmanship_hub', 'name' => 'Marksmanship Focus', 'glyph' => '🎯', 'description' => 'The branch point: commit to Piercing Shot or Rain of Arrows from here. Also keeps you off enemies\' radar, drawing less aggro. Passive.', 'tier' => 1, 'level_req' => 15, 'mp_cost' => 0, 'cooldown_seconds' => 0, 'cooldown_rounds' => 0, 'max_level' => 3, 'rank_levels' => $foundHubRanks, 'effect_json' => ['dodge_flat' => 2, 'threat_reduction_pct' => 4], 'class_scope' => 'ranger', 'node_slot' => 'hub', 'prereq_skill_key' => 'focused_aim'],

            // Signature branch skills — one per ClassProgression branch (see ClassSeeder, 40 rows across
            // the t20/t50/t100/t150/t200 ladder), each its own standalone single-node branch (no
            // prior-skill-in-branch prerequisite, max_level 1) so two mutually-exclusive branches at the
            // same tier can never soft-lock each other. Gated via requires_branch_key against the exact
            // ClassProgression key (checked across all 5 tier-pick columns in
            // CharacterController::unlockSkill()) rather than the old broader requires_profession
            // boolean, which only ever checked "is ANY t20 pick chosen." The "offense pole" branch per
            // class/tier gets an active nuke (dmg_mult); the "defense/utility pole" gets a passive
            // def_pct — SkillService::passiveAtkPct/passiveDefPct only ever reads atk_pct/def_pct off a
            // passive skill's effect_json, so def_pct is the one real passive lever available regardless
            // of a branch's own flavor stat (crit/dodge/hp) in Character::BRANCH_BONUSES; the branch's
            // own passive (from choosing it) already carries that flavor separately. Magnitude escalates
            // with tier: offense dmg_mult 1.4→1.8→2.2→2.6→3.0, defense def_pct 8→12→16→20→25.
            // Warrior: berserker/warlord/warmonger/bloodfury/godslayer (offense) vs. guardian/vanguard/titan/aegis_warden/immortal_bulwark (defense).
            ['branch' => 'Berserker', 'key' => 'berserkers_rage', 'name' => "Berserker's Rage", 'glyph' => '🪓', 'description' => 'A reckless overhead swing fueled by fury, leaving you a little exposed on the follow-up. Costs MP.', 'tier' => 1, 'level_req' => 20, 'mp_cost' => 25, 'cooldown_seconds' => 8, 'cooldown_rounds' => 2, 'max_level' => 1, 'rank_levels' => [20], 'effect_json' => ['dmg_mult' => 1.4, 'applies_status' => [['effect' => 'vulnerable', 'target' => 'self', 'magnitude' => 10, 'rounds' => 1]]], 'class_scope' => 'warrior', 'requires_branch_key' => 'berserker'],
            ['branch' => 'Guardian', 'key' => 'guardians_stand', 'name' => "Guardian's Stand", 'glyph' => '🛡', 'description' => 'Plant your feet and refuse to yield. +8% defense, permanently.', 'tier' => 1, 'level_req' => 20, 'mp_cost' => 0, 'cooldown_seconds' => 0, 'cooldown_rounds' => 0, 'max_level' => 1, 'rank_levels' => [20], 'effect_json' => ['def_pct' => 8], 'class_scope' => 'warrior', 'requires_branch_key' => 'guardian'],
            ['branch' => 'Warlord', 'key' => 'warlords_cleave', 'name' => "Warlord's Cleave", 'glyph' => '👑', 'description' => 'A commanding overhand strike that rallies your resolve, shielding you briefly. Costs MP.', 'tier' => 1, 'level_req' => 50, 'mp_cost' => 35, 'cooldown_seconds' => 14, 'cooldown_rounds' => 3, 'max_level' => 1, 'rank_levels' => [50], 'effect_json' => ['dmg_mult' => 1.8, 'applies_status' => [['effect' => 'shield', 'target' => 'self', 'magnitude' => 120, 'rounds' => 2]]], 'class_scope' => 'warrior', 'requires_branch_key' => 'warlord'],
            ['branch' => 'Vanguard', 'key' => 'warlords_resolve', 'name' => "Vanguard's Resolve", 'glyph' => '🔰', 'description' => 'Your training at the front line hardens your stance. +12% defense, permanently.', 'tier' => 1, 'level_req' => 50, 'mp_cost' => 0, 'cooldown_seconds' => 0, 'cooldown_rounds' => 0, 'max_level' => 1, 'rank_levels' => [50], 'effect_json' => ['def_pct' => 12], 'class_scope' => 'warrior', 'requires_branch_key' => 'vanguard'],
            ['branch' => 'Titan', 'key' => 'titans_endurance', 'name' => "Titan's Endurance", 'glyph' => '⛰', 'description' => 'Ascended resilience: the ground itself seems to hold you up. +16% defense, permanently.', 'tier' => 1, 'level_req' => 100, 'mp_cost' => 0, 'cooldown_seconds' => 0, 'cooldown_rounds' => 0, 'max_level' => 1, 'rank_levels' => [100], 'effect_json' => ['def_pct' => 16], 'class_scope' => 'warrior', 'requires_branch_key' => 'titan'],
            ['branch' => 'Warmonger', 'key' => 'warmongers_wrath', 'name' => "Warmonger's Wrath", 'glyph' => '💢', 'description' => 'Ascended fury unleashed in a single devastating blow that leaves them exposed. Costs MP.', 'tier' => 1, 'level_req' => 100, 'mp_cost' => 70, 'cooldown_seconds' => 20, 'cooldown_rounds' => 4, 'max_level' => 1, 'rank_levels' => [100], 'effect_json' => ['dmg_mult' => 2.2, 'applies_status' => [['effect' => 'vulnerable', 'target' => 'enemy', 'magnitude' => 20, 'rounds' => 2]]], 'class_scope' => 'warrior', 'requires_branch_key' => 'warmonger'],
            ['branch' => 'Bloodfury', 'key' => 'bloodfury_strike', 'name' => 'Bloodfury Strike', 'glyph' => '🩸', 'description' => 'An apex strike that opens a wound bleeding for several rounds. Costs MP.', 'tier' => 1, 'level_req' => 150, 'mp_cost' => 85, 'cooldown_seconds' => 24, 'cooldown_rounds' => 4, 'max_level' => 1, 'rank_levels' => [150], 'effect_json' => ['dmg_mult' => 2.6, 'applies_status' => [['effect' => 'burn', 'target' => 'enemy', 'magnitude' => 140, 'rounds' => 3]]], 'class_scope' => 'warrior', 'requires_branch_key' => 'bloodfury'],
            ['branch' => 'Aegis Warden', 'key' => 'aegis_stance', 'name' => 'Aegis Stance', 'glyph' => '🏯', 'description' => 'An apex defensive stance that no army can break. +20% defense, permanently.', 'tier' => 1, 'level_req' => 150, 'mp_cost' => 0, 'cooldown_seconds' => 0, 'cooldown_rounds' => 0, 'max_level' => 1, 'rank_levels' => [150], 'effect_json' => ['def_pct' => 20], 'class_scope' => 'warrior', 'requires_branch_key' => 'aegis_warden'],
            ['branch' => 'Godslayer', 'key' => 'godslaying_blow', 'name' => 'Godslaying Blow', 'glyph' => '🔱', 'description' => 'A transcendent strike said to have felled things that shouldn\'t die, staggering whatever survives it. Costs MP.', 'tier' => 1, 'level_req' => 200, 'mp_cost' => 100, 'cooldown_seconds' => 28, 'cooldown_rounds' => 5, 'max_level' => 1, 'rank_levels' => [200], 'effect_json' => ['dmg_mult' => 3.0, 'applies_status' => [['effect' => 'stun', 'target' => 'enemy', 'magnitude' => 1, 'rounds' => 1]]], 'class_scope' => 'warrior', 'requires_branch_key' => 'godslayer'],
            ['branch' => 'Immortal Bulwark', 'key' => 'immortal_stance', 'name' => 'Immortal Stance', 'glyph' => '🏛', 'description' => 'A transcendent wall between the world and its end. +25% defense, permanently.', 'tier' => 1, 'level_req' => 200, 'mp_cost' => 0, 'cooldown_seconds' => 0, 'cooldown_rounds' => 0, 'max_level' => 1, 'rank_levels' => [200], 'effect_json' => ['def_pct' => 25], 'class_scope' => 'warrior', 'requires_branch_key' => 'immortal_bulwark'],

            // Mage: shadowmage/necromancer/archmage/dreadlich/worldender (offense) vs. elementalist/stormweaver/voidcaller/stormsovereign/astral_oracle (utility).
            ['branch' => 'Shadow Mage', 'key' => 'shadow_lance', 'name' => 'Shadow Lance', 'glyph' => '🌑', 'description' => 'A spear of raw dark energy that corrodes their resistance. Costs MP.', 'tier' => 1, 'level_req' => 20, 'mp_cost' => 25, 'cooldown_seconds' => 8, 'cooldown_rounds' => 2, 'max_level' => 1, 'rank_levels' => [20], 'effect_json' => ['dmg_mult' => 1.4, 'applies_status' => [['effect' => 'vulnerable', 'target' => 'enemy', 'magnitude' => 15, 'rounds' => 2]]], 'class_scope' => 'mage', 'requires_branch_key' => 'shadowmage'],
            ['branch' => 'Elementalist', 'key' => 'elemental_ward', 'name' => 'Elemental Ward', 'glyph' => '🔥', 'description' => 'A shimmering ward woven from all three elements. +8% defense, permanently.', 'tier' => 1, 'level_req' => 20, 'mp_cost' => 0, 'cooldown_seconds' => 0, 'cooldown_rounds' => 0, 'max_level' => 1, 'rank_levels' => [20], 'effect_json' => ['def_pct' => 8], 'class_scope' => 'mage', 'requires_branch_key' => 'elementalist'],
            ['branch' => 'Necromancer', 'key' => 'life_drain', 'name' => 'Life Drain', 'glyph' => '💀', 'description' => 'Rip vitality from your foe, leaving their next attacks weaker. Costs MP.', 'tier' => 1, 'level_req' => 50, 'mp_cost' => 35, 'cooldown_seconds' => 14, 'cooldown_rounds' => 3, 'max_level' => 1, 'rank_levels' => [50], 'effect_json' => ['dmg_mult' => 1.8, 'applies_status' => [['effect' => 'weaken', 'target' => 'enemy', 'magnitude' => 15, 'rounds' => 2]]], 'class_scope' => 'mage', 'requires_branch_key' => 'necromancer'],
            ['branch' => 'Stormweaver', 'key' => 'storm_ward', 'name' => 'Storm Ward', 'glyph' => '🌪', 'description' => 'A crackling barrier woven from captured lightning. +12% defense, permanently.', 'tier' => 1, 'level_req' => 50, 'mp_cost' => 0, 'cooldown_seconds' => 0, 'cooldown_rounds' => 0, 'max_level' => 1, 'rank_levels' => [50], 'effect_json' => ['def_pct' => 12], 'class_scope' => 'mage', 'requires_branch_key' => 'stormweaver'],
            ['branch' => 'Archmage', 'key' => 'archmagi_surge', 'name' => 'Archmagi Surge', 'glyph' => '✨', 'description' => "Channel your ascended mastery into one devastating, defense-cracking burst. Costs MP.", 'tier' => 1, 'level_req' => 100, 'mp_cost' => 70, 'cooldown_seconds' => 20, 'cooldown_rounds' => 4, 'max_level' => 1, 'rank_levels' => [100], 'effect_json' => ['dmg_mult' => 2.2, 'applies_status' => [['effect' => 'vulnerable', 'target' => 'enemy', 'magnitude' => 25, 'rounds' => 2]]], 'class_scope' => 'mage', 'requires_branch_key' => 'archmage'],
            ['branch' => 'Voidcaller', 'key' => 'void_step', 'name' => 'Void Step', 'glyph' => '🕳', 'description' => 'Slip half out of reality to blunt the next blow. +16% defense, permanently.', 'tier' => 1, 'level_req' => 100, 'mp_cost' => 0, 'cooldown_seconds' => 0, 'cooldown_rounds' => 0, 'max_level' => 1, 'rank_levels' => [100], 'effect_json' => ['def_pct' => 16], 'class_scope' => 'mage', 'requires_branch_key' => 'voidcaller'],
            ['branch' => 'Dreadlich', 'key' => 'dread_nova', 'name' => 'Dread Nova', 'glyph' => '☠', 'description' => 'An apex spell paid for in pieces of the self: it weakens them badly, but leaves you exposed too. Costs MP.', 'tier' => 1, 'level_req' => 150, 'mp_cost' => 85, 'cooldown_seconds' => 24, 'cooldown_rounds' => 4, 'max_level' => 1, 'rank_levels' => [150], 'effect_json' => ['dmg_mult' => 2.6, 'applies_status' => [['effect' => 'weaken', 'target' => 'enemy', 'magnitude' => 20, 'rounds' => 2], ['effect' => 'vulnerable', 'target' => 'self', 'magnitude' => 10, 'rounds' => 1]]], 'class_scope' => 'mage', 'requires_branch_key' => 'dreadlich'],
            ['branch' => 'Stormsovereign', 'key' => 'sovereign_gale', 'name' => 'Sovereign Gale', 'glyph' => '⛈', 'description' => 'Command the storm to shield you as an extension of will. +20% defense, permanently.', 'tier' => 1, 'level_req' => 150, 'mp_cost' => 0, 'cooldown_seconds' => 0, 'cooldown_rounds' => 0, 'max_level' => 1, 'rank_levels' => [150], 'effect_json' => ['def_pct' => 20], 'class_scope' => 'mage', 'requires_branch_key' => 'stormsovereign'],
            ['branch' => 'Worldender', 'key' => 'world_ending_cast', 'name' => 'World-Ending Cast', 'glyph' => '🌌', 'description' => "A transcendent spell that rewrites what's possible, stunning and exposing whatever remains. Costs MP.", 'tier' => 1, 'level_req' => 200, 'mp_cost' => 100, 'cooldown_seconds' => 28, 'cooldown_rounds' => 5, 'max_level' => 1, 'rank_levels' => [200], 'effect_json' => ['dmg_mult' => 3.0, 'applies_status' => [['effect' => 'stun', 'target' => 'enemy', 'magnitude' => 1, 'rounds' => 1], ['effect' => 'vulnerable', 'target' => 'enemy', 'magnitude' => 20, 'rounds' => 2]]], 'class_scope' => 'mage', 'requires_branch_key' => 'worldender'],
            ['branch' => 'Astral Oracle', 'key' => 'fated_ward', 'name' => 'Fated Ward', 'glyph' => '🔮', 'description' => 'Bend fate just enough to turn aside the next blow. +25% defense, permanently.', 'tier' => 1, 'level_req' => 200, 'mp_cost' => 0, 'cooldown_seconds' => 0, 'cooldown_rounds' => 0, 'max_level' => 1, 'rank_levels' => [200], 'effect_json' => ['def_pct' => 25], 'class_scope' => 'mage', 'requires_branch_key' => 'astral_oracle'],

            // Rogue: assassin/shadowblade/nightblade/bloodreaper/deathbringer (offense) vs. trickster/duskrunner/wraith/phantom_dancer/shadowveil_sovereign (evasion).
            ['branch' => 'Assassin', 'key' => 'assassinate', 'name' => 'Assassinate', 'glyph' => '🥷', 'description' => 'A precise strike aimed at the gap in their guard, leaving it wide open. Costs MP.', 'tier' => 1, 'level_req' => 20, 'mp_cost' => 25, 'cooldown_seconds' => 8, 'cooldown_rounds' => 2, 'max_level' => 1, 'rank_levels' => [20], 'effect_json' => ['dmg_mult' => 1.4, 'applies_status' => [['effect' => 'vulnerable', 'target' => 'enemy', 'magnitude' => 20, 'rounds' => 2]]], 'class_scope' => 'rogue', 'requires_branch_key' => 'assassin'],
            ['branch' => 'Trickster', 'key' => 'tricksters_veil', 'name' => "Trickster's Veil", 'glyph' => '🃏', 'description' => 'Never quite where they think you are. +8% defense, permanently.', 'tier' => 1, 'level_req' => 20, 'mp_cost' => 0, 'cooldown_seconds' => 0, 'cooldown_rounds' => 0, 'max_level' => 1, 'rank_levels' => [20], 'effect_json' => ['def_pct' => 8], 'class_scope' => 'rogue', 'requires_branch_key' => 'trickster'],
            ['branch' => 'Shadowblade', 'key' => 'killer_instinct', 'name' => 'Killer Instinct', 'glyph' => '🗡', 'description' => 'Everything your training taught you converges on one lethal opening, crippling their offense. Costs MP.', 'tier' => 1, 'level_req' => 50, 'mp_cost' => 35, 'cooldown_seconds' => 14, 'cooldown_rounds' => 3, 'max_level' => 1, 'rank_levels' => [50], 'effect_json' => ['dmg_mult' => 1.8, 'applies_status' => [['effect' => 'weaken', 'target' => 'enemy', 'magnitude' => 15, 'rounds' => 2]]], 'class_scope' => 'rogue', 'requires_branch_key' => 'shadowblade'],
            ['branch' => 'Duskrunner', 'key' => 'duskrunners_step', 'name' => "Duskrunner's Step", 'glyph' => '🌆', 'description' => 'Built entirely around never being where the blow lands. +12% defense, permanently.', 'tier' => 1, 'level_req' => 50, 'mp_cost' => 0, 'cooldown_seconds' => 0, 'cooldown_rounds' => 0, 'max_level' => 1, 'rank_levels' => [50], 'effect_json' => ['def_pct' => 12], 'class_scope' => 'rogue', 'requires_branch_key' => 'duskrunner'],
            ['branch' => 'Nightblade', 'key' => 'nightblade_strike', 'name' => 'Nightblade Strike', 'glyph' => '🌒', 'description' => 'Ascended precision: your blade finds every opening, and leaves them wide open for more. Costs MP.', 'tier' => 1, 'level_req' => 100, 'mp_cost' => 70, 'cooldown_seconds' => 20, 'cooldown_rounds' => 4, 'max_level' => 1, 'rank_levels' => [100], 'effect_json' => ['dmg_mult' => 2.2, 'applies_status' => [['effect' => 'vulnerable', 'target' => 'enemy', 'magnitude' => 25, 'rounds' => 2]]], 'class_scope' => 'rogue', 'requires_branch_key' => 'nightblade'],
            ['branch' => 'Wraith', 'key' => 'wraithform', 'name' => 'Wraithform', 'glyph' => '👻', 'description' => 'Half-phase between worlds to let a blow pass through. +16% defense, permanently.', 'tier' => 1, 'level_req' => 100, 'mp_cost' => 0, 'cooldown_seconds' => 0, 'cooldown_rounds' => 0, 'max_level' => 1, 'rank_levels' => [100], 'effect_json' => ['def_pct' => 16], 'class_scope' => 'rogue', 'requires_branch_key' => 'wraith'],
            ['branch' => 'Bloodreaper', 'key' => 'reap', 'name' => 'Reap', 'glyph' => '🔪', 'description' => 'An apex killer trades safety for a near-guaranteed kill, badly exposing the target. Costs MP.', 'tier' => 1, 'level_req' => 150, 'mp_cost' => 85, 'cooldown_seconds' => 24, 'cooldown_rounds' => 4, 'max_level' => 1, 'rank_levels' => [150], 'effect_json' => ['dmg_mult' => 2.6, 'applies_status' => [['effect' => 'vulnerable', 'target' => 'enemy', 'magnitude' => 25, 'rounds' => 2], ['effect' => 'vulnerable', 'target' => 'self', 'magnitude' => 12, 'rounds' => 1]]], 'class_scope' => 'rogue', 'requires_branch_key' => 'bloodreaper'],
            ['branch' => 'Phantom Dancer', 'key' => 'phantom_step', 'name' => 'Phantom Step', 'glyph' => '🎭', 'description' => 'An apex evader who is barely present at all. +20% defense, permanently.', 'tier' => 1, 'level_req' => 150, 'mp_cost' => 0, 'cooldown_seconds' => 0, 'cooldown_rounds' => 0, 'max_level' => 1, 'rank_levels' => [150], 'effect_json' => ['def_pct' => 20], 'class_scope' => 'rogue', 'requires_branch_key' => 'phantom_dancer'],
            ['branch' => 'Deathbringer', 'key' => 'deathmark', 'name' => 'Deathmark', 'glyph' => '🖤', 'description' => 'A transcendent strike that marks its target for death, staggered and taking far more damage. Costs MP.', 'tier' => 1, 'level_req' => 200, 'mp_cost' => 100, 'cooldown_seconds' => 28, 'cooldown_rounds' => 5, 'max_level' => 1, 'rank_levels' => [200], 'effect_json' => ['dmg_mult' => 3.0, 'applies_status' => [['effect' => 'vulnerable', 'target' => 'enemy', 'magnitude' => 40, 'rounds' => 2], ['effect' => 'stun', 'target' => 'enemy', 'magnitude' => 1, 'rounds' => 1]]], 'class_scope' => 'rogue', 'requires_branch_key' => 'deathbringer'],
            ['branch' => 'Shadowveil Sovereign', 'key' => 'shadowveil', 'name' => 'Shadowveil', 'glyph' => '🕶', 'description' => 'Rule the space between strikes. +25% defense, permanently.', 'tier' => 1, 'level_req' => 200, 'mp_cost' => 0, 'cooldown_seconds' => 0, 'cooldown_rounds' => 0, 'max_level' => 1, 'rank_levels' => [200], 'effect_json' => ['def_pct' => 25], 'class_scope' => 'rogue', 'requires_branch_key' => 'shadowveil_sovereign'],

            // Ranger: hunter/sharpshooter/stormcaller/windrunner/skysovereign (offense) vs. beastmaster/pathfinder/beastlord/wildwarden/primal_avatar (utility).
            ['branch' => 'Hunter', 'key' => 'huntersmark', 'name' => "Hunter's Mark", 'glyph' => '🏹', 'description' => 'Mark your prey and loose a killing shot: marked prey takes more from every hit that follows. Costs MP.', 'tier' => 1, 'level_req' => 20, 'mp_cost' => 25, 'cooldown_seconds' => 8, 'cooldown_rounds' => 2, 'max_level' => 1, 'rank_levels' => [20], 'effect_json' => ['dmg_mult' => 1.4, 'applies_status' => [['effect' => 'vulnerable', 'target' => 'enemy', 'magnitude' => 20, 'rounds' => 3]]], 'class_scope' => 'ranger', 'requires_branch_key' => 'hunter'],
            // Reworked from a permanent passive into an active support skill (per the user's own example:
            // "Bonded Guard, your companion gains 50% armor and aggros all attacks") — a companion has no
            // defense stat of its own to shred/buff (see TamedCompanion::effectiveDef()'s comment on that),
            // so "50% armor" is realized as a flat shield instead; taunt is read by
            // CombatService::chooseEnemyTarget(), which always picks a taunting companion as the enemy's
            // sole target for as long as the taunt lasts.
            ['branch' => 'Beastmaster', 'key' => 'bonded_guard', 'name' => 'Bonded Guard', 'glyph' => '🐾', 'description' => 'Your companion braces for impact, gaining a protective shield and drawing every attack for a few rounds. Costs MP.', 'tier' => 1, 'level_req' => 20, 'mp_cost' => 20, 'cooldown_seconds' => 16, 'cooldown_rounds' => 4, 'max_level' => 1, 'rank_levels' => [20], 'effect_json' => ['dmg_mult' => 0, 'applies_status' => [['effect' => 'shield', 'target' => 'companion', 'magnitude' => 200, 'rounds' => 3], ['effect' => 'taunt', 'target' => 'companion', 'magnitude' => 1, 'rounds' => 3]]], 'class_scope' => 'ranger', 'requires_branch_key' => 'beastmaster'],
            ['branch' => 'Sharpshooter', 'key' => 'trueshot', 'name' => 'Trueshot', 'glyph' => '🎯', 'description' => 'A trained shot that never misses its mark, rattling their offense. Costs MP.', 'tier' => 1, 'level_req' => 50, 'mp_cost' => 35, 'cooldown_seconds' => 14, 'cooldown_rounds' => 3, 'max_level' => 1, 'rank_levels' => [50], 'effect_json' => ['dmg_mult' => 1.8, 'applies_status' => [['effect' => 'weaken', 'target' => 'enemy', 'magnitude' => 15, 'rounds' => 2]]], 'class_scope' => 'ranger', 'requires_branch_key' => 'sharpshooter'],
            ['branch' => 'Pathfinder', 'key' => 'pathfinders_grit', 'name' => "Pathfinder's Grit", 'glyph' => '🧭', 'description' => 'Built to outlast anything through sheer resilience. +12% defense, permanently.', 'tier' => 1, 'level_req' => 50, 'mp_cost' => 0, 'cooldown_seconds' => 0, 'cooldown_rounds' => 0, 'max_level' => 1, 'rank_levels' => [50], 'effect_json' => ['def_pct' => 12], 'class_scope' => 'ranger', 'requires_branch_key' => 'pathfinder'],
            ['branch' => 'Stormcaller', 'key' => 'storm_volley', 'name' => 'Storm Volley', 'glyph' => '⚡', 'description' => 'Ascended mastery calls down the storm itself, slowing whatever it strikes. Costs MP.', 'tier' => 1, 'level_req' => 100, 'mp_cost' => 70, 'cooldown_seconds' => 20, 'cooldown_rounds' => 4, 'max_level' => 1, 'rank_levels' => [100], 'effect_json' => ['dmg_mult' => 2.2, 'applies_status' => [['effect' => 'slow', 'target' => 'enemy', 'magnitude' => 15, 'rounds' => 2]]], 'class_scope' => 'ranger', 'requires_branch_key' => 'stormcaller'],
            ['branch' => 'Beastlord', 'key' => 'pack_tactics', 'name' => 'Pack Tactics', 'glyph' => '🐺', 'description' => 'A whole pack, not just one companion, watches your back. +16% defense, permanently.', 'tier' => 1, 'level_req' => 100, 'mp_cost' => 0, 'cooldown_seconds' => 0, 'cooldown_rounds' => 0, 'max_level' => 1, 'rank_levels' => [100], 'effect_json' => ['def_pct' => 16], 'class_scope' => 'ranger', 'requires_branch_key' => 'beastlord'],
            ['branch' => 'Windrunner', 'key' => 'windrunners_volley', 'name' => "Windrunner's Volley", 'glyph' => '🌬', 'description' => 'An apex shot too fast and precise to counter, the same speed makes you harder to hit back. Costs MP.', 'tier' => 1, 'level_req' => 150, 'mp_cost' => 85, 'cooldown_seconds' => 24, 'cooldown_rounds' => 4, 'max_level' => 1, 'rank_levels' => [150], 'effect_json' => ['dmg_mult' => 2.6, 'applies_status' => [['effect' => 'evasion', 'target' => 'self', 'magnitude' => 15, 'rounds' => 2]]], 'class_scope' => 'ranger', 'requires_branch_key' => 'windrunner'],
            ['branch' => 'Wildwarden', 'key' => 'wildwardens_bond', 'name' => "Wildwarden's Bond", 'glyph' => '🌳', 'description' => 'A bond with nature that borders on unbreakable. +20% defense, permanently.', 'tier' => 1, 'level_req' => 150, 'mp_cost' => 0, 'cooldown_seconds' => 0, 'cooldown_rounds' => 0, 'max_level' => 1, 'rank_levels' => [150], 'effect_json' => ['def_pct' => 20], 'class_scope' => 'ranger', 'requires_branch_key' => 'wildwarden'],
            // "Remove all armor for 3 rounds against 1 enemy" (user's own example) — monsters have no def
            // stat to strip (see Monster model), so the mechanical equivalent is a heavy `vulnerable`
            // stack for the same 3 rounds: it takes far more from every hit while it lasts, same outcome.
            ['branch' => 'Skysovereign', 'key' => 'sky_ending_shot', 'name' => 'Sky-Ending Shot', 'glyph' => '🦅', 'description' => 'A transcendent shot commanding the battlefield from above, stripping their defenses bare for 3 rounds. Costs MP.', 'tier' => 1, 'level_req' => 200, 'mp_cost' => 100, 'cooldown_seconds' => 28, 'cooldown_rounds' => 5, 'max_level' => 1, 'rank_levels' => [200], 'effect_json' => ['dmg_mult' => 3.0, 'applies_status' => [['effect' => 'vulnerable', 'target' => 'enemy', 'magnitude' => 50, 'rounds' => 3]]], 'class_scope' => 'ranger', 'requires_branch_key' => 'skysovereign'],
            ['branch' => 'Primal Avatar', 'key' => 'primal_bond', 'name' => 'Primal Bond', 'glyph' => '🐻', 'description' => 'Become one with the wild itself. +25% defense, permanently.', 'tier' => 1, 'level_req' => 200, 'mp_cost' => 0, 'cooldown_seconds' => 0, 'cooldown_rounds' => 0, 'max_level' => 1, 'rank_levels' => [200], 'effect_json' => ['def_pct' => 25], 'class_scope' => 'ranger', 'requires_branch_key' => 'primal_avatar'],
        ];

        // Every signature branch skill above is being promoted from a single flat unlock into the
        // CAPSTONE of the LEFT arm of its own full 9-node tree (trunk x2 -> hub/branch-point -> LEFT arm
        // x3 vs RIGHT arm x3 — see treeNodes()). It also picks up real rank depth (max_level 1 -> 3,
        // scaling exactly like every other multi-rank skill via SkillService::rankMultiplier) instead of
        // being a single flat unlock. `tier` is now purely a display-order hint within the branch —
        // actual gating is prereq_skill_key-based (see CharacterController::unlockSkill()), because the
        // LEFT and RIGHT arms branch off the SAME hub in parallel and a tier-only lookup can't tell which
        // sibling a node actually depends on. Any character who already owns a capstone keeps it (matched
        // by `key`, not `tier`) — only characters who haven't unlocked it yet see the new hub prerequisite.
        foreach ($skills as &$skill) {
            if (isset($skill['requires_branch_key'])) {
                $skill['tier'] = 6;
                $skill['node_slot'] = 'left_cap';
                $skill['prereq_skill_key'] = "{$skill['requires_branch_key']}_left_2";
                $skill['max_level'] = 3;
                // Deferred 5 levels past the branch's own tier — see treeNodes()'s matching offset for
                // right_cap — so the flagship capstone lands after the rest of the tree (trunk/hub/arm),
                // instead of every one of the tree's 9 nodes unlocking in the same instant.
                $skill['level_req'] += 5;
                $skill['rank_levels'] = [$skill['level_req'], $skill['level_req'] + 15, $skill['level_req'] + 30];
                // Display-only tag (never read by combat math — see StatusEffectService/CombatService,
                // neither look at this key) naming the whole LEFT arm for the UI badge SkillsPage.vue
                // shows next to it, e.g. "DRAIN" — see ARM_WORDS above.
                $skill['effect_json']['arm_path_name'] = self::ARM_WORDS[$skill['requires_branch_key']]['left'][0];
            }
        }
        unset($skill);

        $skills = array_merge($skills, $this->treeNodes());

        $keys = collect($skills)->pluck('key');
        Skill::whereNotIn('key', $keys)->delete();

        foreach ($skills as $skill) {
            Skill::updateOrCreate(['key' => $skill['key']], $skill);
        }
    }

    /** The full 9-node tree for each of the 40 signature branches: two trunk passives, a hub/"branch
     * point" passive, then a LEFT arm (2 lead-in passives + the branch's existing skill as its capstone)
     * and a RIGHT arm (2 lead-in passives + a brand-new alternate capstone) — mirroring the imported
     * "Solyx Skill Tree v3" design's trunk -> hub -> left/right-arm shape exactly. Committing to either
     * arm's first node (see CharacterController::unlockSkill()'s mutual-exclusion check) permanently
     * locks the other arm until a respec — "one side each" per gate, same as the design's own copy.
     *
     * The RIGHT arm is a genuinely new alternate path, not a filler stat stick: branches whose dominant
     * flavor is offense (atk_pct) get a SUSTAIN arm (a softer nuke that also mends you over time) —
     * trading some burst for survivability; every other branch (defense/evasion/utility-flavored) gets a
     * SWIFT arm (a small strike that also raises your own dodge) — a genuinely new ACTIVE ability for
     * branches that were previously 100% passive.
     */
    private function treeNodes(): array
    {
        // [branch label, key prefix, class, branch key, tier level req, glyph]
        $branches = [
            ['Berserker', 'berserker', 'warrior', 'berserker', 20, '💢'],
            ['Guardian', 'guardian', 'warrior', 'guardian', 20, '🛡'],
            ['Warlord', 'warlord', 'warrior', 'warlord', 50, '👑'],
            ['Vanguard', 'vanguard', 'warrior', 'vanguard', 50, '🔰'],
            ['Warmonger', 'warmonger', 'warrior', 'warmonger', 100, '💢'],
            ['Titan', 'titan', 'warrior', 'titan', 100, '⛰'],
            ['Bloodfury', 'bloodfury', 'warrior', 'bloodfury', 150, '🩸'],
            ['Aegis Warden', 'aegis_warden', 'warrior', 'aegis_warden', 150, '🏯'],
            ['Godslayer', 'godslayer', 'warrior', 'godslayer', 200, '🔱'],
            ['Immortal Bulwark', 'immortal_bulwark', 'warrior', 'immortal_bulwark', 200, '🏛'],

            ['Shadow Mage', 'shadowmage', 'mage', 'shadowmage', 20, '🌑'],
            ['Elementalist', 'elementalist', 'mage', 'elementalist', 20, '🔥'],
            ['Necromancer', 'necromancer', 'mage', 'necromancer', 50, '💀'],
            ['Stormweaver', 'stormweaver', 'mage', 'stormweaver', 50, '🌪'],
            ['Archmage', 'archmage', 'mage', 'archmage', 100, '✨'],
            ['Voidcaller', 'voidcaller', 'mage', 'voidcaller', 100, '🕳'],
            ['Dreadlich', 'dreadlich', 'mage', 'dreadlich', 150, '☠'],
            ['Stormsovereign', 'stormsovereign', 'mage', 'stormsovereign', 150, '⛈'],
            ['Worldender', 'worldender', 'mage', 'worldender', 200, '🌌'],
            ['Astral Oracle', 'astral_oracle', 'mage', 'astral_oracle', 200, '🔮'],

            ['Assassin', 'assassin', 'rogue', 'assassin', 20, '🥷'],
            ['Trickster', 'trickster', 'rogue', 'trickster', 20, '🃏'],
            ['Shadowblade', 'shadowblade', 'rogue', 'shadowblade', 50, '🗡'],
            ['Duskrunner', 'duskrunner', 'rogue', 'duskrunner', 50, '🌆'],
            ['Nightblade', 'nightblade', 'rogue', 'nightblade', 100, '🌒'],
            ['Wraith', 'wraith', 'rogue', 'wraith', 100, '👻'],
            ['Bloodreaper', 'bloodreaper', 'rogue', 'bloodreaper', 150, '🔪'],
            ['Phantom Dancer', 'phantom_dancer', 'rogue', 'phantom_dancer', 150, '🎭'],
            ['Deathbringer', 'deathbringer', 'rogue', 'deathbringer', 200, '🖤'],
            ['Shadowveil Sovereign', 'shadowveil_sovereign', 'rogue', 'shadowveil_sovereign', 200, '🕶'],

            ['Hunter', 'hunter', 'ranger', 'hunter', 20, '🏹'],
            ['Beastmaster', 'beastmaster', 'ranger', 'beastmaster', 20, '🐾'],
            ['Sharpshooter', 'sharpshooter', 'ranger', 'sharpshooter', 50, '🎯'],
            ['Pathfinder', 'pathfinder', 'ranger', 'pathfinder', 50, '🧭'],
            ['Stormcaller', 'stormcaller', 'ranger', 'stormcaller', 100, '⚡'],
            ['Beastlord', 'beastlord', 'ranger', 'beastlord', 100, '🐺'],
            ['Windrunner', 'windrunner', 'ranger', 'windrunner', 150, '🌬'],
            ['Wildwarden', 'wildwarden', 'ranger', 'wildwarden', 150, '🌳'],
            ['Skysovereign', 'skysovereign', 'ranger', 'skysovereign', 200, '🦅'],
            ['Primal Avatar', 'primal_avatar', 'ranger', 'primal_avatar', 200, '🐻'],
        ];

        // Each trunk node's stat/name is derived straight from that SAME branch's own
        // Character::BRANCH_BONUSES entry (the bonus already granted just for picking the branch) —
        // so a crit/dodge-flavored branch like Elementalist gets crit/dodge trunk nodes, not a generic
        // def_pct filler, and every branch's tree actually reads as more of what that branch already is
        // rather than every branch converging on the same two stats.
        // `name`/`name2` — a branch with two distinct positive stats uses each stat's own `name`; a
        // branch with only one (e.g. Guardian: def_pct alone) still gets two real ranks of progression,
        // so trunk_2 falls back to `name2` rather than repeating trunk_1's exact display name.
        $statFlavor = [
            'atk_pct' => ['label' => 'ATK', 'name' => 'Ferocity', 'name2' => 'Momentum'],
            'def_pct' => ['label' => 'DEF', 'name' => 'Resolve', 'name2' => 'Discipline'],
            'hp_pct' => ['label' => 'Max HP', 'name' => 'Vitality', 'name2' => 'Endurance'],
            'mp_pct' => ['label' => 'Max MP', 'name' => 'Focus', 'name2' => 'Clarity'],
            'crit_chance_flat' => ['label' => 'Crit Chance', 'name' => 'Precision', 'name2' => 'Instinct'],
            'dodge_flat' => ['label' => 'Dodge Chance', 'name' => 'Reflexes', 'name2' => 'Evasion'],
            'crit_damage_pct' => ['label' => 'Crit Damage', 'name' => 'Brutality', 'name2' => 'Savagery'],
            'luck_flat' => ['label' => 'Luck', 'name' => 'Fortune', 'name2' => 'Providence'],
        ];
        // The hub/"branch point" node grants a THIRD flavor stat instead of just repeating trunk_1's own
        // stat at half strength — every branch's tree previously converged on only 2-3 distinct stats
        // total (stat1 alone landed on trunk_1, hub, AND left_1), reading as repetitive/"boring" even
        // once each branch's stats were made BRANCH_BONUSES-accurate. Offense-leaning branches (atk_pct
        // dominant) get Crit Damage; everyone else gets Luck — both genuinely useful, previously never
        // grantable from any skill anywhere in the game.
        $hubStatByPole = ['atk_pct' => 'crit_damage_pct', 'default' => 'luck_flat'];
        $hubBaseByTier = [20 => 2, 50 => 3, 100 => 5, 150 => 6, 200 => 8];

        // Flat, tier-scaled base magnitude for every new small node below (hub/left/right lead-ins) —
        // deliberately NOT derived from BRANCH_BONUSES like the trunk nodes are, since the archetype
        // stats a RIGHT arm grants (e.g. hp_pct/mp_pct for a pure-offense branch) often don't exist in
        // that branch's BRANCH_BONUSES entry at all.
        $baseByTier = [20 => 2, 50 => 3, 100 => 4, 150 => 5, 200 => 6];
        // mp_cost/cooldown_seconds/cooldown_rounds for the new RIGHT-arm active capstone, matching the
        // exact curve every offense signature skill above already uses at each tier.
        $activeCost = [
            20 => [25, 8, 2], 50 => [35, 14, 3], 100 => [70, 20, 4], 150 => [85, 24, 4], 200 => [100, 28, 5],
        ];
        // Reduced ~70% of the offense capstone dmg_mult curve (1.4/1.8/2.2/2.6/3.0) — the RIGHT arm's
        // active trades some of that burst for its sustain/evasion effect, so it's real but never simply
        // better than committing to a genuine offense branch's own LEFT arm.
        $altDmgMult = [20 => 1.0, 50 => 1.3, 100 => 1.5, 150 => 1.8, 200 => 2.1];
        $mendMagnitude = [20 => 30, 50 => 50, 100 => 80, 150 => 120, 200 => 160];
        $evasionMagnitude = [20 => 6, 50 => 9, 100 => 12, 150 => 15, 200 => 18];

        $nodes = [];
        foreach ($branches as [$label, $prefix, $class, $branchKey, $levelReq, $glyph]) {
            $bonuses = Character::BRANCH_BONUSES[$branchKey] ?? [];
            // Only the branch's genuine upsides — a drawback like Berserker's def_pct:-4 is never
            // something a trunk node should reinforce.
            $positiveStats = array_keys(array_filter($bonuses, fn ($v) => $v > 0));
            // A branch with only one positive stat (e.g. Guardian: def_pct alone) still gets two trunk
            // nodes — both flavored the same, real, stat rather than a second unrelated one.
            $stat1 = $positiveStats[0] ?? 'atk_pct';
            $stat2 = $positiveStats[1] ?? $stat1;
            $base = $baseByTier[$levelReq];

            foreach ([1 => $stat1, 2 => $stat2] as $slot => $stat) {
                $flavor = $statFlavor[$stat];
                $name = ($slot === 2 && $stat2 === $stat1) ? $flavor['name2'] : $flavor['name'];
                $prereq = $slot === 1 ? null : "{$prefix}_trunk_1";
                // Each maxed trunk node is worth ~35% of what this branch's own passive bonus already
                // grants for that stat — real, on-theme progression, not a power-creep injection.
                $magnitude = round(($bonuses[$stat] ?? 2) * 0.35, 1);
                // Staggered by depth in the tree (trunk_1=+0, trunk_2=+1) instead of both trunk nodes
                // landing on the branch's own tier alongside everything else — see the depth offsets
                // through hub/left/right/cap below for the rest of this same staggering.
                $nodeLevel = $levelReq + ($slot - 1);
                $nodes[] = [
                    'branch' => $label, 'key' => "{$prefix}_trunk_{$slot}", 'name' => "{$label} {$name}",
                    'glyph' => $glyph, 'description' => "Deepens your {$label} training. +{$flavor['label']}. Passive.",
                    'tier' => $slot, 'level_req' => $nodeLevel, 'mp_cost' => 0, 'cooldown_seconds' => 0, 'cooldown_rounds' => 0,
                    'max_level' => 3, 'rank_levels' => [$nodeLevel, $nodeLevel + 10, $nodeLevel + 20],
                    'effect_json' => [$stat => $magnitude], 'class_scope' => $class,
                    'requires_branch_key' => $branchKey, 'node_slot' => "trunk_{$slot}", 'prereq_skill_key' => $prereq,
                ];
            }

            // Hub / "branch point" — the single gate both arms fork from. Grants a third flavor stat
            // (Crit Damage or Luck — see $hubStatByPole above) rather than repeating stat1, so committing
            // through the hub adds something genuinely new instead of a third copy of trunk_1's own bonus.
            // Depth offset +2 — lands right after trunk_1(+0)/trunk_2(+1).
            $hubStat = $hubStatByPole[$stat1] ?? $hubStatByPole['default'];
            $hubFlavor = $statFlavor[$hubStat];
            $hubMagnitude = $hubBaseByTier[$levelReq];
            $hubLevel = $levelReq + 2;
            $nodes[] = [
                'branch' => $label, 'key' => "{$prefix}_hub", 'name' => "{$label} Focus",
                'glyph' => $glyph, 'description' => "The branch point: commit to either side of {$label} from here. +{$hubFlavor['label']}. Passive.",
                'tier' => 3, 'level_req' => $hubLevel, 'mp_cost' => 0, 'cooldown_seconds' => 0, 'cooldown_rounds' => 0,
                'max_level' => 3, 'rank_levels' => [$hubLevel, $hubLevel + 10, $hubLevel + 20],
                'effect_json' => [$hubStat => $hubMagnitude], 'class_scope' => $class,
                'requires_branch_key' => $branchKey, 'node_slot' => 'hub', 'prereq_skill_key' => "{$prefix}_trunk_2",
            ];

            // LEFT arm — two lead-in passives (same 2 stats as the trunk, deeper investment) into the
            // branch's existing skill, now that skill's capstone (see the promotion loop above). Each
            // branch gets its own unique pair of node names (ARM_WORDS) instead of every branch sharing
            // the same 'Adept'/'Zealot' — `arm_path_name` also tags the whole arm for the UI badge.
            $armWords = self::ARM_WORDS[$branchKey];
            // Depth offset +3 (left_1) / +4 (left_2) — after hub(+2), before the capstone (+5, see the
            // promotion loop above).
            foreach ([1 => $stat1, 2 => $stat2] as $slot => $stat) {
                $flavor = $statFlavor[$stat];
                $prereq = $slot === 1 ? "{$prefix}_hub" : "{$prefix}_left_1";
                $nodeLevel = $levelReq + 2 + $slot;
                $nodes[] = [
                    'branch' => $label, 'key' => "{$prefix}_left_{$slot}", 'name' => "{$label} {$armWords['left'][$slot - 1]}",
                    'glyph' => $glyph, 'description' => "Commits deeper into {$label}. +{$flavor['label']}. Passive.",
                    'tier' => 3 + $slot, 'level_req' => $nodeLevel, 'mp_cost' => 0, 'cooldown_seconds' => 0, 'cooldown_rounds' => 0,
                    'max_level' => 4, 'rank_levels' => [$nodeLevel, $nodeLevel + 8, $nodeLevel + 16, $nodeLevel + 24],
                    'effect_json' => [$stat => $base, 'arm_path_name' => $armWords['left'][0]], 'class_scope' => $class,
                    'requires_branch_key' => $branchKey, 'node_slot' => "left_{$slot}", 'prereq_skill_key' => $prereq,
                ];
            }

            // RIGHT arm — a genuinely new alternate path. Offense-flavored branches (atk_pct dominant)
            // get SUSTAIN (softer nuke, mends you over time); everyone else gets SWIFT (a small strike
            // that also raises your own dodge) — a real new ACTIVE for branches that were pure passive.
            // capName is branch-unique (ARM_WORDS) even though the underlying mechanic (mend_over_time
            // vs. evasion) is still shared by every branch of the same offense/defense polarity.
            $isOffense = $stat1 === 'atk_pct';
            $capName = $armWords['rightCap'];
            $archetype = $isOffense
                ? ['glyph' => '💗', 'stat1' => 'hp_pct', 'name1' => 'Vigor', 'stat2' => 'mp_pct', 'name2' => 'Hunger', 'capName' => $capName, 'capDesc' => 'A softer strike that mends you over time. Costs MP.', 'status' => 'mend_over_time', 'magnitudes' => $mendMagnitude]
                : ['glyph' => '💨', 'stat1' => 'dodge_flat', 'name1' => 'Grace', 'stat2' => 'crit_chance_flat', 'name2' => 'Edge', 'capName' => $capName, 'capDesc' => 'A quick strike that leaves you harder to hit. Costs MP.', 'status' => 'evasion', 'magnitudes' => $evasionMagnitude];

            // Depth offset +3 (right_1) / +4 (right_2), mirroring the left arm above.
            foreach ([1 => $archetype['stat1'], 2 => $archetype['stat2']] as $slot => $stat) {
                $name = $slot === 1 ? $archetype['name1'] : $archetype['name2'];
                $prereq = $slot === 1 ? "{$prefix}_hub" : "{$prefix}_right_1";
                $nodeLevel = $levelReq + 2 + $slot;
                $nodes[] = [
                    'branch' => $label, 'key' => "{$prefix}_right_{$slot}", 'name' => "{$label} {$name}",
                    'glyph' => $archetype['glyph'], 'description' => "Commits {$label} to its alternate path. +{$statFlavor[$stat]['label']}. Passive.",
                    'tier' => 3 + $slot, 'level_req' => $nodeLevel, 'mp_cost' => 0, 'cooldown_seconds' => 0, 'cooldown_rounds' => 0,
                    'max_level' => 4, 'rank_levels' => [$nodeLevel, $nodeLevel + 8, $nodeLevel + 16, $nodeLevel + 24],
                    'effect_json' => [$stat => $base, 'arm_path_name' => $capName], 'class_scope' => $class,
                    'requires_branch_key' => $branchKey, 'node_slot' => "right_{$slot}", 'prereq_skill_key' => $prereq,
                ];
            }

            // Depth offset +5 — lands alongside the promoted LEFT capstone (see the promotion loop
            // above), the last node of the tree for either arm, instead of every node bursting at once.
            [$costMp, $cdSec, $cdRounds] = $activeCost[$levelReq];
            $capLevel = $levelReq + 5;
            $nodes[] = [
                'branch' => $label, 'key' => "{$prefix}_right_cap", 'name' => "{$label}'s {$archetype['capName']}",
                'glyph' => $archetype['glyph'], 'description' => "{$archetype['capDesc']}",
                'tier' => 6, 'level_req' => $capLevel, 'mp_cost' => $costMp, 'cooldown_seconds' => $cdSec, 'cooldown_rounds' => $cdRounds,
                'max_level' => 3, 'rank_levels' => [$capLevel, $capLevel + 15, $capLevel + 30],
                'effect_json' => [
                    'dmg_mult' => $altDmgMult[$levelReq],
                    'applies_status' => [['effect' => $archetype['status'], 'target' => 'self', 'magnitude' => $archetype['magnitudes'][$levelReq], 'rounds' => 2]],
                    'arm_path_name' => $capName,
                ],
                'class_scope' => $class, 'requires_branch_key' => $branchKey, 'node_slot' => 'right_cap', 'prereq_skill_key' => "{$prefix}_right_2",
            ];
        }

        return $nodes;
    }
}
