<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One stack of a status effect (buff/debuff) applied to a battle participant — see StatusEffectService
 * for the effect catalog and all the math. Every application is its own row with its own countdown
 * (never merged into an existing stack), so two different-magnitude applications of the same effect_key
 * on one target both stay alive and expire independently, summing their magnitude while both are active. */
class BattleStatusEffect extends Model
{
    protected $fillable = ['battle_id', 'target_type', 'target_id', 'effect_key', 'magnitude', 'remaining_rounds', 'source_label'];

    protected $casts = [
        'magnitude' => 'float',
        'remaining_rounds' => 'integer',
    ];

    public function battle(): BelongsTo
    {
        return $this->belongsTo(Battle::class);
    }
}
