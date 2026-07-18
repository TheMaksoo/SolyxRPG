<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GemLedger extends Model
{
    public $timestamps = false;
    protected $table = 'gem_ledger';
    protected $fillable = ['character_id', 'delta', 'reason', 'created_at'];
    protected $casts = ['created_at' => 'datetime'];

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
