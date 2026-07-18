<?php

namespace Database\Seeders;

use App\Models\WikiEntry;
use Illuminate\Database\Seeder;

class WikiEntrySeeder extends Seeder
{
    /** t = stat text, c = accent color, muted = false -> tinted badge; muted = true -> grey badge */
    private function s(string $t, string $c): array
    {
        return ['t' => $t, 'color' => $c, 'muted' => false];
    }

    private function sg(string $t, string $c): array
    {
        return ['t' => $t, 'color' => $c, 'muted' => true];
    }

    public function run(): void
    {
        $categories = [
            'items' => [
                ['g' => '⚔', 'name' => 'Ashfang Blade', 'sub' => 'Weapon · Legendary', 'rarity' => 'Legendary', 'desc' => 'A blade forged in dragonfire. The signature legendary weapon of Season 3.', 'stats' => [$this->s('+180 ATK', '#ff8163'), $this->s('+12% crit', '#eab308')]],
                ['g' => '🗡', 'name' => 'Shadow Dagger', 'sub' => 'Weapon · Epic', 'rarity' => 'Epic', 'desc' => 'A wickedly fast dagger that drains life on hit.', 'stats' => [$this->s('+120 ATK', '#ff8163'), $this->s('lifesteal', '#a78bfa')]],
                ['g' => '🏹', 'name' => 'Emberbow', 'sub' => 'Weapon · Rare', 'rarity' => 'Rare', 'desc' => 'A longbow strung with molten sinew for precise ranged damage.', 'stats' => [$this->s('+95 ATK', '#ff8163')]],
                ['g' => '🔮', 'name' => 'Void Staff', 'sub' => 'Weapon · Epic', 'rarity' => 'Epic', 'desc' => 'Channels raw void energy into devastating spells.', 'stats' => [$this->s('+140 magic', '#a78bfa')]],
                ['g' => '🛡', 'name' => 'Aegis Plate', 'sub' => 'Armor · Legendary', 'rarity' => 'Legendary', 'desc' => 'Impenetrable plate armor blessed by the old gods.', 'stats' => [$this->s('+150 DEF', '#5cc7f5')]],
                ['g' => '🥋', 'name' => 'Shadow Robe', 'sub' => 'Armor · Epic', 'rarity' => 'Epic', 'desc' => 'Woven from shadowsilk; favored by mages.', 'stats' => [$this->s('+90 DEF', '#5cc7f5'), $this->s('+40 MP', '#38bdf8')]],
                ['g' => '👢', 'name' => 'Swift Boots', 'sub' => 'Armor · Rare', 'rarity' => 'Rare', 'desc' => 'Enchanted boots that make you harder to hit.', 'stats' => [$this->s('+15% dodge', '#4ade80')]],
                ['g' => '🧪', 'name' => 'Health Potion', 'sub' => 'Consumable · Common', 'rarity' => 'Common', 'desc' => 'Restores 40% of your max HP instantly in battle.', 'stats' => [$this->sg('Restore 40% HP', '#4ade80')]],
                ['g' => '💧', 'name' => 'Mana Potion', 'sub' => 'Consumable · Common', 'rarity' => 'Common', 'desc' => 'Restores 40% of your max MP instantly in battle.', 'stats' => [$this->sg('Restore 40% MP', '#38bdf8')]],
                ['g' => '⚗', 'name' => 'Elixir of Power', 'sub' => 'Consumable · Legendary', 'rarity' => 'Legendary', 'desc' => 'Grants +50% ATK for your next three fights.', 'stats' => [$this->s('+50% ATK 3 fights', '#eab308')]],
                ['g' => '👑', 'name' => 'Golden Crown', 'sub' => 'Cosmetic · Legendary', 'rarity' => 'Legendary', 'desc' => 'A pure flex — no combat stats, all prestige.', 'stats' => [$this->sg('Cosmetic', '#eab308')]],
                ['g' => '💎', 'name' => 'Iron Ore', 'sub' => 'Material · Common', 'rarity' => 'Common', 'desc' => 'Basic crafting material dropped by golems and mined in caverns.', 'stats' => [$this->sg('Crafting', '#cbd5e1')]],
            ],
            'monsters' => [
                ['g' => '🟢', 'name' => 'Slime', 'sub' => 'Whispering Meadows · Lv.1', 'rarity' => 'Common', 'desc' => 'A harmless jelly that wobbles across the meadow. Every adventurer\'s first fight.', 'stats' => [$this->s('HP 40', '#4ade80'), $this->s('ATK 8', '#ff8163'), $this->sg('12g · 20xp', '#eab308')]],
                ['g' => '🐭', 'name' => 'Field Mouse', 'sub' => 'Whispering Meadows · Lv.1', 'rarity' => 'Common', 'desc' => 'Skittish rodents that nibble at crops and flee at the first sign of danger.', 'stats' => [$this->s('HP 30', '#4ade80'), $this->s('ATK 6', '#ff8163'), $this->sg('8g · 15xp', '#eab308')]],
                ['g' => '🐗', 'name' => 'Wild Boar', 'sub' => 'Whispering Meadows · Lv.4', 'rarity' => 'Common', 'desc' => 'Ill-tempered tuskers that charge anything that gets too close.', 'stats' => [$this->s('HP 90', '#4ade80'), $this->s('ATK 16', '#ff8163'), $this->sg('25g · 45xp', '#eab308')]],
                ['g' => '🗡', 'name' => 'Bandit Scout', 'sub' => 'Whispering Meadows · Lv.10', 'rarity' => 'Common', 'desc' => 'Highwaymen who prey on travelers along the meadow roads.', 'stats' => [$this->s('HP 180', '#4ade80'), $this->s('ATK 28', '#ff8163'), $this->sg('60g · 110xp', '#eab308')]],
                ['g' => '🕷', 'name' => 'Giant Spider', 'sub' => 'Whispering Meadows · Lv.18', 'rarity' => 'Rare', 'desc' => 'Oversized arachnids nesting in the meadow\'s overgrown hedgerows.', 'stats' => [$this->s('HP 300', '#4ade80'), $this->s('ATK 42', '#ff8163'), $this->sg('110g · 200xp', '#eab308')]],
                ['g' => '⚔', 'name' => 'Rogue Knight', 'sub' => 'Whispering Meadows · Lv.28', 'rarity' => 'Rare', 'desc' => 'A disgraced knight turned bandit lord, guarding the meadow\'s edge before the Dark Forest.', 'stats' => [$this->s('HP 380', '#4ade80'), $this->s('ATK 55', '#ff8163'), $this->sg('160g · 300xp', '#eab308')]],
                ['g' => '🐺', 'name' => 'Shadow Wolf', 'sub' => 'Dark Forest · Lv.35', 'rarity' => 'Common', 'desc' => 'Pack hunters that stalk the Dark Forest at dusk.', 'stats' => [$this->s('HP 420', '#4ade80'), $this->s('ATK 60', '#ff8163'), $this->sg('180g · 340xp', '#eab308')]],
                ['g' => '👻', 'name' => 'Dark Spirit', 'sub' => 'Dark Forest · Lv.38', 'rarity' => 'Rare', 'desc' => 'Restless souls that drain the warmth from the living.', 'stats' => [$this->s('HP 560', '#4ade80'), $this->s('ATK 85', '#ff8163'), $this->sg('260g · 480xp', '#eab308')]],
                ['g' => '🗿', 'name' => 'Stone Golem', 'sub' => 'Frostpeak · Lv.40', 'rarity' => 'Rare', 'desc' => 'Ancient constructs that guard the frost caverns.', 'stats' => [$this->s('HP 900', '#4ade80'), $this->s('ATK 110', '#ff8163'), $this->sg('420g · 720xp', '#eab308')]],
                ['g' => '🐉', 'name' => 'Ashfang Dragon', 'sub' => 'Dragon Lair · BOSS', 'rarity' => 'Legendary', 'desc' => 'The Season 3 world boss. Requires a full guild raid.', 'stats' => [$this->s('HP 1600', '#4ade80'), $this->s('ATK 160', '#ff8163'), $this->s('BOSS', '#e8482f')]],
                ['g' => '❄', 'name' => 'Ice Golem', 'sub' => 'Frostpeak · Lv.42', 'rarity' => 'Rare', 'desc' => 'Golems of living ice that shatter into shards.', 'stats' => [$this->s('HP 1100', '#4ade80'), $this->s('ATK 130', '#ff8163')]],
                ['g' => '🦑', 'name' => 'Abyss Kraken', 'sub' => 'Sunken Abyss · Lv.50', 'rarity' => 'Epic', 'desc' => 'A leviathan lurking in the drowned depths.', 'stats' => [$this->s('HP 2200', '#4ade80'), $this->s('ATK 200', '#ff8163')]],
                ['g' => '👁', 'name' => 'Void Sovereign', 'sub' => 'The Void · MYTHIC', 'rarity' => 'Mythic', 'desc' => 'The endgame raid boss that rules the space between worlds.', 'stats' => [$this->s('HP 5000', '#4ade80'), $this->s('ATK 320', '#ff8163'), $this->s('MYTHIC', '#e8482f')]],
            ],
            'zones' => [
                ['g' => '🌾', 'name' => 'Whispering Meadows', 'sub' => 'Danger: Safe · Lv.1+', 'rarity' => 'Common', 'desc' => 'The starter zone — rolling fields of slimes and boars.', 'stats' => [$this->sg('Safe', '#4ade80'), $this->sg('Lv.1+', '#cbd5e1')]],
                ['g' => '🌲', 'name' => 'Dark Forest', 'sub' => 'Danger: Medium · Lv.35+', 'rarity' => 'Rare', 'desc' => 'Shadow wolves and dark spirits roam this high-XP zone.', 'stats' => [$this->sg('Medium', '#eab308'), $this->sg('High XP', '#4ade80')]],
                ['g' => '🏔', 'name' => 'Frostpeak Caverns', 'sub' => 'Danger: High · Lv.40+', 'rarity' => 'Epic', 'desc' => 'Frozen tunnels home to golems and yetis.', 'stats' => [$this->sg('High', '#ff8163'), $this->sg('Lv.40+', '#cbd5e1')]],
                ['g' => '🌋', 'name' => 'Emberpeak Volcano', 'sub' => 'Danger: High · Lv.45+ · Locked', 'rarity' => 'Epic', 'desc' => 'A molten wasteland unlocked by reaching level 45.', 'stats' => [$this->sg('High', '#ff8163'), $this->sg('🔒 Locked', '#a78bfa')]],
                ['g' => '🌊', 'name' => 'Sunken Abyss', 'sub' => 'Danger: Deadly · Lv.50+ · Locked', 'rarity' => 'Legendary', 'desc' => 'The drowned ruins of an ancient civilization.', 'stats' => [$this->sg('Deadly', '#a78bfa'), $this->sg('🔒 Locked', '#a78bfa')]],
                ['g' => '🌌', 'name' => 'The Void', 'sub' => 'Danger: Deadly · Lv.60+ · Locked', 'rarity' => 'Mythic', 'desc' => 'The final frontier, home of the Void Sovereign.', 'stats' => [$this->sg('Deadly', '#a78bfa'), $this->sg('🔒 Endgame', '#e8482f')]],
            ],
            'dungeons' => [
                ['g' => '🐺', 'name' => 'Wolf Den', 'sub' => 'Normal · 3 waves', 'rarity' => 'Rare', 'desc' => 'A starter dungeon culminating in the Alpha Fenrir boss.', 'stats' => [$this->sg('Normal', '#4ade80'), $this->sg('Rare gear · 800g', '#eab308')]],
                ['g' => '⚰', 'name' => 'Haunted Crypt', 'sub' => 'Hard · 5 waves', 'rarity' => 'Epic', 'desc' => 'Face the Lich King in his cursed tomb.', 'stats' => [$this->sg('Hard', '#eab308'), $this->sg('Epic · 2000g · 5◆', '#eab308')]],
                ['g' => '🐉', 'name' => 'Dragon Lair', 'sub' => 'Raid · Boss', 'rarity' => 'Legendary', 'desc' => 'A full-party raid against the Ashfang Dragon.', 'stats' => [$this->sg('Raid', '#ff8163'), $this->sg('Legendary · 20◆', '#eab308')]],
                ['g' => '👁', 'name' => 'The Void Throne', 'sub' => 'Mythic · Endgame', 'rarity' => 'Mythic', 'desc' => 'The hardest content in Solyx — defeat the Void Sovereign.', 'stats' => [$this->sg('Mythic', '#a78bfa'), $this->sg('Mythic set · 50◆', '#eab308')]],
            ],
            'events' => [
                ['g' => '🔥', 'name' => 'Ashfall Season', 'sub' => 'Login Reward · Live', 'rarity' => 'Legendary', 'desc' => 'The current Season 3 — new zone, battle pass and world boss.', 'stats' => [$this->s('LIVE', '#4ade80')]],
                ['g' => '⚡', 'name' => 'Double XP Weekend', 'sub' => 'Bonus XP · Fri–Sun', 'rarity' => 'Rare', 'desc' => 'Earn double XP from every battle all weekend long.', 'stats' => [$this->sg('+100% XP', '#eab308')]],
                ['g' => '🐉', 'name' => 'Dragon World Boss', 'sub' => 'World Boss · Sat 8pm UTC', 'rarity' => 'Legendary', 'desc' => 'The whole server rallies to bring down the Ashfang Dragon.', 'stats' => [$this->sg('Legendary drops', '#eab308')]],
            ],
            'classes' => [
                ['g' => '⚔', 'name' => 'Warrior', 'sub' => 'Base class · Tank', 'rarity' => 'Common', 'desc' => 'High HP and defense; specializes into Berserker or Guardian at Lv.20.', 'stats' => [$this->s('HP 1200', '#4ade80'), $this->s('ATK 280', '#ff8163')]],
                ['g' => '✷', 'name' => 'Mage', 'sub' => 'Base class · Caster', 'rarity' => 'Common', 'desc' => 'Fragile burst caster; specializes into Shadow Mage or Elementalist.', 'stats' => [$this->s('HP 820', '#4ade80'), $this->s('MP 520', '#38bdf8')]],
                ['g' => '🗡', 'name' => 'Rogue', 'sub' => 'Base class · Crit', 'rarity' => 'Common', 'desc' => 'Fast, evasive and crit-focused; branches to Assassin or Trickster.', 'stats' => [$this->s('HP 900', '#4ade80'), $this->s('ATK 310', '#ff8163')]],
                ['g' => '🏹', 'name' => 'Ranger', 'sub' => 'Base class · Ranged', 'rarity' => 'Common', 'desc' => 'Precise ranged DPS; branches to Hunter or Beastmaster.', 'stats' => [$this->s('HP 940', '#4ade80'), $this->s('ATK 300', '#ff8163')]],
                ['g' => '💀', 'name' => 'Necromancer', 'sub' => 'Mage profession · Lv.40', 'rarity' => 'Epic', 'desc' => 'A Mage profession that raises undead minions and drains life.', 'stats' => [$this->sg('Profession', '#a78bfa')]],
                ['g' => '👑', 'name' => 'Warlord', 'sub' => 'Warrior profession · Lv.40', 'rarity' => 'Epic', 'desc' => 'A battlefield commander that buffs allies and cleaves foes.', 'stats' => [$this->sg('Profession', '#a78bfa')]],
            ],
            'skills' => [
                ['g' => '⚔', 'name' => 'Power Strike', 'sub' => 'Warfare · Lv.1', 'rarity' => 'Common', 'desc' => '+15% attack damage on your basic attack.', 'stats' => [$this->sg('Passive', '#cbd5e1')]],
                ['g' => '🪓', 'name' => 'Cleave', 'sub' => 'Warfare · Lv.15', 'rarity' => 'Rare', 'desc' => 'Strike all enemies at once. Costs MP.', 'stats' => [$this->sg('20 MP', '#38bdf8')]],
                ['g' => '✷', 'name' => 'Shadow Bolt', 'sub' => 'Sorcery · Lv.1', 'rarity' => 'Common', 'desc' => 'The core burst spell — heavy single-target damage.', 'stats' => [$this->sg('40 MP', '#38bdf8')]],
                ['g' => '⚡', 'name' => 'Chain Cast', 'sub' => 'Sorcery · Lv.30', 'rarity' => 'Epic', 'desc' => 'Your spells hit twice. Costs MP.', 'stats' => [$this->sg('60 MP', '#38bdf8')]],
                ['g' => '🌀', 'name' => 'Void Nova', 'sub' => 'Sorcery · Lv.50', 'rarity' => 'Legendary', 'desc' => 'A massive AoE ultimate that devastates the battlefield.', 'stats' => [$this->sg('Ultimate', '#a78bfa')]],
                ['g' => '🛡', 'name' => 'Tough Skin', 'sub' => 'Survival · Lv.1', 'rarity' => 'Common', 'desc' => '+20% defense, permanently.', 'stats' => [$this->sg('Passive', '#cbd5e1')]],
                ['g' => '✨', 'name' => 'Undying', 'sub' => 'Survival · Lv.50', 'rarity' => 'Legendary', 'desc' => 'Survive one otherwise-fatal hit per battle.', 'stats' => [$this->sg('Passive', '#a78bfa')]],
            ],
            'pets' => [
                ['g' => '🐺', 'name' => 'Frost Wolf', 'sub' => 'Companion', 'rarity' => 'Rare', 'desc' => 'A loyal wolf that boosts your attack in battle.', 'stats' => [$this->s('+10% ATK', '#ff8163')]],
                ['g' => '🐲', 'name' => 'Baby Drake', 'sub' => 'Companion', 'rarity' => 'Epic', 'desc' => 'A young dragon that sharpens your critical strikes.', 'stats' => [$this->s('+8% crit', '#eab308')]],
                ['g' => '🦉', 'name' => 'Spirit Owl', 'sub' => 'Companion', 'rarity' => 'Rare', 'desc' => 'A wise owl that accelerates your XP gains.', 'stats' => [$this->s('+15% XP', '#4ade80')]],
                ['g' => '🗿', 'name' => 'Mini Golem', 'sub' => 'Companion', 'rarity' => 'Epic', 'desc' => 'A pocket golem that hardens your defenses.', 'stats' => [$this->s('+20% DEF', '#5cc7f5')]],
            ],
        ];

        foreach ($categories as $category => $rows) {
            foreach ($rows as $i => $row) {
                WikiEntry::create([
                    'category' => $category,
                    'glyph' => $row['g'],
                    'name' => $row['name'],
                    'sub' => $row['sub'],
                    'rarity' => $row['rarity'],
                    'description' => $row['desc'],
                    'stats' => $row['stats'],
                    'sort_order' => $i,
                ]);
            }
        }
    }
}
