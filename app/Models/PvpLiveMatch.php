<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PvpLiveMatch extends Model
{
    public $timestamps = false;
    protected $fillable = [
        'character_a_id', 'character_b_id', 'turn_character_id', 'state_json', 'log_json',
        'status', 'winner_character_id', 'last_action_at', 'created_at',
    ];
    protected $casts = [
        'character_a_id' => 'integer',
        'character_b_id' => 'integer',
        'turn_character_id' => 'integer',
        'winner_character_id' => 'integer',
        'state_json' => 'array',
        'log_json' => 'array',
        'last_action_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function characterA(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'character_a_id');
    }

    public function characterB(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'character_b_id');
    }

    public function turnCharacter(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'turn_character_id');
    }

    public function winner(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'winner_character_id');
    }

    /** 'a' or 'b' — which side the given character is playing in this match, or null if they're not a participant. */
    public function sideFor(int $characterId): ?string
    {
        return match (true) {
            $this->character_a_id === $characterId => 'a',
            $this->character_b_id === $characterId => 'b',
            default => null,
        };
    }

    public function opponentIdFor(int $characterId): ?int
    {
        return match ($this->sideFor($characterId)) {
            'a' => $this->character_b_id,
            'b' => $this->character_a_id,
            default => null,
        };
    }
}
