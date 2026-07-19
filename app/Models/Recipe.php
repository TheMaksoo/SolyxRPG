<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Recipe extends Model
{
    protected $fillable = ['name', 'result_item_id', 'materials_json', 'craft_seconds', 'result_qty', 'min_level', 'enabled'];

    protected $casts = [
        'materials_json' => 'array',
        'enabled' => 'boolean',
    ];

    public function resultItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'result_item_id');
    }
}
