<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TesterBugReport extends Model
{
    protected $fillable = ['changelog_id', 'user_id', 'title', 'description', 'status', 'known_bug_id'];

    public function changelog(): BelongsTo
    {
        return $this->belongsTo(Changelog::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function knownBug(): BelongsTo
    {
        return $this->belongsTo(KnownBug::class);
    }
}
