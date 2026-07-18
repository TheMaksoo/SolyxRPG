<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyClaim extends Model
{
    protected $fillable = ['character_id', 'streak', 'last_claim_date'];
    protected $casts = ['last_claim_date' => 'date'];

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
