<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        // User::firstOrCreate, not the factory's create() — re-running db:seed used to throw a
        // duplicate-email error (or require deleting the account first), wiping out its character/
        // progress every time. Now seeding again is a no-op for this user once it already exists.
        User::firstOrCreate(
            ['email' => 'test@example.com'],
            ['name' => 'Test User', 'email_verified_at' => now(), 'password' => Hash::make('password')]
        );

        $this->call([
            ClassSeeder::class,
            ZoneSeeder::class,
            // Items before Monsters: monster loot tables (see MonsterSeeder's loot_table_json) now
            // reference material/trophy Item rows by key, so the catalog they resolve against must exist first.
            ItemSeeder::class,
            ItemSetSeeder::class,
            MonsterSeeder::class,
            DungeonSeeder::class,
            RecipeSeeder::class,
            SkillSeeder::class,
            PetSeeder::class,
            QuestSeeder::class,
            EventSeeder::class,
            FeatureFlagSeeder::class,
            GameConfigSeeder::class,
            LeaderboardSeasonSeeder::class,
            AchievementSeeder::class,
            CosmeticSeeder::class,
            ChangelogSeeder::class,
            LegacyDiscordUserSeeder::class,
            // Runs last: derives the monsters/items/pets wiki categories from the rows the seeders above just created.
            WikiEntrySeeder::class,
        ]);
    }
}
