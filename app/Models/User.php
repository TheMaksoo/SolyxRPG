<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\ResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasApiTokens, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'vip_expires_at' => 'datetime',
            'password' => 'hashed',
            'is_tester' => 'boolean',
            'ads_removed' => 'boolean',
        ];
    }

    /** The currently active/selected character (one of possibly several owned slots). */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'active_character_id');
    }

    /** All characters owned by this account, across every slot. */
    public function characters(): HasMany
    {
        return $this->hasMany(Character::class);
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function isGm(): bool
    {
        return in_array($this->role, ['gm', 'owner'], true);
    }

    /** extra character slots (of the 3 subscription slots) each VIP tier unlocks */
    public const VIP_TIER_SLOTS = ['bronze' => 1, 'gold' => 2, 'diamond' => 3];

    public function hasActiveVip(): bool
    {
        return $this->vip_tier !== 'none' && $this->vip_expires_at && $this->vip_expires_at->isFuture();
    }

    public function vipCharacterSlots(): int
    {
        return $this->hasActiveVip() ? (self::VIP_TIER_SLOTS[$this->vip_tier] ?? 0) : 0;
    }

    /** 1 free slot + up to 4 bought with gems + up to 3 more from an active VIP subscription tier. */
    public function maxCharacterSlots(): int
    {
        return 1 + min($this->bonus_character_slots, 4) + $this->vipCharacterSlots();
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
}
