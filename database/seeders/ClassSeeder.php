<?php

namespace Database\Seeders;

use App\Models\ClassProgression;
use Illuminate\Database\Seeder;

class ClassSeeder extends Seeder
{
    /** 5-tier ladder (t20/t50/t100/t150/t200), 2 branches per class per tier — 40 rows total. Every tier
     * is mechanically real (see Character::BRANCH_BONUSES) and unlocks a signature skill (SkillSeeder)
     * plus a craftable specialty weapon/armor (ItemSeeder/RecipeSeeder), both gated on the exact branch
     * key via requires_branch_key. t50/t100 used to be t40/t60 with only 1 (flavor-only) branch each —
     * relabeled in place rather than duplicated, see the updateOrCreate match key below. */
    public function run(): void
    {
        $progressions = [
            // Warrior — offense pole (berserker → warlord → warmonger → bloodfury → godslayer) vs.
            // defense pole (guardian → vanguard → titan → aegis_warden → immortal_bulwark).
            ['base_class' => 'warrior', 'tier' => 't20', 'key' => 'berserker', 'name' => 'Berserker', 'glyph' => '🪓', 'description' => 'Trades defense for relentless offense.', 'level_cap' => 20],
            ['base_class' => 'warrior', 'tier' => 't20', 'key' => 'guardian', 'name' => 'Guardian', 'glyph' => '🛡', 'description' => 'A wall between allies and danger.', 'level_cap' => 20],
            ['base_class' => 'warrior', 'tier' => 't50', 'key' => 'warlord', 'name' => 'Warlord', 'glyph' => '👑', 'description' => 'A battlefield commander that buffs allies and cleaves foes.', 'level_cap' => 50],
            ['base_class' => 'warrior', 'tier' => 't50', 'key' => 'vanguard', 'name' => 'Vanguard', 'glyph' => '🔰', 'description' => 'A frontline bulwark who plants and holds the line.', 'level_cap' => 50],
            ['base_class' => 'warrior', 'tier' => 't100', 'key' => 'titan', 'name' => 'Titan', 'glyph' => '⛰', 'description' => 'Ascended warrior, immovable and unstoppable.', 'level_cap' => 100],
            ['base_class' => 'warrior', 'tier' => 't100', 'key' => 'warmonger', 'name' => 'Warmonger', 'glyph' => '💢', 'description' => 'Ascended berserker who turns battle-fury into raw devastation.', 'level_cap' => 100],
            ['base_class' => 'warrior', 'tier' => 't150', 'key' => 'bloodfury', 'name' => 'Bloodfury', 'glyph' => '🩸', 'description' => 'A frenzied apex warrior who bleeds power into every strike.', 'level_cap' => 150],
            ['base_class' => 'warrior', 'tier' => 't150', 'key' => 'aegis_warden', 'name' => 'Aegis Warden', 'glyph' => '🏯', 'description' => 'An apex defender whose stance no army can break.', 'level_cap' => 150],
            ['base_class' => 'warrior', 'tier' => 't200', 'key' => 'godslayer', 'name' => 'Godslayer', 'glyph' => '🔱', 'description' => "A transcendent warrior said to have felled things that shouldn't die.", 'level_cap' => 200],
            ['base_class' => 'warrior', 'tier' => 't200', 'key' => 'immortal_bulwark', 'name' => 'Immortal Bulwark', 'glyph' => '🏛', 'description' => 'A transcendent wall between the world and its end.', 'level_cap' => 200],

            // Mage — offense pole (shadowmage → necromancer → archmage → dreadlich → worldender) vs.
            // crit/utility pole (elementalist → stormweaver → voidcaller → stormsovereign → astral_oracle).
            ['base_class' => 'mage', 'tier' => 't20', 'key' => 'shadowmage', 'name' => 'Shadow Mage', 'glyph' => '🌑', 'description' => 'Bends dark energy into devastating bursts.', 'level_cap' => 20],
            ['base_class' => 'mage', 'tier' => 't20', 'key' => 'elementalist', 'name' => 'Elementalist', 'glyph' => '🔥', 'description' => 'Masters fire, ice and lightning.', 'level_cap' => 20],
            ['base_class' => 'mage', 'tier' => 't50', 'key' => 'necromancer', 'name' => 'Necromancer', 'glyph' => '💀', 'description' => 'A Mage profession that raises undead minions and drains life.', 'level_cap' => 50],
            ['base_class' => 'mage', 'tier' => 't50', 'key' => 'stormweaver', 'name' => 'Stormweaver', 'glyph' => '🌪', 'description' => 'A Mage profession weaving elemental crit into every cast.', 'level_cap' => 50],
            ['base_class' => 'mage', 'tier' => 't100', 'key' => 'archmage', 'name' => 'Archmage', 'glyph' => '✨', 'description' => 'Ascended mage wielding reality-bending power.', 'level_cap' => 100],
            ['base_class' => 'mage', 'tier' => 't100', 'key' => 'voidcaller', 'name' => 'Voidcaller', 'glyph' => '🕳', 'description' => 'Ascended elementalist who slips between moments to strike unseen.', 'level_cap' => 100],
            ['base_class' => 'mage', 'tier' => 't150', 'key' => 'dreadlich', 'name' => 'Dreadlich', 'glyph' => '☠', 'description' => 'An apex necromancer whose power costs pieces of the self.', 'level_cap' => 150],
            ['base_class' => 'mage', 'tier' => 't150', 'key' => 'stormsovereign', 'name' => 'Stormsovereign', 'glyph' => '⛈', 'description' => 'An apex elementalist commanding storms as an extension of will.', 'level_cap' => 150],
            ['base_class' => 'mage', 'tier' => 't200', 'key' => 'worldender', 'name' => 'Worldender', 'glyph' => '🌌', 'description' => "A transcendent archmage whose spells rewrite what's possible.", 'level_cap' => 200],
            ['base_class' => 'mage', 'tier' => 't200', 'key' => 'astral_oracle', 'name' => 'Astral Oracle', 'glyph' => '🔮', 'description' => 'A transcendent seer who bends fate as easily as flame.', 'level_cap' => 200],

            // Rogue — offense pole (assassin → shadowblade → nightblade → bloodreaper → deathbringer) vs.
            // evasion pole (trickster → duskrunner → wraith → phantom_dancer → shadowveil_sovereign).
            ['base_class' => 'rogue', 'tier' => 't20', 'key' => 'assassin', 'name' => 'Assassin', 'glyph' => '🥷', 'description' => 'Strikes from the shadows for massive burst.', 'level_cap' => 20],
            ['base_class' => 'rogue', 'tier' => 't20', 'key' => 'trickster', 'name' => 'Trickster', 'glyph' => '🃏', 'description' => 'Unpredictable, evasive, always one step ahead.', 'level_cap' => 20],
            ['base_class' => 'rogue', 'tier' => 't50', 'key' => 'shadowblade', 'name' => 'Shadowblade', 'glyph' => '🗡', 'description' => 'A Rogue profession specializing in critical execution.', 'level_cap' => 50],
            ['base_class' => 'rogue', 'tier' => 't50', 'key' => 'duskrunner', 'name' => 'Duskrunner', 'glyph' => '🌆', 'description' => 'A Rogue profession built entirely around never being hit.', 'level_cap' => 50],
            ['base_class' => 'rogue', 'tier' => 't100', 'key' => 'nightblade', 'name' => 'Nightblade', 'glyph' => '🌒', 'description' => 'Ascended assassin whose blade finds every opening.', 'level_cap' => 100],
            ['base_class' => 'rogue', 'tier' => 't100', 'key' => 'wraith', 'name' => 'Wraith', 'glyph' => '👻', 'description' => 'Ascended rogue, half-phased between worlds.', 'level_cap' => 100],
            ['base_class' => 'rogue', 'tier' => 't150', 'key' => 'bloodreaper', 'name' => 'Bloodreaper', 'glyph' => '🔪', 'description' => 'An apex killer who trades safety for guaranteed kills.', 'level_cap' => 150],
            ['base_class' => 'rogue', 'tier' => 't150', 'key' => 'phantom_dancer', 'name' => 'Phantom Dancer', 'glyph' => '🎭', 'description' => 'An apex evader who is barely present at all.', 'level_cap' => 150],
            ['base_class' => 'rogue', 'tier' => 't200', 'key' => 'deathbringer', 'name' => 'Deathbringer', 'glyph' => '🖤', 'description' => 'A transcendent assassin whose name ends fights before they start.', 'level_cap' => 200],
            ['base_class' => 'rogue', 'tier' => 't200', 'key' => 'shadowveil_sovereign', 'name' => 'Shadowveil Sovereign', 'glyph' => '🕶', 'description' => 'A transcendent wraith who rules the space between strikes.', 'level_cap' => 200],

            // Ranger — offense pole (hunter → sharpshooter → stormcaller → windrunner → skysovereign) vs.
            // companion/tank pole (beastmaster → pathfinder → beastlord → wildwarden → primal_avatar).
            ['base_class' => 'ranger', 'tier' => 't20', 'key' => 'hunter', 'name' => 'Hunter', 'glyph' => '🏹', 'description' => 'Tracks and takes down prey with precision.', 'level_cap' => 20],
            ['base_class' => 'ranger', 'tier' => 't20', 'key' => 'beastmaster', 'name' => 'Beastmaster', 'glyph' => '🐾', 'description' => 'Fights alongside a bonded companion.', 'level_cap' => 20],
            ['base_class' => 'ranger', 'tier' => 't50', 'key' => 'sharpshooter', 'name' => 'Sharpshooter', 'glyph' => '🎯', 'description' => 'A Ranger profession with unmatched critical range damage.', 'level_cap' => 50],
            ['base_class' => 'ranger', 'tier' => 't50', 'key' => 'pathfinder', 'name' => 'Pathfinder', 'glyph' => '🧭', 'description' => 'A Ranger profession that outlasts anything through sheer resilience.', 'level_cap' => 50],
            ['base_class' => 'ranger', 'tier' => 't100', 'key' => 'stormcaller', 'name' => 'Stormcaller', 'glyph' => '⚡', 'description' => 'Ascended ranger who commands the storm itself.', 'level_cap' => 100],
            ['base_class' => 'ranger', 'tier' => 't100', 'key' => 'beastlord', 'name' => 'Beastlord', 'glyph' => '🐺', 'description' => 'Ascended beastmaster commanding a whole pack, not just one companion.', 'level_cap' => 100],
            ['base_class' => 'ranger', 'tier' => 't150', 'key' => 'windrunner', 'name' => 'Windrunner', 'glyph' => '🌬', 'description' => 'An apex marksman too fast and precise to counter.', 'level_cap' => 150],
            ['base_class' => 'ranger', 'tier' => 't150', 'key' => 'wildwarden', 'name' => 'Wildwarden', 'glyph' => '🌳', 'description' => "An apex protector whose bond with nature borders on unbreakable.", 'level_cap' => 150],
            ['base_class' => 'ranger', 'tier' => 't200', 'key' => 'skysovereign', 'name' => 'Skysovereign', 'glyph' => '🦅', 'description' => 'A transcendent hunter who commands the battlefield from above.', 'level_cap' => 200],
            ['base_class' => 'ranger', 'tier' => 't200', 'key' => 'primal_avatar', 'name' => 'Primal Avatar', 'glyph' => '🐻', 'description' => 'A transcendent beastmaster who has become one with the wild itself.', 'level_cap' => 200],
        ];

        foreach ($progressions as $p) {
            // Matches on base_class+key only (not tier) so relabeling an existing row's tier/level_cap
            // (the t40→t50, t60→t100 relevel) updates it in place instead of leaving an orphaned row
            // under the old tier behind.
            ClassProgression::updateOrCreate(
                ['base_class' => $p['base_class'], 'key' => $p['key']],
                $p
            );
        }
    }
}
