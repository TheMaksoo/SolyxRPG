<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReferralService;
use Illuminate\Http\Request;

class ReferralController extends Controller
{
    public function __construct(private ReferralService $referrals) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $code = $this->referrals->ensureCode($user);

        // Self-service check on every page load, in addition to the scheduled sweep in
        // CheckReferralMilestones — so a referrer who just qualified (or a referee who just hit the
        // required level) sees the reward immediately instead of waiting for the next cron tick.
        $this->referrals->checkAndGrant($user);
        $this->referrals->checkAndGrantMilestones($user);
        $this->referrals->checkAndGrantRefereeBonus($user);
        $user->refresh();

        $referred = $user->referrals()->with('characters')->latest('created_at')->get()->map(function ($friend) {
            $bestLevel = $friend->characters->max('level') ?? 0;

            return [
                'name' => $friend->name,
                'joined_at' => $friend->created_at,
                'level' => $bestLevel,
                'qualified' => $bestLevel >= ReferralService::REQUIRED_LEVEL,
            ];
        });

        $qualifying = $referred->where('qualified', true)->count();
        $referrer = $user->referred_by_user_id ? $user->referrer : null;
        $ownBestLevel = $user->relationLoaded('characters')
            ? $user->characters->max('level')
            : $user->characters()->max('level');

        // Get milestone progress information
        $milestones = ReferralService::getMilestoneLevels();
        $milestoneProgress = [];

        foreach ($milestones as $milestoneLevel) {
            $qualifyingCount = $user->referrals()
                ->whereHas('characters', fn ($q) => $q->where('level', '>=', $milestoneLevel))
                ->count();

            $rewardsGranted = \App\Models\ReferralMilestone::where('referrer_id', $user->id)
                ->where('level_milestone', $milestoneLevel)
                ->whereNotNull('reward_granted_at')
                ->count();

            $milestoneProgress[] = [
                'level' => $milestoneLevel,
                'qualifying_count' => $qualifyingCount,
                'progress' => $qualifyingCount % ReferralService::REFERRALS_PER_REWARD,
                'rewards_claimed' => $rewardsGranted,
                'reward_type' => $milestoneLevel === 5 ? 'vip' : 'gems',
                'reward_amount' => $milestoneLevel === 5 ? ReferralService::REWARD_VIP_DAYS : ReferralService::MILESTONE_GEM_REWARD,
            ];
        }

        return response()->json([
            'code' => $code,
            'invite_url' => url("/landing?ref={$code}"),
            'required_level' => ReferralService::REQUIRED_LEVEL,
            'referrals_per_reward' => ReferralService::REFERRALS_PER_REWARD,
            'reward_vip_days' => ReferralService::REWARD_VIP_DAYS,
            'reward_vip_tier' => ReferralService::REWARD_VIP_TIER,
            'referee_bonus_gems' => ReferralService::REFEREE_BONUS_GEMS,
            'milestone_gem_reward' => ReferralService::MILESTONE_GEM_REWARD,
            'qualifying_count' => $qualifying,
            'progress_to_next' => $qualifying % ReferralService::REFERRALS_PER_REWARD,
            'rewards_claimed' => $user->referral_rewards_claimed,
            'milestone_progress' => $milestoneProgress,
            'referred' => $referred,
            // Null unless this account itself was referred by someone else — see ReferralsPage's
            // "You were referred by..." card.
            'referred_by' => $referrer ? [
                'name' => $referrer->name,
                'own_level' => $ownBestLevel ?? 0,
                'bonus_claimed' => $user->referral_bonus_granted_at !== null,
            ] : null,
        ]);
    }

    /** Fired when the player clicks "Copy" on their invite link or code — pure engagement signal for
     * GM Console (see GmAnalyticsController::headline's referral_copies), separate from actual signups. */
    public function trackCopy(Request $request)
    {
        $request->user()->increment('referral_link_copies');

        return response()->json(['ok' => true]);
    }
}
