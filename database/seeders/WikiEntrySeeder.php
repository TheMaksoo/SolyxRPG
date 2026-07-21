<?php

namespace Database\Seeders;

use App\Services\WikiSyncService;
use Illuminate\Database\Seeder;

class WikiEntrySeeder extends Seeder
{
    /** Every wiki category is derived straight from live game tables via WikiSyncService, so the
     * wiki can never drift out of sync with a monster/zone/dungeon/skill rebalance — see that
     * class for how each category maps to its source model. */
    public function run(): void
    {
        $wiki = new WikiSyncService();
        $wiki->syncMonsters();
        $wiki->syncItems();
        $wiki->syncPets();
        $wiki->syncZones();
        $wiki->syncDungeons();
        $wiki->syncSkills();
        $wiki->syncEvents();
        $wiki->syncClasses();
    }
}
