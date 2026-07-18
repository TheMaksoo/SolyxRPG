<?php

namespace Database\Seeders;

use App\Models\Item;
use App\Models\Recipe;
use Illuminate\Database\Seeder;

class RecipeSeeder extends Seeder
{
    public function run(): void
    {
        $ironOreId = Item::where('key', 'iron_ore')->value('id');
        $ironDaggerId = Item::where('key', 'iron_dagger')->value('id');

        Recipe::updateOrCreate(
            ['name' => 'Craft Iron Dagger'],
            [
                'result_item_id' => $ironDaggerId,
                'materials_json' => [['item_id' => $ironOreId, 'qty' => 5]],
                'enabled' => true,
            ]
        );
    }
}
