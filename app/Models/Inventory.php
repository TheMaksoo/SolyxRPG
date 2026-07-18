<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Inventory extends Model

{
    protected $table = 'inventories';
    protected $fillable = ['character_id', 'item_id', 'qty', 'equipped', 'slot'];
    protected $casts = ['equipped' => 'boolean'];

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
