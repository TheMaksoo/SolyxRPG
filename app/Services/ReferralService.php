<?php

namespace App\Services;

use App\Models\GemLedger;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Invite-a-friend rewards: every 5 people you referred who actually played to REQUIRED_LEVEL earns you
 * a free week of Gold VIP. Gating on a level (not just a signup) is deliberate — it's what makes this a
 * "get your friends playing" feature instead of a free-account farm, since a referral that never plays
 * is worth nothing to the referrer.
 */
class ReferralService
{
    public const REQUIRED_LEVEL = 5;

    public const REFERRALS_PER_REWARD = 5;

    public const REWARD_VIP_DAYS = 7;

    public const REWARD_VIP_TIER = 'gold';

    public const REFEREE_BONUS_GEMS = 300;

    /** Every account gets a stable code lazily on first need rather than at registration, so accounts
     * created before this feature shipped still get one the first time they open the Referrals page. */
    public function ensureCode(User $user): string
    {
        if ($user->referral_code) {
            return $user->referral_code;
        }

        do {
            $code = Str::upper(Str::random(6));
        } while (User::where('referral_code', $code)->exists());

        $user->referral_code = $code;
        $user->save();

        return $code;
    }

    /** Links a brand-new account to whoever referred them. Silently no-ops on an unknown/self code
     * rather than failing registration over a cosmetic attribution field. */
    public function attach(User $newUser, ?string $code): void
    {
        if (! $code || $newUser->referred_by_user_id) {
            return;
        }

        $referrer = User::where('referral_code', Str::upper(trim($code)))->first();
        if (! $referrer || $referrer->id === $newUser->id) {
            return;
        }

        $newUser->referred_by_user_id = $referrer->id;
        $newUser->save();
    }

    /** How many of this user's referrals have a character that reached the required level — the only
     * referrals that count toward a reward. */
    public function qualifyingCount(User $referrer): int
    {
        return $referrer->referrals()
            ->whereHas('characters', fn ($q) => $q->where('level', '>=', self::REQUIRED_LEVEL))
            ->count();
    }

    /** Grants any reward milestones this referrer has newly earned since the last check. Safe to call
     * repeatedly (e.g. on every Referrals page load, and from the scheduled sweep) — it only ever grants
     * the difference between qualifying milestones and what's already been claimed. Returns how many
     * new rewards were granted this call. */
    public function checkAndGrant(User $referrer): int
    {
        $milestonesEarned = intdiv($this->qualifyingCount($referrer), self::REFERRALS_PER_REWARD);
        $newRewards = $milestonesEarned - $referrer->referral_rewards_claimed;

        if ($newRewards <= 0) {
            return 0;
        }

        for ($i = 0; $i < $newRewards; $i++) {
            $this->grantVipWeek($referrer);
        }

        $referrer->referral_rewards_claimed = $milestonesEarned;
        $referrer->save();

        return $newRewards;
    }

    /** Tops up VIP time on the referrer's current tier if they're already subscribed (never downgrades
     * a better tier), otherwise grants a week of Gold VIP outright. */
    private function grantVipWeek(User $referrer): void
    {
        $base = $referrer->hasActiveVip() ? $referrer->vip_expires_at : now();

        if (! $referrer->hasActiveVip()) {
            $referrer->vip_tier = self::REWARD_VIP_TIER;
        }

        $referrer->vip_expires_at = $base->copy()->addDays(self::REWARD_VIP_DAYS);
        $referrer->save();
    }

    /** One-time bonus for the referred player themselves, separate from the referrer's reward — grants
     * the moment any of their characters reaches REQUIRED_LEVEL. Safe to call repeatedly (same pattern
     * as checkAndGrant): a no-op once referral_bonus_granted_at is set. Returns whether it just granted. */
    public function checkAndGrantRefereeBonus(User $referee): bool
    {
        if (! $referee->referred_by_user_id || $referee->referral_bonus_granted_at) {
            return false;
        }

        $qualifyingCharacter = $referee->characters()
            ->where('level', '>=', self::REQUIRED_LEVEL)
            ->orderByDesc('level')
            ->first();

        if (! $qualifyingCharacter) {
            return false;
        }

        $qualifyingCharacter->increment('gems', self::REFEREE_BONUS_GEMS);
        GemLedger::log($qualifyingCharacter, self::REFEREE_BONUS_GEMS, 'referral_bonus');

        $referee->referral_bonus_granted_at = now();
        $referee->save();

        return true;
    }
}
