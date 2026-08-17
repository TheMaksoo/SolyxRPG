<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Battle extends Model
{
    protected $fillable = ['character_id', 'monster_id', 'grade', 'character_hp', 'monster_hp', 'monster_hp_max', 'status', 'is_auto', 'log_json', 'revived_with_skill', 'monster_cooldowns_json', 'skill_cooldowns_json', 'tame_eligible', 'threat_json', 'companion_threat_json', 'round'];
    protected $casts = ['character_id' => 'integer', 'log_json' => 'array', 'monster_cooldowns_json' => 'array', 'skill_cooldowns_json' => 'array', 'tame_eligible' => 'boolean', 'threat_json' => 'array', 'companion_threat_json' => 'array', 'round' => 'integer'];
    protected $appends = ['tame_unlock_level', 'player_statuses', 'monster_statuses', 'monster_intent', 'monster_threat', 'monster_effective_atk', 'speed_order', 'roster_full'];

    /** Request-scoped memoization of Battle::find() by id — BattleMonster's getThreatAttribute()/
     * getEffectiveAtkAttribute() each need a real (non-transient) Battle instance and can't safely reuse
     * the `battle` relation (see BattleMonster's docblocks for the serialization-recursion risk), so a
     * pack fight with N adds previously re-issued the identical query N times per response. Same
     * static-array pattern as GameConfig::$memo — safe because this app has no persistent-process
     * runtime (no Octane), so it's automatically fresh every request. */
    private static array $findMemo = [];

    public static function cachedFind(int $id): ?self
    {
        if (! array_key_exists($id, self::$findMemo)) {
            self::$findMemo[$id] = static::find($id);
        }

        return self::$findMemo[$id];
    }

    /** Client-facing mirror of CombatService::attemptTame()'s level gate (see Monster::tameUnlockLevel())
     * — lets BattlePage.vue hide the Tame button before the character is high enough level instead of
     * showing it and letting the click 422. */
    public function getTameUnlockLevelAttribute(): int
    {
        return $this->monster?->tameUnlockLevel() ?? 1;
    }

    /** Active buff/debuff pills on the player — see StatusEffectService::pillsFor(). */
    public function getPlayerStatusesAttribute(): array
    {
        return app(\App\Services\StatusEffectService::class)->pillsFor($this, 'player', null);
    }

    /** Active buff/debuff pills on the primary monster (target_id 0 — it has no BattleMonster row of its
     * own since its hp lives directly on this table; see BattleMonster::getStatusesAttribute() for adds). */
    public function getMonsterStatusesAttribute(): array
    {
        return app(\App\Services\StatusEffectService::class)->pillsFor($this, 'monster', 0);
    }

    /** Read-only preview of the primary monster's next action — computed by the same role-priority rule
     * MonsterAiService actually resolves on its turn (see MonsterAiService::predictIntent()), so the UI
     * can never show an intent that doesn't match what happens next. Null once the monster's defeated. */
    public function getMonsterIntentAttribute(): ?array
    {
        if ($this->monster_hp <= 0) {
            return null;
        }

        return app(\App\Services\MonsterAiService::class)->predictIntent($this->monster, $this->monster_cooldowns_json ?? []);
    }

    /** Read-only "who's this taking its next swing at" preview for the primary monster — see
     * ThreatService::topThreatLabel(). Null once the monster's defeated. */
    public function getMonsterThreatAttribute(): ?array
    {
        if ($this->monster_hp <= 0) {
            return null;
        }

        return app(\App\Services\ThreatService::class)->topThreatLabel($this, 'monster', 0, $this->monster?->role);
    }

    /** The primary monster's REAL attack power — base atk × this encounter's grade multiplier × its
     * role's atk_mult (see GradeService::atkMult()/MonsterAiService::buildFor()), i.e. exactly what
     * CombatService::resolveMonsterAction() actually rolls damage from. Monster::atk alone understates
     * this by up to 2.5x on a Legendary-grade encounter — the enemy card used to show that raw base
     * number, leaving a Champion/Legendary monster's real hitting power invisible until it landed a hit
     * that looked wildly disproportionate to its displayed ATK. */
    public function getMonsterEffectiveAtkAttribute(): ?int
    {
        if (! $this->monster) {
            return null;
        }

        $gradeMult = app(\App\Services\GradeService::class)->atkMult($this->grade);
        $roleMult = app(\App\Services\MonsterAiService::class)->buildFor($this->monster->role)['atk_mult'];

        return (int) round($this->monster->atk * $gradeMult * $roleMult);
    }

    private static array $rosterCountMemo = [];

    /** True once every active companion roster slot is filled — lets BattlePage.vue hide the Tame button
     * before an attempt that would just 422 on CombatService::attemptTame()'s roster-cap check. Memoized
     * by character_id, same reasoning as $findMemo above — cheap insurance against this Battle instance
     * (or another for the same character) getting serialized more than once per response. */
    public function getRosterFullAttribute(): bool
    {
        $cap = $this->character?->user?->tameRosterCap() ?? 0;

        if (! array_key_exists($this->character_id, self::$rosterCountMemo)) {
            self::$rosterCountMemo[$this->character_id] = TamedCompanion::where('character_id', $this->character_id)->whereNull('archived_at')->count();
        }

        return self::$rosterCountMemo[$this->character_id] >= $cap;
    }

    /** Display-only "who's fastest this round" ranking — see MonsterAiService::speedOrder(). */
    public function getSpeedOrderAttribute(): array
    {
        return app(\App\Services\MonsterAiService::class)->speedOrder($this);
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function monster(): BelongsTo
    {
        return $this->belongsTo(Monster::class);
    }

    /** "Adds" fighting alongside the primary monster in a multi-enemy boss encounter — empty for a normal 1v1 fight. */
    public function battleMonsters(): HasMany
    {
        return $this->hasMany(BattleMonster::class)->orderBy('slot');
    }

    /** Tamed companions fighting alongside the player this battle — empty unless the character has at
     * least one active TamedCompanion (see CombatService::start()). */
    public function battleCompanions(): HasMany
    {
        return $this->hasMany(BattleCompanion::class)->orderBy('slot');
    }
}
