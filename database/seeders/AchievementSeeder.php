<?php

namespace Database\Seeders;

use App\Models\Achievement;
use Illuminate\Database\Seeder;

class AchievementSeeder extends Seeder
{
    public function run(): void
    {
        $achievements = [
            ['key' => 'first_blood', 'name' => 'First Blood', 'glyph' => '🩸', 'description' => 'Win your first battle.', 'requirement_json' => ['kind' => 'battles_won', 'target' => 1]],
            ['key' => 'monster_hunter', 'name' => 'Monster Hunter', 'glyph' => '🗡', 'description' => 'Win 50 battles.', 'requirement_json' => ['kind' => 'battles_won', 'target' => 50]],
            ['key' => 'veteran', 'name' => 'Veteran', 'glyph' => '🎖', 'description' => 'Win 300 battles.', 'requirement_json' => ['kind' => 'battles_won', 'target' => 300]],
            ['key' => 'warlord', 'name' => 'Warlord', 'glyph' => '⚔', 'description' => 'Win 1,000 battles.', 'requirement_json' => ['kind' => 'battles_won', 'target' => 1000]],
            ['key' => 'boss_slayer', 'name' => 'Boss Slayer', 'glyph' => '💀', 'description' => 'Defeat your first boss.', 'requirement_json' => ['kind' => 'bosses_slain', 'target' => 1]],
            ['key' => 'boss_hunter', 'name' => 'Boss Hunter', 'glyph' => '☠', 'description' => 'Defeat 25 bosses.', 'requirement_json' => ['kind' => 'bosses_slain', 'target' => 25]],
            ['key' => 'dragon_slayer', 'name' => 'Dragon Slayer', 'glyph' => '🐉', 'description' => 'Defeat the Ashfang Dragon.', 'requirement_json' => ['kind' => 'boss_kill', 'monster_key' => 'ashfang_dragon']],
            ['key' => 'rising_star', 'name' => 'Rising Star', 'glyph' => '⭐', 'description' => 'Reach level 10.', 'requirement_json' => ['kind' => 'level', 'target' => 10]],
            ['key' => 'seasoned', 'name' => 'Seasoned Adventurer', 'glyph' => '🏅', 'description' => 'Reach level 30.', 'requirement_json' => ['kind' => 'level', 'target' => 30]],
            ['key' => 'legend', 'name' => 'Legend', 'glyph' => '👑', 'description' => 'Reach level 60.', 'requirement_json' => ['kind' => 'level', 'target' => 60]],
            ['key' => 'ascendant', 'name' => 'Ascendant', 'glyph' => '🌟', 'description' => 'Reach level 100.', 'requirement_json' => ['kind' => 'level', 'target' => 100]],
            ['key' => 'wealthy', 'name' => 'Wealthy', 'glyph' => '💰', 'description' => 'Hold 10,000 gold at once.', 'requirement_json' => ['kind' => 'gold', 'target' => 10000]],
            ['key' => 'tycoon', 'name' => 'Tycoon', 'glyph' => '🏦', 'description' => 'Hold 100,000 gold at once.', 'requirement_json' => ['kind' => 'gold', 'target' => 100000]],
            ['key' => 'gem_collector', 'name' => 'Gem Collector', 'glyph' => '💎', 'description' => 'Hold 500 gems at once.', 'requirement_json' => ['kind' => 'gems', 'target' => 500]],
            ['key' => 'quest_novice', 'name' => 'Quest Novice', 'glyph' => '📜', 'description' => 'Complete 10 quests.', 'requirement_json' => ['kind' => 'quests_completed', 'target' => 10]],
            ['key' => 'quest_adept', 'name' => 'Quest Adept', 'glyph' => '📚', 'description' => 'Complete 100 quests.', 'requirement_json' => ['kind' => 'quests_completed', 'target' => 100]],
            ['key' => 'quest_master', 'name' => 'Quest Master', 'glyph' => '🏆', 'description' => 'Complete 500 quests.', 'requirement_json' => ['kind' => 'quests_completed', 'target' => 500]],
            ['key' => 'quest_legend', 'name' => 'Quest Legend', 'glyph' => '📖', 'description' => 'Complete 1,000 quests.', 'requirement_json' => ['kind' => 'quests_completed', 'target' => 1000]],
            ['key' => 'warmonger', 'name' => 'Warmonger', 'glyph' => '⚔️', 'description' => 'Win 5,000 battles.', 'requirement_json' => ['kind' => 'battles_won', 'target' => 5000]],
            ['key' => 'transcendent', 'name' => 'Transcendent', 'glyph' => '✨', 'description' => 'Reach level 150.', 'requirement_json' => ['kind' => 'level', 'target' => 150]],
            ['key' => 'gold_baron', 'name' => 'Gold Baron', 'glyph' => '🏛️', 'description' => 'Hold 1,000,000 gold at once.', 'requirement_json' => ['kind' => 'gold', 'target' => 1000000]],
            ['key' => 'gem_hoarder', 'name' => 'Gem Hoarder', 'glyph' => '💰', 'description' => 'Hold 2,000 gems at once.', 'requirement_json' => ['kind' => 'gems', 'target' => 2000]],
            ['key' => 'first_duel', 'name' => 'First Duel', 'glyph' => '🤺', 'description' => 'Win your first PvP match.', 'requirement_json' => ['kind' => 'pvp_wins', 'target' => 1]],
            ['key' => 'arena_regular', 'name' => 'Arena Regular', 'glyph' => '🛡️', 'description' => 'Win 50 PvP matches.', 'requirement_json' => ['kind' => 'pvp_wins', 'target' => 50]],
            ['key' => 'arena_champion', 'name' => 'Arena Champion', 'glyph' => '🏆', 'description' => 'Win 250 PvP matches.', 'requirement_json' => ['kind' => 'pvp_wins', 'target' => 250]],
            ['key' => 'platinum_contender', 'name' => 'Platinum Contender', 'glyph' => '💠', 'description' => 'Reach 1,600 PvP rating.', 'requirement_json' => ['kind' => 'pvp_rating', 'target' => 1600]],
            ['key' => 'diamond_elite', 'name' => 'Diamond Elite', 'glyph' => '💎', 'description' => 'Reach 2,000 PvP rating.', 'requirement_json' => ['kind' => 'pvp_rating', 'target' => 2000]],
            ['key' => 'skilled_miner', 'name' => 'Skilled Miner', 'glyph' => '⛏', 'description' => 'Reach level 25 Mining.', 'requirement_json' => ['kind' => 'trade_skill_level', 'skill_key' => 'mining', 'target' => 25]],
            ['key' => 'skilled_lumberjack', 'name' => 'Skilled Lumberjack', 'glyph' => '🪓', 'description' => 'Reach level 25 Woodchopping.', 'requirement_json' => ['kind' => 'trade_skill_level', 'skill_key' => 'woodchopping', 'target' => 25]],
            ['key' => 'skilled_smelter', 'name' => 'Skilled Smelter', 'glyph' => '🔥', 'description' => 'Reach level 25 Smelting.', 'requirement_json' => ['kind' => 'trade_skill_level', 'skill_key' => 'smelting', 'target' => 25]],
            ['key' => 'skilled_forager', 'name' => 'Skilled Forager', 'glyph' => '🌿', 'description' => 'Reach level 25 Foraging.', 'requirement_json' => ['kind' => 'trade_skill_level', 'skill_key' => 'foraging', 'target' => 25]],
            ['key' => 'skilled_crafter', 'name' => 'Skilled Crafter', 'glyph' => '🔨', 'description' => 'Reach level 25 Crafting.', 'requirement_json' => ['kind' => 'trade_skill_level', 'skill_key' => 'crafting', 'target' => 25]],
            ['key' => 'first_companion', 'name' => 'First Companion', 'glyph' => '🐾', 'description' => 'Unlock your first pet.', 'requirement_json' => ['kind' => 'pets_owned', 'target' => 1]],
            ['key' => 'menagerie', 'name' => 'Menagerie', 'glyph' => '🦊', 'description' => 'Unlock 5 pets.', 'requirement_json' => ['kind' => 'pets_owned', 'target' => 5]],
            ['key' => 'beast_master', 'name' => 'Beast Master', 'glyph' => '🐻', 'description' => 'Unlock 10 pets.', 'requirement_json' => ['kind' => 'pets_owned', 'target' => 10]],
            ['key' => 'well_connected', 'name' => 'Well Connected', 'glyph' => '🤝', 'description' => 'Make 5 friends.', 'requirement_json' => ['kind' => 'friends_count', 'target' => 5]],
            ['key' => 'social_butterfly', 'name' => 'Social Butterfly', 'glyph' => '🦋', 'description' => 'Make 25 friends.', 'requirement_json' => ['kind' => 'friends_count', 'target' => 25]],
            ['key' => 'pass_holder', 'name' => 'Pass Holder', 'glyph' => '🎫', 'description' => 'Reach Battle Pass tier 25.', 'requirement_json' => ['kind' => 'battle_pass_tier', 'target' => 25]],
            ['key' => 'pass_master', 'name' => 'Pass Master', 'glyph' => '🎟️', 'description' => 'Reach Battle Pass tier 50.', 'requirement_json' => ['kind' => 'battle_pass_tier', 'target' => 50]],
            ['key' => 'weekly_regular', 'name' => 'Weekly Regular', 'glyph' => '📅', 'description' => 'Reach a 7-day login streak.', 'requirement_json' => ['kind' => 'daily_streak', 'target' => 7]],
            ['key' => 'monthly_devotee', 'name' => 'Monthly Devotee', 'glyph' => '🗓️', 'description' => 'Reach a 30-day login streak.', 'requirement_json' => ['kind' => 'daily_streak', 'target' => 30]],
            ['key' => 'guild_initiate', 'name' => 'Guild Initiate', 'glyph' => '🏰', 'description' => 'Join or found a guild.', 'requirement_json' => ['kind' => 'guild_member']],
        ];

        foreach ($achievements as $a) {
            Achievement::updateOrCreate(['key' => $a['key']], $a);
        }
    }
}
