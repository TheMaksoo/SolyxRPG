<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PvpQueueEntry extends Model
{
    protected $table = 'pvp_queue';
    public $timestamps = false;
    protected $fillable = ['character_id', 'rating', 'queued_at'];
    protected $casts = ['queued_at' => 'datetime'];

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
