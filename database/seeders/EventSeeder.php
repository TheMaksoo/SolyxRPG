<?php

namespace Database\Seeders;

use App\Models\Event;
use Illuminate\Database\Seeder;

class EventSeeder extends Seeder
{
    public function run(): void
    {
        Event::updateOrCreate(['name' => 'Ashfall Season'], [
            'type' => 'login',
            'reward' => 'Season 3 battle pass + world boss access',
            'effect_json' => [],
            'starts_at' => now()->subWeeks(2),
            'ends_at' => now()->addWeeks(10),
            'active' => true,
        ]);

        Event::updateOrCreate(['name' => 'Double XP Weekend'], [
            'type' => 'bonus_xp',
            'reward' => '+100% XP',
            'effect_json' => ['xp_mult' => 2],
            'starts_at' => now()->startOfWeek()->addDays(4),
            'ends_at' => now()->startOfWeek()->addDays(7),
            'active' => false,
        ]);

        Event::updateOrCreate(['name' => 'Dragon World Boss'], [
            'type' => 'world_boss',
            'reward' => 'Legendary drops',
            'effect_json' => ['monster_key' => 'ashfang_dragon'],
            'starts_at' => now()->startOfWeek()->addDays(5)->setTime(20, 0),
            'ends_at' => now()->startOfWeek()->addDays(5)->setTime(21, 0),
            'active' => false,
        ]);
    }
}
