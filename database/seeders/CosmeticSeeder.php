<?php

namespace Database\Seeders;

use App\Models\CharacterCosmetic;
use App\Models\CharacterQuest;
use App\Models\Cosmetic;
use App\Models\Quest;
use Illuminate\Database\Seeder;

class CosmeticSeeder extends Seeder
{
    public function run(): void
    {
        $cosmetics = [
            // Titles — shown next to the character's name. Most are earned for free by completing the quest
            // of the same name (see 'unlock_quest_key', granted automatically on QuestController::claim());
            // a handful of high-prestige ones are gem-purchase only and never tied to a quest.
            ['key' => 'title_newcomer', 'type' => 'title', 'name' => 'Rising Adventurer', 'value' => 'Rising Adventurer', 'cost_gems' => 0, 'unlock_quest_key' => 'main_reach_level_10'],
            ['key' => 'title_battle_tested', 'type' => 'title', 'name' => 'Seasoned Fighter', 'value' => 'Seasoned Fighter', 'cost_gems' => 0, 'unlock_quest_key' => 'main_reach_level_20'],
            ['key' => 'title_gold_hoarder', 'type' => 'title', 'name' => 'Battle-Hardened', 'value' => 'Battle-Hardened', 'cost_gems' => 0, 'unlock_quest_key' => 'main_reach_level_30'],
            ['key' => 'title_dragon_slayer', 'type' => 'title', 'name' => 'The Ashfang Hunt', 'value' => 'The Ashfang Hunt', 'cost_gems' => 0, 'unlock_quest_key' => 'raid_ashfang_dragon'],
            ['key' => 'title_champion', 'type' => 'title', 'name' => 'Champion', 'value' => 'Champion', 'cost_gems' => 600, 'unlock_quest_key' => null],
            ['key' => 'title_legend', 'type' => 'title', 'name' => 'Legend of Solyx', 'value' => 'Legend of Solyx', 'cost_gems' => 1000, 'unlock_quest_key' => null],
            ['key' => 'title_wanderer', 'type' => 'title', 'name' => 'Wanderer', 'value' => 'Wanderer', 'cost_gems' => 0, 'unlock_quest_key' => 'daily_zone_hop'],
            ['key' => 'title_scavenger', 'type' => 'title', 'name' => "Forge's Apprentice", 'value' => "Forge's Apprentice", 'cost_gems' => 0, 'unlock_quest_key' => 'daily_craft_3'],
            ['key' => 'title_apprentice', 'type' => 'title', 'name' => "Forge's Journeyman", 'value' => "Forge's Journeyman", 'cost_gems' => 0, 'unlock_quest_key' => 'weekly_craft_10'],
            ['key' => 'title_ironclad', 'type' => 'title', 'name' => 'Ironclad', 'value' => 'Ironclad', 'cost_gems' => 125, 'unlock_quest_key' => null],
            ['key' => 'title_bladedancer', 'type' => 'title', 'name' => 'Bladedancer', 'value' => 'Bladedancer', 'cost_gems' => 150, 'unlock_quest_key' => null],
            ['key' => 'title_arcanist', 'type' => 'title', 'name' => 'Master of Sorcery', 'value' => 'Master of Sorcery', 'cost_gems' => 0, 'unlock_quest_key' => 'main_mage_chain_cast'],
            ['key' => 'title_beastslayer', 'type' => 'title', 'name' => 'Beastslayer', 'value' => 'Beastslayer', 'cost_gems' => 175, 'unlock_quest_key' => null],
            ['key' => 'title_pathfinder', 'type' => 'title', 'name' => 'Clear a Dungeon', 'value' => 'Clear a Dungeon', 'cost_gems' => 0, 'unlock_quest_key' => 'weekly_dungeon_clear'],
            ['key' => 'title_miner', 'type' => 'title', 'name' => 'Weekly Bounty', 'value' => 'Weekly Bounty', 'cost_gems' => 0, 'unlock_quest_key' => 'weekly_boss_hunter'],
            ['key' => 'title_lumberjack', 'type' => 'title', 'name' => 'Slay 5 Monsters', 'value' => 'Slay 5 Monsters', 'cost_gems' => 0, 'unlock_quest_key' => 'daily_slay_5'],
            ['key' => 'title_alchemist', 'type' => 'title', 'name' => 'Master of Marksmanship', 'value' => 'Master of Marksmanship', 'cost_gems' => 0, 'unlock_quest_key' => 'main_ranger_piercing_shot'],
            ['key' => 'title_blacksmith', 'type' => 'title', 'name' => 'Master of Warfare', 'value' => 'Master of Warfare', 'cost_gems' => 0, 'unlock_quest_key' => 'main_warrior_cleave'],
            ['key' => 'title_duelist', 'type' => 'title', 'name' => 'Master of Shadowcraft', 'value' => 'Master of Shadowcraft', 'cost_gems' => 0, 'unlock_quest_key' => 'main_rogue_shadow_strike'],
            ['key' => 'title_berserker', 'type' => 'title', 'name' => 'The Undying', 'value' => 'The Undying', 'cost_gems' => 0, 'unlock_quest_key' => 'main_warrior_undying'],
            ['key' => 'title_shadow', 'type' => 'title', 'name' => 'Death by a Thousand Cuts', 'value' => 'Death by a Thousand Cuts', 'cost_gems' => 0, 'unlock_quest_key' => 'main_rogue_thousand_cuts'],
            ['key' => 'title_stormcaller', 'type' => 'title', 'name' => 'Storm of Arrows', 'value' => 'Storm of Arrows', 'cost_gems' => 0, 'unlock_quest_key' => 'main_ranger_rain_of_arrows'],
            ['key' => 'title_warden', 'type' => 'title', 'name' => 'Herald of the Void', 'value' => 'Herald of the Void', 'cost_gems' => 0, 'unlock_quest_key' => 'main_mage_void_nova'],
            ['key' => 'title_bounty_hunter', 'type' => 'title', 'name' => 'The Final Sovereign', 'value' => 'The Final Sovereign', 'cost_gems' => 0, 'unlock_quest_key' => 'raid_void_sovereign'],
            ['key' => 'title_kingslayer', 'type' => 'title', 'name' => 'Kingslayer', 'value' => 'Kingslayer', 'cost_gems' => 450, 'unlock_quest_key' => null],
            ['key' => 'title_worldwalker', 'type' => 'title', 'name' => 'Depths of the Abyss', 'value' => 'Depths of the Abyss', 'cost_gems' => 0, 'unlock_quest_key' => 'raid_abyss_kraken'],
            ['key' => 'title_immortal', 'type' => 'title', 'name' => 'Immortal', 'value' => 'Immortal', 'cost_gems' => 550, 'unlock_quest_key' => null],
            ['key' => 'title_ascendant', 'type' => 'title', 'name' => 'Frostpeak Sentinel', 'value' => 'Frostpeak Sentinel', 'cost_gems' => 0, 'unlock_quest_key' => 'raid_ice_golem'],
            ['key' => 'title_voidwalker', 'type' => 'title', 'name' => 'Exorcism', 'value' => 'Exorcism', 'cost_gems' => 0, 'unlock_quest_key' => 'raid_dark_spirit'],
            ['key' => 'title_titan', 'type' => 'title', 'name' => 'Titan', 'value' => 'Titan', 'cost_gems' => 800, 'unlock_quest_key' => null],
            ['key' => 'title_mythic', 'type' => 'title', 'name' => 'Living Legend', 'value' => 'Living Legend', 'cost_gems' => 0, 'unlock_quest_key' => 'main_reach_level_60'],
            ['key' => 'title_initiate', 'type' => 'title', 'name' => 'Initiate', 'value' => 'Initiate', 'cost_gems' => 0, 'unlock_event' => 'tutorial_complete'],

            // Colors — applied to the character's name.
            ['key' => 'color_silver', 'type' => 'color', 'name' => 'Silver', 'value' => '#c0c0c0', 'cost_gems' => 25],
            ['key' => 'color_crimson', 'type' => 'color', 'name' => 'Crimson', 'value' => '#dc2626', 'cost_gems' => 150],
            ['key' => 'color_azure', 'type' => 'color', 'name' => 'Azure', 'value' => '#3b82f6', 'cost_gems' => 150],
            ['key' => 'color_emerald', 'type' => 'color', 'name' => 'Emerald', 'value' => '#10b981', 'cost_gems' => 150],
            ['key' => 'color_violet', 'type' => 'color', 'name' => 'Violet', 'value' => '#8b5cf6', 'cost_gems' => 200],
            ['key' => 'color_gold', 'type' => 'color', 'name' => 'Gold', 'value' => '#eab308', 'cost_gems' => 350],

            // Banners — the gradient behind the profile hero.
            ['key' => 'banner_slate', 'type' => 'banner', 'name' => 'Slate', 'value' => 'linear-gradient(150deg, #1a1013, #232323)', 'cost_gems' => 25],
            ['key' => 'banner_ember', 'type' => 'banner', 'name' => 'Ember', 'value' => 'linear-gradient(135deg, #7f1d1d, #f97316)', 'cost_gems' => 150],
            ['key' => 'banner_ocean', 'type' => 'banner', 'name' => 'Ocean', 'value' => 'linear-gradient(135deg, #0c4a6e, #06b6d4)', 'cost_gems' => 150],
            ['key' => 'banner_royal', 'type' => 'banner', 'name' => 'Royal', 'value' => 'linear-gradient(135deg, #312e81, #a855f7)', 'cost_gems' => 250],
            ['key' => 'banner_golden_hour', 'type' => 'banner', 'name' => 'Golden Hour', 'value' => 'linear-gradient(135deg, #78350f, #fbbf24)', 'cost_gems' => 350],
            ['key' => 'banner_void', 'type' => 'banner', 'name' => 'Void', 'value' => 'linear-gradient(135deg, #000000, #4c1d95)', 'cost_gems' => 500],

            // Icons — the glyph shown in the profile hero avatar.
            ['key' => 'icon_sword', 'type' => 'icon', 'name' => 'Sword', 'value' => '⚔️', 'cost_gems' => 25],
            ['key' => 'icon_shield', 'type' => 'icon', 'name' => 'Shield', 'value' => '🛡️', 'cost_gems' => 75],
            ['key' => 'icon_flame', 'type' => 'icon', 'name' => 'Flame', 'value' => '🔥', 'cost_gems' => 100],
            ['key' => 'icon_skull', 'type' => 'icon', 'name' => 'Skull', 'value' => '💀', 'cost_gems' => 100],
            ['key' => 'icon_bow', 'type' => 'icon', 'name' => 'Bow', 'value' => '🏹', 'cost_gems' => 125],
            ['key' => 'icon_wolf', 'type' => 'icon', 'name' => 'Wolf', 'value' => '🐺', 'cost_gems' => 150],
            ['key' => 'icon_lightning', 'type' => 'icon', 'name' => 'Lightning', 'value' => '⚡', 'cost_gems' => 150],
            ['key' => 'icon_star', 'type' => 'icon', 'name' => 'Star', 'value' => '⭐', 'cost_gems' => 175],
            ['key' => 'icon_dragon', 'type' => 'icon', 'name' => 'Dragon', 'value' => '🐉', 'cost_gems' => 400],
            ['key' => 'icon_wizard_hat', 'type' => 'icon', 'name' => 'Wizard Hat', 'value' => '🧙', 'cost_gems' => 200],
            ['key' => 'icon_crown', 'type' => 'icon', 'name' => 'Crown', 'value' => '👑', 'cost_gems' => 450],
            ['key' => 'icon_gem', 'type' => 'icon', 'name' => 'Gem', 'value' => '💎', 'cost_gems' => 300],
            ['key' => 'icon_demon', 'type' => 'icon', 'name' => 'Demon', 'value' => '👹', 'cost_gems' => 350],
            ['key' => 'icon_phoenix', 'type' => 'icon', 'name' => 'Phoenix', 'value' => '🔥🦅', 'cost_gems' => 600, 'unlock_quest_key' => null],
            ['key' => 'icon_ghost', 'type' => 'icon', 'name' => 'Ghost', 'value' => '👻', 'cost_gems' => 200],
        ];

        foreach ($cosmetics as $c) {
            Cosmetic::updateOrCreate(['key' => $c['key']], $c);
        }

        $this->backfillQuestTitles();
    }

    /** Titles gained a quest-unlock condition after some characters had already completed the matching
     * quest — grant those characters the title now rather than soft-locking them out of what they earned. */
    private function backfillQuestTitles(): void
    {
        Cosmetic::whereNotNull('unlock_quest_key')->get()->each(function (Cosmetic $cosmetic) {
            $questId = Quest::where('key', $cosmetic->unlock_quest_key)->value('id');
            if (! $questId) {
                return;
            }

            $characterIds = CharacterQuest::where('quest_id', $questId)->where('claimed', true)->pluck('character_id');
            foreach ($characterIds as $characterId) {
                CharacterCosmetic::firstOrCreate(['character_id' => $characterId, 'cosmetic_id' => $cosmetic->id]);
            }
        });
    }
}
