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

    /** Records a gem change if it's non-zero — the single entry point every gem-affecting action should go
     * through, so the Inbox's transaction history is a complete picture rather than whatever a few call
     * sites happened to log. */
    public static function log(Character $character, int $delta, string $reason): void
    {
        if ($delta === 0) {
            return;
        }

        self::create(['character_id' => $character->id, 'delta' => $delta, 'reason' => $reason, 'created_at' => now()]);
    }
}
