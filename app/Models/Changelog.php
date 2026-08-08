<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Changelog extends Model
{
    protected $fillable = ['version', 'title', 'body', 'tag', 'visibility', 'published_at', 'pushed_live_at'];

    protected $casts = [
        'published_at' => 'datetime',
        'pushed_live_at' => 'datetime',
    ];

    // GMs shouldn't have to remember to also set a timestamp — an entry is live the moment it's
    // created, and that's also what makes its version the "current" one (see ChangelogController).
    protected static function booted(): void
    {
        static::creating(function (Changelog $entry) {
            $entry->published_at ??= now();
        });
    }

    public function bugReports(): HasMany
    {
        return $this->hasMany(TesterBugReport::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(TesterUpdateVote::class);
    }
}
