<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->call([
            ClassSeeder::class,
            ZoneSeeder::class,
            MonsterSeeder::class,
            DungeonSeeder::class,
            ItemSeeder::class,
            RecipeSeeder::class,
            SkillSeeder::class,
            PetSeeder::class,
            QuestSeeder::class,
            EventSeeder::class,
            FeatureFlagSeeder::class,
            GameConfigSeeder::class,
            AchievementSeeder::class,
            // Runs last: derives the monsters/items/pets wiki categories from the rows the seeders above just created.
            WikiEntrySeeder::class,
        ]);
    }
}
