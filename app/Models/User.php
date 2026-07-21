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
            'tos_accepted_at' => 'datetime',
            'vip_expires_at' => 'datetime',
            'banned_at' => 'datetime',
            'password' => 'hashed',
            'is_tester' => 'boolean',
            'tester_mode_disabled' => 'boolean',
            'ads_removed' => 'boolean',
            'vip_cancel_at_period_end' => 'boolean',
            'vip_gems_granted_at' => 'datetime',
            'referral_bonus_granted_at' => 'datetime',
            'preferences' => 'array',
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

    /** Accounts that signed up using this user's referral code — see ReferralService. */
    public function referrals(): HasMany
    {
        return $this->hasMany(User::class, 'referred_by_user_id');
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_by_user_id');
    }

    public function isGm(): bool
    {
        return in_array($this->role, ['gm', 'owner'], true);
    }

    /** Testers get every title/color/banner unlocked and can freely switch between them — but only while
     * a GM has the "global_tester_mode" feature flag switched on; a designated tester's is_tester flag
     * or role is otherwise inert. GMs/owners always get tester perks regardless, since they need them to
     * QA the game whether or not the flag is on for everyone else. `tester_mode_disabled` is a SEPARATE
     * self-serve on/off switch (see AuthController::toggleTesterMode()) — it never touches the underlying
     * is_tester/role designation, so a tester who switches their own perks off to preview the game as a
     * regular player can always switch back on themselves; only a GM can revoke the designation itself. */
    public function isTester(): bool
    {
        if ($this->isGm()) {
            return true;
        }

        if (! ($this->is_tester || $this->role === 'tester')) {
            return false;
        }

        if ($this->tester_mode_disabled) {
            return false;
        }

        return (bool) FeatureFlag::where('key', 'global_tester_mode')->value('enabled');
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
    public const VIP_TIER_CRAFT_QUEUE_BONUS = ['bronze' => 1, 'gold' => 2, 'diamond' => 3];

    /** Extra daily PvP battle attempts (on top of the 10 base attempts) per VIP tier. */
    public const VIP_TIER_PVP_ATTEMPTS = ['bronze' => 5, 'gold' => 10, 'diamond' => 15];

    /** Extra daily dungeon raid attempts (on top of the 3 base attempts) per VIP tier. Dungeon raids are
     * multi-stage (up to 4 stages for Mythic, with boss adds) and take much longer per attempt than a
     * single PvP match, so both the base allowance and VIP bonuses are scaled well below PvP's. */
    public const VIP_TIER_DUNGEON_ATTEMPTS = ['bronze' => 1, 'gold' => 3, 'diamond' => 5];

    /** Free monthly gem stipend per tier — 25% of that tier's cash price, in gems (at the entry gem-pack's ~68/$ rate). */
    public const VIP_TIER_MONTHLY_GEMS = ['bronze' => 50, 'gold' => 85, 'diamond' => 170];

    /** Flat active-companion-pet cap per VIP tier — always applies regardless of character level. */
    public const VIP_TIER_PET_SLOTS = ['bronze' => 3, 'gold' => 4, 'diamond' => 5];

    /** Extra concurrent active Marketplace listings (on top of the 10 base slots) per VIP tier. */
    public const VIP_TIER_MARKET_LISTINGS = ['bronze' => 5, 'gold' => 10, 'diamond' => 20];

    /** Level milestones that raise the level-earned active pet cap (cumulative — highest reached wins). */
    private const PET_LEVEL_SLOT_TIERS = [1 => 1, 50 => 2, 100 => 3];

    /** How many companion pets this character may have active at once: the better of level progression
     * and an active VIP subscription's flat grant (VIP never stacks on top of level, it's a floor/ceiling). */
    public function maxActivePetSlots(Character $character): int
    {
        $levelSlots = 1;
        foreach (self::PET_LEVEL_SLOT_TIERS as $level => $slots) {
            if ($character->level >= $level) {
                $levelSlots = max($levelSlots, $slots);
            }
        }

        $vipSlots = $this->hasActiveVip() ? (self::VIP_TIER_PET_SLOTS[$this->vip_tier] ?? 0) : 0;

        return max($levelSlots, $vipSlots);
    }

    public function hasActiveVip(): bool
    {
        return $this->vip_tier !== 'none' && $this->vip_expires_at && $this->vip_expires_at->isFuture();
    }

    /** Grants this calendar month's free VIP gem stipend to the character if active VIP and not already claimed
     * this month. Lazily checked wherever character data loads, like every other timed effect in this game.
     * Returns the amount granted (0 if none). */
    public function grantMonthlyVipGemsIfDue(Character $character): int
    {
        if (! $this->hasActiveVip()) {
            return 0;
        }

        if ($this->vip_gems_granted_at && $this->vip_gems_granted_at->isSameMonth(now())) {
            return 0;
        }

        $fallback = self::VIP_TIER_MONTHLY_GEMS[$this->vip_tier] ?? 0;
        $gems = (int) round(GameConfig::number("vip_monthly_gems_{$this->vip_tier}", $fallback));

        if ($gems > 0) {
            $character->increment('gems', $gems);
            GemLedger::log($character, $gems, "vip_monthly_stipend:{$this->vip_tier}");
        }
        $this->vip_gems_granted_at = now();
        $this->save();

        return $gems;
    }

    public function vipCharacterSlots(): int
    {
        return $this->hasActiveVip() ? (self::VIP_TIER_SLOTS[$this->vip_tier] ?? 0) : 0;
    }

    /** Extra concurrent active Marketplace listing slots from an active VIP subscription, on top of
     * MarketplaceController::BASE_LISTING_CAP. */
    public function vipMarketListingBonus(): int
    {
        if (! $this->hasActiveVip()) {
            return 0;
        }

        $fallback = self::VIP_TIER_MARKET_LISTINGS[$this->vip_tier] ?? 0;

        return (int) round(GameConfig::number("vip_market_listings_{$this->vip_tier}", $fallback));
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

    /** Extra concurrent crafting-queue slots from an active VIP subscription. */
    public function vipCraftQueueBonus(): int
    {
        if (! $this->hasActiveVip()) {
            return 0;
        }

        $fallback = self::VIP_TIER_CRAFT_QUEUE_BONUS[$this->vip_tier] ?? 0;

        return (int) round(GameConfig::number("vip_craft_queue_bonus_{$this->vip_tier}", $fallback));
    }

    /** Extra daily PvP battle attempts from an active VIP subscription. */
    public function vipPvpBonusAttempts(): int
    {
        if (! $this->hasActiveVip()) {
            return 0;
        }

        $fallback = self::VIP_TIER_PVP_ATTEMPTS[$this->vip_tier] ?? 0;

        return (int) round(GameConfig::number("vip_pvp_attempts_{$this->vip_tier}", $fallback));
    }

    /** Extra daily dungeon raid attempts from an active VIP subscription. */
    public function vipDungeonBonusAttempts(): int
    {
        if (! $this->hasActiveVip()) {
            return 0;
        }

        $fallback = self::VIP_TIER_DUNGEON_ATTEMPTS[$this->vip_tier] ?? 0;

        return (int) round(GameConfig::number("vip_dungeon_attempts_{$this->vip_tier}", $fallback));
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
