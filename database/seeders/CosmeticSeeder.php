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
            // Rarity on quest/event titles reflects how far into the game the unlocking quest sits, not price.
            // 'category' buckets the Titles tab into sub-sections in the Customize UI: leveling, mastery
            // (class-specific "Master of X" chains), raid, daily_weekly, event, purchased.
            ['key' => 'title_newcomer', 'type' => 'title', 'name' => 'Rising Adventurer', 'value' => 'Rising Adventurer', 'rarity' => 'common', 'category' => 'leveling', 'cost_gems' => 0, 'unlock_quest_key' => 'main_reach_level_10'],
            ['key' => 'title_battle_tested', 'type' => 'title', 'name' => 'Seasoned Fighter', 'value' => 'Seasoned Fighter', 'rarity' => 'rare', 'category' => 'leveling', 'cost_gems' => 0, 'unlock_quest_key' => 'main_reach_level_20'],
            ['key' => 'title_gold_hoarder', 'type' => 'title', 'name' => 'Battle-Hardened', 'value' => 'Battle-Hardened', 'rarity' => 'rare', 'category' => 'leveling', 'cost_gems' => 0, 'unlock_quest_key' => 'main_reach_level_30'],
            ['key' => 'title_dragon_slayer', 'type' => 'title', 'name' => 'The Ashfang Hunt', 'value' => 'The Ashfang Hunt', 'rarity' => 'epic', 'category' => 'raid', 'cost_gems' => 0, 'unlock_quest_key' => 'raid_ashfang_dragon'],
            ['key' => 'title_champion', 'type' => 'title', 'name' => 'Champion', 'value' => 'Champion', 'rarity' => 'legendary', 'category' => 'purchased', 'cost_gems' => 600, 'unlock_quest_key' => null],
            ['key' => 'title_legend', 'type' => 'title', 'name' => 'Legend of Solyx', 'value' => 'Legend of Solyx', 'rarity' => 'mythic', 'category' => 'purchased', 'cost_gems' => 1000, 'unlock_quest_key' => null],
            ['key' => 'title_wanderer', 'type' => 'title', 'name' => 'Wanderer', 'value' => 'Wanderer', 'rarity' => 'common', 'category' => 'daily_weekly', 'cost_gems' => 0, 'unlock_quest_key' => 'daily_zone_hop'],
            ['key' => 'title_scavenger', 'type' => 'title', 'name' => "Forge's Apprentice", 'value' => "Forge's Apprentice", 'rarity' => 'common', 'category' => 'daily_weekly', 'cost_gems' => 0, 'unlock_quest_key' => 'daily_craft_3'],
            ['key' => 'title_apprentice', 'type' => 'title', 'name' => "Forge's Journeyman", 'value' => "Forge's Journeyman", 'rarity' => 'rare', 'category' => 'daily_weekly', 'cost_gems' => 0, 'unlock_quest_key' => 'weekly_craft_10'],
            ['key' => 'title_ironclad', 'type' => 'title', 'name' => 'Ironclad', 'value' => 'Ironclad', 'rarity' => 'rare', 'category' => 'purchased', 'cost_gems' => 125, 'unlock_quest_key' => null],
            ['key' => 'title_bladedancer', 'type' => 'title', 'name' => 'Bladedancer', 'value' => 'Bladedancer', 'rarity' => 'rare', 'category' => 'purchased', 'cost_gems' => 150, 'unlock_quest_key' => null],
            ['key' => 'title_arcanist', 'type' => 'title', 'name' => 'Master of Sorcery', 'value' => 'Master of Sorcery', 'rarity' => 'epic', 'category' => 'mastery', 'cost_gems' => 0, 'unlock_quest_key' => 'main_mage_chain_cast'],
            ['key' => 'title_beastslayer', 'type' => 'title', 'name' => 'Beastslayer', 'value' => 'Beastslayer', 'rarity' => 'rare', 'category' => 'purchased', 'cost_gems' => 175, 'unlock_quest_key' => null],
            ['key' => 'title_pathfinder', 'type' => 'title', 'name' => 'Clear a Dungeon', 'value' => 'Clear a Dungeon', 'rarity' => 'rare', 'category' => 'daily_weekly', 'cost_gems' => 0, 'unlock_quest_key' => 'weekly_dungeon_clear'],
            ['key' => 'title_miner', 'type' => 'title', 'name' => 'Weekly Bounty', 'value' => 'Weekly Bounty', 'rarity' => 'rare', 'category' => 'daily_weekly', 'cost_gems' => 0, 'unlock_quest_key' => 'weekly_boss_hunter'],
            ['key' => 'title_lumberjack', 'type' => 'title', 'name' => 'Slay 5 Monsters', 'value' => 'Slay 5 Monsters', 'rarity' => 'common', 'category' => 'daily_weekly', 'cost_gems' => 0, 'unlock_quest_key' => 'daily_slay_5'],
            ['key' => 'title_alchemist', 'type' => 'title', 'name' => 'Master of Marksmanship', 'value' => 'Master of Marksmanship', 'rarity' => 'epic', 'category' => 'mastery', 'cost_gems' => 0, 'unlock_quest_key' => 'main_ranger_piercing_shot'],
            ['key' => 'title_blacksmith', 'type' => 'title', 'name' => 'Master of Warfare', 'value' => 'Master of Warfare', 'rarity' => 'epic', 'category' => 'mastery', 'cost_gems' => 0, 'unlock_quest_key' => 'main_warrior_cleave'],
            ['key' => 'title_duelist', 'type' => 'title', 'name' => 'Master of Shadowcraft', 'value' => 'Master of Shadowcraft', 'rarity' => 'epic', 'category' => 'mastery', 'cost_gems' => 0, 'unlock_quest_key' => 'main_rogue_shadow_strike'],
            ['key' => 'title_berserker', 'type' => 'title', 'name' => 'The Undying', 'value' => 'The Undying', 'rarity' => 'epic', 'category' => 'mastery', 'cost_gems' => 0, 'unlock_quest_key' => 'main_warrior_undying'],
            ['key' => 'title_shadow', 'type' => 'title', 'name' => 'Death by a Thousand Cuts', 'value' => 'Death by a Thousand Cuts', 'rarity' => 'epic', 'category' => 'mastery', 'cost_gems' => 0, 'unlock_quest_key' => 'main_rogue_thousand_cuts'],
            ['key' => 'title_stormcaller', 'type' => 'title', 'name' => 'Storm of Arrows', 'value' => 'Storm of Arrows', 'rarity' => 'epic', 'category' => 'mastery', 'cost_gems' => 0, 'unlock_quest_key' => 'main_ranger_rain_of_arrows'],
            ['key' => 'title_warden', 'type' => 'title', 'name' => 'Herald of the Void', 'value' => 'Herald of the Void', 'rarity' => 'epic', 'category' => 'mastery', 'cost_gems' => 0, 'unlock_quest_key' => 'main_mage_void_nova'],
            ['key' => 'title_bounty_hunter', 'type' => 'title', 'name' => 'The Final Sovereign', 'value' => 'The Final Sovereign', 'rarity' => 'legendary', 'category' => 'raid', 'cost_gems' => 0, 'unlock_quest_key' => 'raid_void_sovereign'],
            ['key' => 'title_kingslayer', 'type' => 'title', 'name' => 'Kingslayer', 'value' => 'Kingslayer', 'rarity' => 'legendary', 'category' => 'purchased', 'cost_gems' => 450, 'unlock_quest_key' => null],
            ['key' => 'title_worldwalker', 'type' => 'title', 'name' => 'Depths of the Abyss', 'value' => 'Depths of the Abyss', 'rarity' => 'legendary', 'category' => 'raid', 'cost_gems' => 0, 'unlock_quest_key' => 'raid_abyss_kraken'],
            ['key' => 'title_immortal', 'type' => 'title', 'name' => 'Immortal', 'value' => 'Immortal', 'rarity' => 'legendary', 'category' => 'purchased', 'cost_gems' => 550, 'unlock_quest_key' => null],
            ['key' => 'title_ascendant', 'type' => 'title', 'name' => 'Frostpeak Sentinel', 'value' => 'Frostpeak Sentinel', 'rarity' => 'legendary', 'category' => 'raid', 'cost_gems' => 0, 'unlock_quest_key' => 'raid_ice_golem'],
            ['key' => 'title_voidwalker', 'type' => 'title', 'name' => 'Exorcism', 'value' => 'Exorcism', 'rarity' => 'legendary', 'category' => 'raid', 'cost_gems' => 0, 'unlock_quest_key' => 'raid_dark_spirit'],
            ['key' => 'title_titan', 'type' => 'title', 'name' => 'Titan', 'value' => 'Titan', 'rarity' => 'mythic', 'category' => 'purchased', 'cost_gems' => 800, 'unlock_quest_key' => null],
            ['key' => 'title_mythic', 'type' => 'title', 'name' => 'Living Legend', 'value' => 'Living Legend', 'rarity' => 'mythic', 'category' => 'leveling', 'cost_gems' => 0, 'unlock_quest_key' => 'main_reach_level_60'],
            ['key' => 'title_initiate', 'type' => 'title', 'name' => 'Initiate', 'value' => 'Initiate', 'rarity' => 'common', 'category' => 'event', 'cost_gems' => 0, 'unlock_event' => 'tutorial_complete'],

            // PvP season-exclusive titles — granted by `php artisan pvp:season-reset` (App\Console\Commands\PvpSeasonReset)
            // to whoever finishes Season 1 at Platinum/Diamond/Master. Keyed with the season number so a
            // future season can add new "_s2" rows without touching these (re-running season-reset stays idempotent).
            // is_dynamic: hidden from anyone who hasn't earned it (same rule as every later season's rows).
            ['key' => 'title_pvp_platinum_s1', 'type' => 'title', 'name' => 'Platinum Gladiator (S1)', 'value' => 'Platinum Gladiator', 'rarity' => 'legendary', 'category' => 'event', 'cost_gems' => 0, 'unlock_event' => 'pvp_season_1_platinum', 'is_dynamic' => true],
            ['key' => 'title_pvp_diamond_s1', 'type' => 'title', 'name' => 'Diamond Gladiator (S1)', 'value' => 'Diamond Gladiator', 'rarity' => 'legendary', 'category' => 'event', 'cost_gems' => 0, 'unlock_event' => 'pvp_season_1_diamond', 'is_dynamic' => true],
            ['key' => 'title_pvp_master_s1', 'type' => 'title', 'name' => 'Master Gladiator (S1)', 'value' => 'Master Gladiator', 'rarity' => 'mythic', 'category' => 'event', 'cost_gems' => 0, 'unlock_event' => 'pvp_season_1_master', 'is_dynamic' => true],

            // Colors — applied to the character's name.
            ['key' => 'color_silver', 'type' => 'color', 'name' => 'Silver', 'value' => '#c0c0c0', 'rarity' => 'common', 'cost_gems' => 25],
            ['key' => 'color_crimson', 'type' => 'color', 'name' => 'Crimson', 'value' => '#dc2626', 'rarity' => 'rare', 'cost_gems' => 150],
            ['key' => 'color_azure', 'type' => 'color', 'name' => 'Azure', 'value' => '#3b82f6', 'rarity' => 'rare', 'cost_gems' => 150],
            ['key' => 'color_emerald', 'type' => 'color', 'name' => 'Emerald', 'value' => '#10b981', 'rarity' => 'rare', 'cost_gems' => 150],
            ['key' => 'color_violet', 'type' => 'color', 'name' => 'Violet', 'value' => '#8b5cf6', 'rarity' => 'epic', 'cost_gems' => 200],
            // No longer gem-purchasable — gold is now exclusive to the Founder Beta Pack's name-color
            // reward (is_dynamic hides it from the catalog for anyone who doesn't already own it, same
            // as the other Founder cosmetics below; existing owners, if any, keep it either way).
            ['key' => 'color_gold', 'type' => 'color', 'name' => 'Gold', 'value' => '#eab308', 'rarity' => 'legendary', 'cost_gems' => 0, 'category' => 'founder', 'is_dynamic' => true, 'unlock_event' => 'founder_pack'],
            ['key' => 'color_rose', 'type' => 'color', 'name' => 'Rose', 'value' => '#f43f5e', 'rarity' => 'rare', 'cost_gems' => 175],
            ['key' => 'color_teal', 'type' => 'color', 'name' => 'Teal', 'value' => '#14b8a6', 'rarity' => 'rare', 'cost_gems' => 150],
            ['key' => 'color_obsidian', 'type' => 'color', 'name' => 'Obsidian', 'value' => '#52525b', 'rarity' => 'epic', 'cost_gems' => 225],
            ['key' => 'color_ice', 'type' => 'color', 'name' => 'Ice', 'value' => '#a5f3fc', 'rarity' => 'epic', 'cost_gems' => 250],
            ['key' => 'color_jade', 'type' => 'color', 'name' => 'Jade', 'value' => '#22c55e', 'rarity' => 'common', 'cost_gems' => 40],
            ['key' => 'color_amethyst', 'type' => 'color', 'name' => 'Amethyst', 'value' => '#a78bfa', 'rarity' => 'rare', 'cost_gems' => 160],
            ['key' => 'color_coral', 'type' => 'color', 'name' => 'Coral', 'value' => '#fb7185', 'rarity' => 'rare', 'cost_gems' => 160],
            ['key' => 'color_bronze', 'type' => 'color', 'name' => 'Bronze', 'value' => '#b45309', 'rarity' => 'rare', 'cost_gems' => 165],
            ['key' => 'color_cobalt', 'type' => 'color', 'name' => 'Cobalt', 'value' => '#1d4ed8', 'rarity' => 'epic', 'cost_gems' => 220],
            ['key' => 'color_magenta', 'type' => 'color', 'name' => 'Magenta', 'value' => '#d946ef', 'rarity' => 'epic', 'cost_gems' => 230],
            ['key' => 'color_platinum', 'type' => 'color', 'name' => 'Platinum', 'value' => '#e5e7eb', 'rarity' => 'legendary', 'cost_gems' => 375],
            ['key' => 'color_blood', 'type' => 'color', 'name' => 'Blood', 'value' => '#7f1d1d', 'rarity' => 'legendary', 'cost_gems' => 360],
            ['key' => 'color_prism', 'type' => 'color', 'name' => 'Prism', 'value' => '#ec4899', 'rarity' => 'mythic', 'cost_gems' => 525],

            // Banners — the gradient behind the profile hero. 'icon' is the crest glyph, 'accent_hex' drives
            // the crest border/glow and glyph watermark in the Customize banner grid and profile hero.
            ['key' => 'banner_slate', 'type' => 'banner', 'name' => 'Slate', 'value' => 'linear-gradient(150deg, #1a1013, #232323)', 'icon' => '◆', 'accent_hex' => '#8a8a92', 'rarity' => 'common', 'cost_gems' => 25],
            ['key' => 'banner_ember', 'type' => 'banner', 'name' => 'Ember', 'value' => 'linear-gradient(135deg, #7f1d1d, #f97316)', 'icon' => '🔥', 'accent_hex' => '#f97316', 'rarity' => 'rare', 'cost_gems' => 150],
            ['key' => 'banner_ocean', 'type' => 'banner', 'name' => 'Ocean', 'value' => 'linear-gradient(135deg, #0c4a6e, #06b6d4)', 'icon' => '🌊', 'accent_hex' => '#06b6d4', 'rarity' => 'rare', 'cost_gems' => 150],
            ['key' => 'banner_royal', 'type' => 'banner', 'name' => 'Royal', 'value' => 'linear-gradient(135deg, #312e81, #a855f7)', 'icon' => '👑', 'accent_hex' => '#a855f7', 'rarity' => 'epic', 'cost_gems' => 250],
            ['key' => 'banner_golden_hour', 'type' => 'banner', 'name' => 'Golden Hour', 'value' => 'linear-gradient(135deg, #78350f, #fbbf24)', 'icon' => '🌅', 'accent_hex' => '#fbbf24', 'rarity' => 'legendary', 'cost_gems' => 350],
            ['key' => 'banner_void', 'type' => 'banner', 'name' => 'Void', 'value' => 'linear-gradient(135deg, #000000, #4c1d95)', 'icon' => '🕳', 'accent_hex' => '#4c1d95', 'rarity' => 'mythic', 'cost_gems' => 500],
            ['key' => 'banner_starlit', 'type' => 'banner', 'name' => 'Starlit', 'value' => 'linear-gradient(135deg, #0f172a, #334155)', 'icon' => '✦', 'accent_hex' => '#64748b', 'rarity' => 'rare', 'cost_gems' => 150],
            ['key' => 'banner_aurora', 'type' => 'banner', 'name' => 'Aurora', 'value' => 'linear-gradient(135deg, #065f46, #7c3aed)', 'icon' => '🌌', 'accent_hex' => '#7c3aed', 'rarity' => 'epic', 'cost_gems' => 300],
            ['key' => 'banner_inferno', 'type' => 'banner', 'name' => 'Inferno', 'value' => 'linear-gradient(135deg, #450a0a, #ea580c)', 'icon' => '🔥', 'accent_hex' => '#ea580c', 'rarity' => 'legendary', 'cost_gems' => 400],
            ['key' => 'banner_abyss', 'type' => 'banner', 'name' => 'Abyss', 'value' => 'linear-gradient(135deg, #000000, #0f766e)', 'icon' => '🐙', 'accent_hex' => '#0f766e', 'rarity' => 'mythic', 'cost_gems' => 550],
            ['key' => 'banner_bloodmoon', 'type' => 'banner', 'name' => 'Bloodmoon', 'value' => 'linear-gradient(135deg, #1c0a0a, #991b1b)', 'icon' => '🌑', 'accent_hex' => '#991b1b', 'rarity' => 'rare', 'cost_gems' => 175],
            ['key' => 'banner_verdant', 'type' => 'banner', 'name' => 'Verdant', 'value' => 'linear-gradient(135deg, #052e16, #22c55e)', 'icon' => '🌿', 'accent_hex' => '#22c55e', 'rarity' => 'rare', 'cost_gems' => 175],
            ['key' => 'banner_glacier', 'type' => 'banner', 'name' => 'Glacier', 'value' => 'linear-gradient(135deg, #082f49, #38bdf8)', 'icon' => '❄', 'accent_hex' => '#38bdf8', 'rarity' => 'epic', 'cost_gems' => 275],
            ['key' => 'banner_solstice', 'type' => 'banner', 'name' => 'Solstice', 'value' => 'linear-gradient(135deg, #431407, #fb923c, #fde047)', 'icon' => '☀', 'accent_hex' => '#fde047', 'rarity' => 'epic', 'cost_gems' => 300],
            ['key' => 'banner_nebula', 'type' => 'banner', 'name' => 'Nebula', 'value' => 'linear-gradient(135deg, #1e1b4b, #a855f7, #ec4899)', 'icon' => '✨', 'accent_hex' => '#ec4899', 'rarity' => 'legendary', 'cost_gems' => 425],
            ['key' => 'banner_ironclad', 'type' => 'banner', 'name' => 'Ironclad', 'value' => 'linear-gradient(135deg, #18181b, #52525b)', 'icon' => '🛡', 'accent_hex' => '#71717a', 'rarity' => 'common', 'cost_gems' => 30],
            ['key' => 'banner_wildfire', 'type' => 'banner', 'name' => 'Wildfire', 'value' => 'linear-gradient(135deg, #3f0d0d, #dc2626, #f97316)', 'icon' => '⚡', 'accent_hex' => '#dc2626', 'rarity' => 'legendary', 'cost_gems' => 400],
            ['key' => 'banner_eclipse', 'type' => 'banner', 'name' => 'Eclipse', 'value' => 'linear-gradient(135deg, #000000, #1e1b4b, #4c1d95)', 'icon' => '🌑', 'accent_hex' => '#312e81', 'rarity' => 'mythic', 'cost_gems' => 575],

            // Icons — the glyph shown in the profile hero avatar.
            ['key' => 'icon_sword', 'type' => 'icon', 'name' => 'Sword', 'value' => '⚔️', 'rarity' => 'common', 'cost_gems' => 25],
            ['key' => 'icon_shield', 'type' => 'icon', 'name' => 'Shield', 'value' => '🛡️', 'rarity' => 'common', 'cost_gems' => 75],
            ['key' => 'icon_flame', 'type' => 'icon', 'name' => 'Flame', 'value' => '🔥', 'rarity' => 'rare', 'cost_gems' => 100],
            ['key' => 'icon_skull', 'type' => 'icon', 'name' => 'Skull', 'value' => '💀', 'rarity' => 'rare', 'cost_gems' => 100],
            ['key' => 'icon_bow', 'type' => 'icon', 'name' => 'Bow', 'value' => '🏹', 'rarity' => 'rare', 'cost_gems' => 125],
            ['key' => 'icon_wolf', 'type' => 'icon', 'name' => 'Wolf', 'value' => '🐺', 'rarity' => 'rare', 'cost_gems' => 150],
            ['key' => 'icon_lightning', 'type' => 'icon', 'name' => 'Lightning', 'value' => '⚡', 'rarity' => 'rare', 'cost_gems' => 150],
            ['key' => 'icon_star', 'type' => 'icon', 'name' => 'Star', 'value' => '⭐', 'rarity' => 'rare', 'cost_gems' => 175],
            ['key' => 'icon_dragon', 'type' => 'icon', 'name' => 'Dragon', 'value' => '🐉', 'rarity' => 'legendary', 'cost_gems' => 400],
            ['key' => 'icon_wizard_hat', 'type' => 'icon', 'name' => 'Wizard Hat', 'value' => '🧙', 'rarity' => 'epic', 'cost_gems' => 200],
            ['key' => 'icon_crown', 'type' => 'icon', 'name' => 'Crown', 'value' => '👑', 'rarity' => 'legendary', 'cost_gems' => 450],
            ['key' => 'icon_gem', 'type' => 'icon', 'name' => 'Gem', 'value' => '💎', 'rarity' => 'epic', 'cost_gems' => 300],
            ['key' => 'icon_demon', 'type' => 'icon', 'name' => 'Demon', 'value' => '👹', 'rarity' => 'epic', 'cost_gems' => 350],
            ['key' => 'icon_phoenix', 'type' => 'icon', 'name' => 'Phoenix', 'value' => '🔥🦅', 'rarity' => 'mythic', 'cost_gems' => 600, 'unlock_quest_key' => null],
            ['key' => 'icon_ghost', 'type' => 'icon', 'name' => 'Ghost', 'value' => '👻', 'rarity' => 'epic', 'cost_gems' => 200],
            ['key' => 'icon_owl', 'type' => 'icon', 'name' => 'Owl', 'value' => '🦉', 'rarity' => 'rare', 'cost_gems' => 150],
            ['key' => 'icon_shark', 'type' => 'icon', 'name' => 'Shark', 'value' => '🦈', 'rarity' => 'epic', 'cost_gems' => 275],
            ['key' => 'icon_unicorn', 'type' => 'icon', 'name' => 'Unicorn', 'value' => '🦄', 'rarity' => 'legendary', 'cost_gems' => 425],
            ['key' => 'icon_moon', 'type' => 'icon', 'name' => 'Moon', 'value' => '🌙', 'rarity' => 'common', 'cost_gems' => 60],
            ['key' => 'icon_bear', 'type' => 'icon', 'name' => 'Bear', 'value' => '🐻', 'rarity' => 'common', 'cost_gems' => 65],
            ['key' => 'icon_fox', 'type' => 'icon', 'name' => 'Fox', 'value' => '🦊', 'rarity' => 'rare', 'cost_gems' => 110],
            ['key' => 'icon_scorpion', 'type' => 'icon', 'name' => 'Scorpion', 'value' => '🦂', 'rarity' => 'rare', 'cost_gems' => 125],
            ['key' => 'icon_bat', 'type' => 'icon', 'name' => 'Bat', 'value' => '🦇', 'rarity' => 'rare', 'cost_gems' => 130],
            ['key' => 'icon_trident', 'type' => 'icon', 'name' => 'Trident', 'value' => '🔱', 'rarity' => 'epic', 'cost_gems' => 225],
            ['key' => 'icon_hourglass', 'type' => 'icon', 'name' => 'Hourglass', 'value' => '⏳', 'rarity' => 'epic', 'cost_gems' => 210],
            ['key' => 'icon_comet', 'type' => 'icon', 'name' => 'Comet', 'value' => '☄️', 'rarity' => 'epic', 'cost_gems' => 260],
            ['key' => 'icon_griffin', 'type' => 'icon', 'name' => 'Griffin', 'value' => '🦅', 'rarity' => 'legendary', 'cost_gems' => 400],
            ['key' => 'icon_kraken', 'type' => 'icon', 'name' => 'Kraken', 'value' => '🐙', 'rarity' => 'legendary', 'cost_gems' => 440],
            ['key' => 'icon_crystal_ball', 'type' => 'icon', 'name' => 'Crystal Ball', 'value' => '🔮', 'rarity' => 'legendary', 'cost_gems' => 410],
            ['key' => 'icon_infinity', 'type' => 'icon', 'name' => 'Infinity', 'value' => '♾️', 'rarity' => 'mythic', 'cost_gems' => 650],
            ['key' => 'icon_black_hole', 'type' => 'icon', 'name' => 'Void Rift', 'value' => '🌀', 'rarity' => 'mythic', 'cost_gems' => 625],

            // Avatar Frames — a ring drawn around the profile avatar. 'value' is the ring's CSS color,
            // same convention as 'color' cosmetics. Two are gem-purchasable, two reuse real high-level
            // milestones (the same quests title_mythic/title_dragon_slayer are keyed on) rather than
            // inventing new unlock conditions. The Battle Pass tier-75 frame and the PvP Season Master
            // frame are NOT seeded here — see BattlePassService::PREMIUM_COSMETIC_TIERS and
            // PvpSeasonReset::grantSeasonFrame, which create those dynamically the same way the existing
            // Battle Pass banner/title and PvP season titles already do.
            ['key' => 'frame_steel', 'type' => 'frame', 'name' => 'Steel Frame', 'value' => '#94a3b8', 'rarity' => 'common', 'cost_gems' => 60],
            ['key' => 'frame_ember', 'type' => 'frame', 'name' => 'Ember Frame', 'value' => '#e8482f', 'rarity' => 'rare', 'cost_gems' => 180],
            ['key' => 'frame_arcane', 'type' => 'frame', 'name' => 'Arcane Frame', 'value' => '#8b5cf6', 'rarity' => 'epic', 'cost_gems' => 280],
            ['key' => 'frame_veteran', 'type' => 'frame', 'name' => "Veteran's Frame", 'value' => '#eab308', 'rarity' => 'legendary', 'cost_gems' => 0, 'unlock_quest_key' => 'main_reach_level_60'],
            ['key' => 'frame_dragonslayer', 'type' => 'frame', 'name' => 'Dragonslayer Frame', 'value' => '#dc2626', 'rarity' => 'legendary', 'cost_gems' => 0, 'unlock_quest_key' => 'raid_ashfang_dragon'],
            ['key' => 'frame_jade', 'type' => 'frame', 'name' => 'Jade Frame', 'value' => '#22c55e', 'rarity' => 'common', 'cost_gems' => 65],
            ['key' => 'frame_azure', 'type' => 'frame', 'name' => 'Azure Frame', 'value' => '#3b82f6', 'rarity' => 'rare', 'cost_gems' => 190],
            ['key' => 'frame_rose', 'type' => 'frame', 'name' => 'Rose Frame', 'value' => '#f43f5e', 'rarity' => 'epic', 'cost_gems' => 300],
            ['key' => 'frame_prismatic', 'type' => 'frame', 'name' => 'Prismatic Frame', 'value' => '#ec4899', 'rarity' => 'mythic', 'cost_gems' => 600],

            // Purchased titles — pure gem-store prestige, no quest/event tie.
            ['key' => 'title_mercenary', 'type' => 'title', 'name' => 'Mercenary', 'value' => 'Mercenary', 'rarity' => 'common', 'category' => 'purchased', 'cost_gems' => 60, 'unlock_quest_key' => null],
            ['key' => 'title_nightblade', 'type' => 'title', 'name' => 'Nightblade', 'value' => 'Nightblade', 'rarity' => 'rare', 'category' => 'purchased', 'cost_gems' => 140, 'unlock_quest_key' => null],
            ['key' => 'title_stormbringer', 'type' => 'title', 'name' => 'Stormbringer', 'value' => 'Stormbringer', 'rarity' => 'epic', 'category' => 'purchased', 'cost_gems' => 275, 'unlock_quest_key' => null],
            ['key' => 'title_ashen_lord', 'type' => 'title', 'name' => 'Ashen Lord', 'value' => 'Ashen Lord', 'rarity' => 'legendary', 'category' => 'purchased', 'cost_gems' => 500, 'unlock_quest_key' => null],

            // Founder Beta Pack — one-time real-money purchase only (see FounderPackController), never sold
            // for gems and never re-offered once the beta window closes. is_dynamic hides all four from the
            // Customize catalog for anyone who doesn't own them, same as season-reward cosmetics.
            ['key' => 'title_founder', 'type' => 'title', 'name' => 'Founder', 'value' => 'Founder', 'rarity' => 'mythic', 'category' => 'founder', 'cost_gems' => 0, 'is_dynamic' => true, 'unlock_event' => 'founder_pack'],
            ['key' => 'icon_founder', 'type' => 'icon', 'name' => 'Founder Badge', 'value' => '🎖️', 'rarity' => 'mythic', 'category' => 'founder', 'cost_gems' => 0, 'is_dynamic' => true, 'unlock_event' => 'founder_pack'],
            ['key' => 'frame_founder', 'type' => 'frame', 'name' => 'Founder Frame', 'value' => '#facc15', 'rarity' => 'mythic', 'category' => 'founder', 'cost_gems' => 0, 'is_dynamic' => true, 'unlock_event' => 'founder_pack'],
            ['key' => 'banner_founder', 'type' => 'banner', 'name' => 'Founder Banner', 'value' => 'linear-gradient(135deg, #1e1b4b, #facc15)', 'icon' => '⚜️', 'accent_hex' => '#facc15', 'rarity' => 'mythic', 'category' => 'founder', 'cost_gems' => 0, 'is_dynamic' => true, 'unlock_event' => 'founder_pack'],
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
