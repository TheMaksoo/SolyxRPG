<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PvpMatch extends Model
{
    public $timestamps = false;
    protected $fillable = ['character_id', 'opponent_id', 'result', 'rating_delta', 'created_at'];
    protected $casts = ['created_at' => 'datetime'];

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function opponent(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'opponent_id');
    }
}
