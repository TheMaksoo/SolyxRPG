<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorldMessage extends Model
{
    public $timestamps = false;
    protected $fillable = ['character_id', 'body', 'created_at'];
    protected $casts = ['created_at' => 'datetime'];

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
