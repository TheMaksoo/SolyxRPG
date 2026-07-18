<?php

namespace Database\Seeders;

use App\Models\GameConfig;
use Illuminate\Database\Seeder;

class GameConfigSeeder extends Seeder
{
    public function run(): void
    {
        $config = [
            'gold_mult' => '1',
            'xp_mult' => '1',
            'drop_rate' => '15',
            'gem_mult' => '1',
        ];

        foreach ($config as $key => $value) {
            GameConfig::updateOrCreate(['key' => $key], ['value' => $value]);
        }
    }
}
