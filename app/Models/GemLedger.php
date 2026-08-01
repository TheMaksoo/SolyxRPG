<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GemLedger extends Model
{
    public $timestamps = false;
    protected $table = 'gem_ledger';
    protected $fillable = ['user_id', 'character_id', 'delta', 'reason', 'created_at'];
    protected $casts = ['created_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    /** Records a gem change if it's non-zero — the single entry point every gem-affecting action should go
     * through, so the Inbox's transaction history is a complete picture rather than whatever a few call
     * sites happened to log. Now logs at the user level (account-wide gems), with optional character_id
     * for context about which character triggered the transaction. */
    public static function log(User $user, int $delta, string $reason, ?Character $character = null): void
    {
        if ($delta === 0) {
            return;
        }

        self::create([
            'user_id' => $user->id,
            'character_id' => $character?->id,
            'delta' => $delta,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }

    /** Legacy method signature for backward compatibility during migration. Routes to user-level logging. */
    public static function logForCharacter(Character $character, int $delta, string $reason): void
    {
        self::log($character->user, $delta, $reason, $character);
    }
}
