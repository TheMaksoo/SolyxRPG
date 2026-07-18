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
            'banned_at' => 'datetime',
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

    public function isBanned(): bool
    {
        return $this->banned_at !== null;
    }

    /** extra character slots (of the 4 subscription slots) each VIP tier unlocks */
    public const VIP_TIER_SLOTS = ['bronze' => 1, 'gold' => 2, 'diamond' => 4];
    public const VIP_TIER_LUCK = ['bronze' => 2, 'gold' => 6, 'diamond' => 12];
    public const VIP_TIER_REGEN_FLAT = ['bronze' => 1, 'gold' => 2, 'diamond' => 4];
    public const VIP_TIER_REGEN_PCT = ['bronze' => 10, 'gold' => 25, 'diamond' => 50];
    public const VIP_TIER_GOLD_XP_PCT = ['bronze' => 10, 'gold' => 20, 'diamond' => 35];
    public const VIP_TIER_CRAFT_SPEED_PCT = ['bronze' => 15, 'gold' => 30, 'diamond' => 50];
    public const VIP_TIER_ENERGY_FLAT = ['bronze' => 1, 'gold' => 2, 'diamond' => 4];
    public const VIP_TIER_ENERGY_PCT = ['bronze' => 10, 'gold' => 25, 'diamond' => 50];

    public function hasActiveVip(): bool
    {
        return $this->vip_tier !== 'none' && $this->vip_expires_at && $this->vip_expires_at->isFuture();
    }

    public function vipCharacterSlots(): int
    {
        return $this->hasActiveVip() ? (self::VIP_TIER_SLOTS[$this->vip_tier] ?? 0) : 0;
    }

    public function vipLuckBonus(): int
    {
        if (! $this->hasActiveVip()) {
            return 0;
        }

        $fallback = self::VIP_TIER_LUCK[$this->vip_tier] ?? 0;

        return (int) round(GameConfig::number("vip_luck_{$this->vip_tier}", $fallback));
    }

    /** Flat HP/mana regen-per-tick bonus from an active VIP subscription. */
    public function vipRegenFlatBonus(): int
    {
        if (! $this->hasActiveVip()) {
            return 0;
        }

        $fallback = self::VIP_TIER_REGEN_FLAT[$this->vip_tier] ?? 0;

        return (int) round(GameConfig::number("vip_regen_flat_{$this->vip_tier}", $fallback));
    }

    /** % bonus applied on top of regen-per-tick from an active VIP subscription. */
    public function vipRegenPctBonus(): float
    {
        if (! $this->hasActiveVip()) {
            return 0;
        }

        $fallback = self::VIP_TIER_REGEN_PCT[$this->vip_tier] ?? 0;

        return GameConfig::number("vip_regen_pct_{$this->vip_tier}", $fallback);
    }

    /** % bonus to gold and XP earned from battles, from an active VIP subscription. */
    public function vipGoldXpBonusPct(): float
    {
        if (! $this->hasActiveVip()) {
            return 0;
        }

        $fallback = self::VIP_TIER_GOLD_XP_PCT[$this->vip_tier] ?? 0;

        return GameConfig::number("vip_gold_xp_pct_{$this->vip_tier}", $fallback);
    }

    /** % reduction to crafting time from an active VIP subscription. */
    public function vipCraftingSpeedBonus(): float
    {
        if (! $this->hasActiveVip()) {
            return 0;
        }

        $fallback = self::VIP_TIER_CRAFT_SPEED_PCT[$this->vip_tier] ?? 0;

        return GameConfig::number("vip_craft_speed_pct_{$this->vip_tier}", $fallback);
    }

    /** Flat Energy regen-per-tick bonus from an active VIP subscription. */
    public function vipEnergyFlatBonus(): int
    {
        if (! $this->hasActiveVip()) {
            return 0;
        }

        $fallback = self::VIP_TIER_ENERGY_FLAT[$this->vip_tier] ?? 0;

        return (int) round(GameConfig::number("vip_energy_flat_{$this->vip_tier}", $fallback));
    }

    /** % bonus applied on top of Energy regen-per-tick from an active VIP subscription. */
    public function vipEnergyPctBonus(): float
    {
        if (! $this->hasActiveVip()) {
            return 0;
        }

        $fallback = self::VIP_TIER_ENERGY_PCT[$this->vip_tier] ?? 0;

        return GameConfig::number("vip_energy_pct_{$this->vip_tier}", $fallback);
    }

    /** up to 4 gem-side slots (1 starter + 3 bought) + up to 4 from an active VIP subscription tier. */
    public function maxCharacterSlots(): int
    {
        return 1 + min($this->bonus_character_slots, 3) + $this->vipCharacterSlots();
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
}
