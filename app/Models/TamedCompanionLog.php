<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only history of a character's tamed companions — captured/died/freed — mirroring
 * GemLedger's log-everything-through-one-entry-point pattern. */
class TamedCompanionLog extends Model
{
    public $timestamps = false;
    protected $fillable = ['character_id', 'tamed_companion_id', 'event', 'level', 'created_at'];
    protected $casts = ['created_at' => 'datetime'];

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function tamedCompanion(): BelongsTo
    {
        return $this->belongsTo(TamedCompanion::class);
    }

    public static function log(TamedCompanion $companion, string $event): void
    {
        self::create([
            'character_id' => $companion->character_id,
            'tamed_companion_id' => $companion->id,
            'event' => $event,
            'level' => $companion->level,
            'created_at' => now(),
        ]);
    }
}
