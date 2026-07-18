<?php

namespace Database\Seeders;

use App\Models\Item;
use App\Models\Recipe;
use Illuminate\Database\Seeder;

class RecipeSeeder extends Seeder
{
    public function run(): void
    {
        $ids = Item::whereIn('key', [
            'iron_bar', 'wood', 'silver_bar', 'ironwood', 'iron_dagger', 'wooden_bow', 'iron_buckler', 'silvered_blade',
        ])->pluck('id', 'key');

        $recipes = [
            ['name' => 'Craft Iron Dagger', 'result' => 'iron_dagger', 'materials' => [['item' => 'iron_bar', 'qty' => 3]], 'craft_seconds' => 30],
            ['name' => 'Craft Wooden Bow', 'result' => 'wooden_bow', 'materials' => [['item' => 'wood', 'qty' => 8]], 'craft_seconds' => 25],
            ['name' => 'Craft Iron Buckler', 'result' => 'iron_buckler', 'materials' => [['item' => 'iron_bar', 'qty' => 4]], 'craft_seconds' => 45],
            ['name' => 'Craft Silvered Blade', 'result' => 'silvered_blade', 'materials' => [['item' => 'silver_bar', 'qty' => 3], ['item' => 'iron_bar', 'qty' => 2], ['item' => 'ironwood', 'qty' => 2]], 'craft_seconds' => 90],
        ];

        foreach ($recipes as $recipe) {
            Recipe::updateOrCreate(
                ['name' => $recipe['name']],
                [
                    'result_item_id' => $ids[$recipe['result']],
                    'materials_json' => collect($recipe['materials'])->map(fn (array $m) => ['item_id' => $ids[$m['item']], 'qty' => $m['qty']])->all(),
                    'craft_seconds' => $recipe['craft_seconds'],
                    'enabled' => true,
                ]
            );
        }
    }
}
