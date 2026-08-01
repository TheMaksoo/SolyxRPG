<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferralMilestone extends Model
{
    protected $fillable = [
        'referrer_id',
        'referee_id',
        'level_milestone',
        'reward_granted_at',
    ];

    protected $casts = [
        'reward_granted_at' => 'datetime',
    ];

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }

    public function referee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referee_id');
    }
}
