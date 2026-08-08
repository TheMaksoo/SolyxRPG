<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TesterUpdateVote extends Model
{
    protected $fillable = ['changelog_id', 'user_id'];

    public function changelog(): BelongsTo
    {
        return $this->belongsTo(Changelog::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
