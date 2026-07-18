<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Character extends Model
{
    protected $fillable = [
        'user_id', 'name', 'base_class', 'spec_class', 'profession', 'ascension',
        'avatar', 'level', 'xp', 'gold', 'gems', 'hp', 'hp_max', 'mana', 'mana_max',
        'base_atk', 'base_def', 'skill_points', 'attribute_points', 'current_zone_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attributes_(): HasOne
    {
        return $this->hasOne(CharacterAttribute::class);
    }

    public function skills(): HasMany
    {
        return $this->hasMany(CharacterSkill::class);
    }

    public function inventory(): HasMany
    {
        return $this->hasMany(Inventory::class);
    }

    public function quests(): HasMany
    {
        return $this->hasMany(CharacterQuest::class);
    }

    public function pets(): HasMany
    {
        return $this->hasMany(CharacterPet::class);
    }

    public function dailyClaim(): HasOne
    {
        return $this->hasOne(DailyClaim::class);
    }

    public function guildMembership(): HasOne
    {
        return $this->hasOne(GuildMember::class);
    }

    public function battlePasses(): HasMany
    {
        return $this->hasMany(BattlePass::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class, 'current_zone_id');
    }

    public function sentFriendRequests(): HasMany
    {
        return $this->hasMany(Friendship::class, 'requester_id');
    }

    public function receivedFriendRequests(): HasMany
    {
        return $this->hasMany(Friendship::class, 'addressee_id');
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(CharacterFavorite::class);
    }

    public function pvpRecord(): HasOne
    {
        return $this->hasOne(PvpRecord::class);
    }

    public function achievements(): HasMany
    {
        return $this->hasMany(CharacterAchievement::class);
    }

    /** Accepted friendships in either direction, as a collection of the *other* character. */
    public function friends()
    {
        $sent = $this->sentFriendRequests()->where('status', 'accepted')->with('addressee')->get()->pluck('addressee');
        $received = $this->receivedFriendRequests()->where('status', 'accepted')->with('requester')->get()->pluck('requester');

        return $sent->concat($received);
    }

    /** Effective combat stats after attribute points, level, and equipped gear, per build guide §7 */
    public function effectiveStats(): array
    {
        $attr = $this->attributes_ ?? new CharacterAttribute();

        $equipped = $this->relationLoaded('inventory')
            ? $this->inventory->where('equipped', true)
            : $this->inventory()->where('equipped', true)->with('item')->get();

        $gearAtk = 0;
        $gearDef = 0;
        foreach ($equipped as $slot) {
            $stats = $slot->item->stat_json ?? [];
            $gearAtk += $stats['atk'] ?? 0;
            $gearDef += $stats['def'] ?? 0;
        }

        $effAtk = $this->base_atk + $attr->damage * 5 + $gearAtk;
        $effDef = $this->base_def + $attr->armor * 4 + $gearDef;
        $effHpMax = $this->hp_max + $attr->hp * 30;
        $effMpMax = $this->mana_max + $attr->mp * 20;
        $critChance = 18 + $attr->crit * 2;

        return [
            'eff_atk' => $effAtk,
            'eff_def' => $effDef,
            'eff_hp_max' => $effHpMax,
            'eff_mp_max' => $effMpMax,
            'crit_chance' => $critChance,
            'power' => $effAtk * 4 + $effDef * 3 + $effHpMax,
        ];
    }

    public static function xpForLevel(int $level): int
    {
        $xp = 500;
        for ($i = 1; $i < $level; $i++) {
            $xp = (int) round($xp * 1.3);
        }

        return $xp;
    }
}
