<?php

namespace App\Services;

use App\Models\GemLedger;
use App\Models\ReferralMilestone;
use App\Models\User;

/**
 * Invite-a-friend rewards.
 *
 * Referrer rewards (the person who shared the link):
 *   - Every REFERRALS_PER_REWARD friends who reach REQUIRED_LEVEL → REWARD_VIP_DAYS of Gold VIP.
 *   - Every REFERRALS_PER_REWARD friends who reach each milestone level → MILESTONE_GEM_REWARD gems.
 *   Rewards cycle: hitting the threshold again earns another reward, with no cap on total referrals.
 *
 * Referee rewards (each person who used the link):
 *   - Reaching REQUIRED_LEVEL once → REFEREE_BONUS_GEMS gems (one-time).
 *   - Reaching each milestone level → REFEREE_MILESTONE_GEM_REWARD gems (once per milestone).
 *
 * Gating on level (not just signup) is deliberate — it rewards friends who actually play, not
 * free-account farming.
 */
class ReferralService
{
    public const REQUIRED_LEVEL = 5;

    public const REFERRALS_PER_REWARD = 2;

    public const REWARD_VIP_DAYS = 7;

    public const REWARD_VIP_TIER = 'gold';

    public const REFEREE_BONUS_GEMS = 500;

    public const MILESTONE_GEM_REWARD = 100;

    /** Gems awarded to the referred player themselves for each milestone level they personally reach. */
    public const REFEREE_MILESTONE_GEM_REWARD = 29;

    // Milestone levels where both the referrer (every 2 qualifying referrals) and the referee
    // (individually, on first reach) earn gem rewards.
    public const MILESTONE_LEVELS = [10, 25, 50, 100, 150, 200, 250];
    /**
     * Generate additional milestone levels beyond the predefined ones.
     * Pattern: after 250, continue with increments of 50 (300, 350, 400, etc.)
     */
    public static function getMilestoneLevels(int $maxLevel = 1000): array
    {
        $milestones = self::MILESTONE_LEVELS;
        $lastMilestone = end($milestones);
        $increment = 50;

        while ($lastMilestone < $maxLevel) {
            $lastMilestone += $increment;
            $milestones[] = $lastMilestone;
        }

        return $milestones;
    }

    /** Every account gets a stable code lazily on first need rather than at registration, so accounts
     * created before this feature shipped still get one the first time they open the Referrals page.
     * The code is just the account's own id, base64url-encoded — deterministic and inherently unique
     * (no collision loop needed), and trivially decodable back to a user id if ever needed. */
    public function ensureCode(User $user): string
    {
        if ($user->referral_code) {
            return $user->referral_code;
        }

        $user->referral_code = self::encodeUid($user->id);
        $user->save();

        return $user->referral_code;
    }

    /** Base64url (RFC 4648 §5) encoding of an account id — URL/query-string safe, unlike raw base64's
     * '+', '/', and '=' padding. */
    public static function encodeUid(int $id): string
    {
        return rtrim(strtr(base64_encode((string) $id), '+/', '-_'), '=');
    }

    /** Links a brand-new account to whoever referred them. Silently no-ops on an unknown/self code
     * rather than failing registration over a cosmetic attribution field. Case-sensitive match — unlike
     * the old random-uppercase codes, base64url codes are case-sensitive. */
    public function attach(User $newUser, ?string $code): void
    {
        if (! $code || $newUser->referred_by_user_id) {
            return;
        }

        $referrer = User::where('referral_code', trim($code))->first();
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

    /**
     * Check for level milestone rewards and grant gems for qualifying milestones.
     * Returns the number of new referrer-side rewards granted.
     *
     * Two things happen here:
     *  1. Each referee who newly reached a milestone level gets REFEREE_MILESTONE_GEM_REWARD gems
     *     the first time (tracked by referee_reward_granted_at on the milestone row).
     *  2. For every REFERRALS_PER_REWARD referees who have reached a milestone level, the referrer
     *     earns MILESTONE_GEM_REWARD gems (tracked by reward_granted_at).
     */
    public function checkAndGrantMilestones(User $referrer): int
    {
        $milestones = self::getMilestoneLevels();
        $rewardsGranted = 0;

        foreach ($milestones as $milestoneLevel) {
            // All referrals that have reached this milestone level.
            $qualifyingReferrals = $referrer->referrals()
                ->whereHas('characters', fn ($q) => $q->where('level', '>=', $milestoneLevel))
                ->get();

            $qualifyingCount = $qualifyingReferrals->count();

            // Ensure a milestone row exists for every qualifying referee, and grant the referee their
            // per-milestone bonus the first time we see them at this level.
            foreach ($qualifyingReferrals as $referee) {
                $row = ReferralMilestone::where('referrer_id', $referrer->id)
                    ->where('referee_id', $referee->id)
                    ->where('level_milestone', $milestoneLevel)
                    ->first();

                if (! $row) {
                    $row = ReferralMilestone::create([
                        'referrer_id' => $referrer->id,
                        'referee_id' => $referee->id,
                        'level_milestone' => $milestoneLevel,
                        'reward_granted_at' => null,
                        'referee_reward_granted_at' => null,
                    ]);
                }

                // Grant the referee their personal milestone bonus if not yet done.
                if (! $row->referee_reward_granted_at) {
                    $this->grantGemsToUser($referee, self::REFEREE_MILESTONE_GEM_REWARD, "referee_milestone_level_{$milestoneLevel}");
                    $row->update(['referee_reward_granted_at' => now()]);
                }
            }

            // Now check how many referrer-side rewards (every REFERRALS_PER_REWARD) are newly due.
            $milestonesEarned = intdiv($qualifyingCount, self::REFERRALS_PER_REWARD);

            $grantedRows = ReferralMilestone::where('referrer_id', $referrer->id)
                ->where('level_milestone', $milestoneLevel)
                ->whereNotNull('reward_granted_at')
                ->count();

            $alreadyGranted = intdiv($grantedRows, self::REFERRALS_PER_REWARD);
            $newRewards = $milestonesEarned - $alreadyGranted;

            if ($newRewards <= 0) {
                continue;
            }

            for ($i = 0; $i < $newRewards; $i++) {
                // Pick the next REFERRALS_PER_REWARD ungranted rows to mark off for this reward.
                $ungrantedMilestones = ReferralMilestone::where('referrer_id', $referrer->id)
                    ->where('level_milestone', $milestoneLevel)
                    ->whereNull('reward_granted_at')
                    ->limit(self::REFERRALS_PER_REWARD)
                    ->get();

                if ($ungrantedMilestones->count() >= self::REFERRALS_PER_REWARD) {
                    $this->grantGemsToUser($referrer, self::MILESTONE_GEM_REWARD, "referral_milestone_level_{$milestoneLevel}");

                    foreach ($ungrantedMilestones->take(self::REFERRALS_PER_REWARD) as $milestone) {
                        $milestone->update(['reward_granted_at' => now()]);
                    }

                    $rewardsGranted++;
                }
            }
        }

        return $rewardsGranted;
    }

    /**
     * Grants any outstanding referee milestone bonuses to a referred player — called from the
     * scheduled sweep for accounts that haven't triggered a page-load check recently.
     * Returns the number of new milestone bonuses granted.
     */
    public function checkAndGrantRefereeMilestoneBonus(User $referee): int
    {
        if (! $referee->referred_by_user_id) {
            return 0;
        }

        $milestones = self::getMilestoneLevels();
        $granted = 0;

        foreach ($milestones as $milestoneLevel) {
            $hasReached = $referee->characters()
                ->where('level', '>=', $milestoneLevel)
                ->exists();

            if (! $hasReached) {
                continue;
            }

            $row = ReferralMilestone::where('referrer_id', $referee->referred_by_user_id)
                ->where('referee_id', $referee->id)
                ->where('level_milestone', $milestoneLevel)
                ->first();

            if ($row && $row->referee_reward_granted_at) {
                continue; // Already granted.
            }

            if (! $row) {
                $row = ReferralMilestone::create([
                    'referrer_id' => $referee->referred_by_user_id,
                    'referee_id' => $referee->id,
                    'level_milestone' => $milestoneLevel,
                    'reward_granted_at' => null,
                    'referee_reward_granted_at' => null,
                ]);
            }

            $this->grantGemsToUser($referee, self::REFEREE_MILESTONE_GEM_REWARD, "referee_milestone_level_{$milestoneLevel}");
            $row->update(['referee_reward_granted_at' => now()]);
            $granted++;
        }

        return $granted;
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

    /**
     * Grant gems to any user's account and log the transaction.
     */
    private function grantGemsToUser(User $user, int $gemAmount, string $reason): void
    {
        $user->increment('gems', $gemAmount);

        $character = $user->characters()->orderByDesc('level')->first();
        GemLedger::log($user, $gemAmount, $reason, $character);
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

        $referee->increment('gems', self::REFEREE_BONUS_GEMS);
        GemLedger::log($referee, self::REFEREE_BONUS_GEMS, 'referral_bonus', $qualifyingCharacter);

        $referee->referral_bonus_granted_at = now();
        $referee->save();

        return true;
    }
}
