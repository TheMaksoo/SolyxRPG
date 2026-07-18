<?php

namespace Database\Seeders;

use App\Models\WikiEntry;
use App\Services\WikiSyncService;
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
        // 'items', 'monsters', and 'pets' are NOT hand-typed here — WikiSyncService derives them
        // straight from the Item/Monster/Pet tables below, so they can never drift out of sync
        // with the game's real stats.
        $categories = [
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
        ];

        foreach ($categories as $category => $rows) {
            foreach ($rows as $i => $row) {
                WikiEntry::updateOrCreate(
                    ['category' => $category, 'name' => $row['name']],
                    [
                        'glyph' => $row['g'],
                        'sub' => $row['sub'],
                        'rarity' => $row['rarity'],
                        'description' => $row['desc'],
                        'stats' => $row['stats'],
                        'sort_order' => $i,
                    ]
                );
            }
        }

        $wiki = new WikiSyncService();
        $wiki->syncMonsters();
        $wiki->syncItems();
        $wiki->syncPets();
    }
}
