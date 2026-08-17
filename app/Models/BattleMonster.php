<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An "add" fighting alongside the battle's primary monster — only multi-enemy boss encounters
 * (see DungeonService) create these; a normal 1v1 fight has none. Adds only ever basic-attack, they
 * don't run the full ability/cooldown AI the primary monster does. */
class BattleMonster extends Model
{
    public $timestamps = false;
    protected $fillable = ['battle_id', 'monster_id', 'hp', 'hp_max', 'slot', 'defeated_at'];
    protected $casts = ['defeated_at' => 'datetime'];
    protected $appends = ['statuses', 'intent', 'threat', 'effective_atk'];

    public function battle(): BelongsTo
    {
        return $this->belongsTo(Battle::class);
    }

    public function monster(): BelongsTo
    {
        return $this->belongsTo(Monster::class);
    }

    public function isAlive(): bool
    {
        return $this->hp > 0;
    }

    /** Active buff/debuff pills on this add — see StatusEffectService::pillsFor(). Deliberately builds a
     * transient, unsaved Battle instance from battle_id rather than touching the real `battle` relation —
     * see BattleCompanion::getStatusesAttribute() for why accessing $this->battle here would cause
     * infinite recursion the moment this model gets serialized (i.e. every battle API response). */
    public function getStatusesAttribute(): array
    {
        return app(\App\Services\StatusEffectService::class)->pillsFor((new Battle())->forceFill(['id' => $this->battle_id]), 'monster', $this->id);
    }

    /** Read-only preview of this add's next action — see Battle::getMonsterIntentAttribute(). */
    public function getIntentAttribute(): ?array
    {
        if ($this->hp <= 0) {
            return null;
        }

        return app(\App\Services\MonsterAiService::class)->predictIntent($this->monster, []);
    }

    /** Read-only "who's this add taking its next swing at" preview — see
     * ThreatService::topThreatLabel(). Fetches the real Battle by id (rather than the `battle` relation
     * used by getStatusesAttribute's transient trick) since this needs the battle's actual threat_json
     * column, not just its id — a standalone lookup here never gets serialized itself, so it can't
     * trigger the same nested-relation recursion the `battle` relation would. */
    public function getThreatAttribute(): ?array
    {
        if ($this->hp <= 0) {
            return null;
        }

        $battle = Battle::cachedFind($this->battle_id);
        if (! $battle) {
            return null;
        }

        return app(\App\Services\ThreatService::class)->topThreatLabel($battle, 'monster', $this->id, $this->monster?->role);
    }

    /** This add's REAL attack power — see Battle::getMonsterEffectiveAtkAttribute() for why the base
     * Monster::atk column alone doesn't match what it actually hits for. Standalone battle lookup, same
     * reasoning as getThreatAttribute() above (avoids the `battle` relation's serialization recursion). */
    public function getEffectiveAtkAttribute(): ?int
    {
        $battle = Battle::cachedFind($this->battle_id);
        if (! $battle || ! $this->monster) {
            return null;
        }

        $gradeMult = app(\App\Services\GradeService::class)->atkMult($battle->grade);
        $roleMult = app(\App\Services\MonsterAiService::class)->buildFor($this->monster->role)['atk_mult'];

        return (int) round($this->monster->atk * $gradeMult * $roleMult);
    }
}
