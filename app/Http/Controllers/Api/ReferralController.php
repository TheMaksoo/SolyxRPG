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
        $this->referrals->checkAndGrantRefereeMilestoneBonus($user);
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

        // Build referee's own milestone progress (which milestone gem rewards they've earned/pending).
        $refereeMilestoneProgress = [];
        if ($user->referred_by_user_id) {
            $allMilestones = ReferralService::getMilestoneLevels();
            foreach ($allMilestones as $milestoneLevel) {
                $reached = ($ownBestLevel ?? 0) >= $milestoneLevel;
                $row = \App\Models\ReferralMilestone::where('referrer_id', $user->referred_by_user_id)
                    ->where('referee_id', $user->id)
                    ->where('level_milestone', $milestoneLevel)
                    ->first();
                $claimed = $row && $row->referee_reward_granted_at !== null;
                if ($reached || $claimed) {
                    $refereeMilestoneProgress[] = [
                        'level' => $milestoneLevel,
                        'reached' => $reached,
                        'claimed' => $claimed,
                        'gem_reward' => ReferralService::REFEREE_MILESTONE_GEM_REWARD,
                    ];
                }
            }
            // Also include the next upcoming milestone so the player sees what's coming.
            foreach ($allMilestones as $milestoneLevel) {
                if (($ownBestLevel ?? 0) < $milestoneLevel) {
                    $refereeMilestoneProgress[] = [
                        'level' => $milestoneLevel,
                        'reached' => false,
                        'claimed' => false,
                        'gem_reward' => ReferralService::REFEREE_MILESTONE_GEM_REWARD,
                    ];
                    break;
                }
            }
        }

        // Get milestone progress information
        $milestones = ReferralService::getMilestoneLevels();
        $milestoneProgress = [];

        foreach ($milestones as $milestoneLevel) {
            $qualifyingCount = $user->referrals()
                ->whereHas('characters', fn ($q) => $q->where('level', '>=', $milestoneLevel))
                ->count();

            $grantedRows = \App\Models\ReferralMilestone::where('referrer_id', $user->id)
                ->where('level_milestone', $milestoneLevel)
                ->whereNotNull('reward_granted_at')
                ->count();

            $rewardsGranted = intdiv($grantedRows, ReferralService::REFERRALS_PER_REWARD);
            $milestoneProgress[] = [
                'level' => $milestoneLevel,
                'qualifying_count' => $qualifyingCount,
                'progress' => $qualifyingCount % ReferralService::REFERRALS_PER_REWARD,
                'rewards_claimed' => $rewardsGranted,
                'reward_type' => $milestoneLevel === 5 ? 'vip' : 'gems',
                'reward_amount' => $milestoneLevel === 5 ? ReferralService::REWARD_VIP_DAYS : ReferralService::MILESTONE_GEM_REWARD,
            ];
        }

        // Filter milestones: show completed (2+ users), current (1+ user), and next (0 users)
        $filteredMilestoneProgress = [];
        if (!empty($milestoneProgress)) {
            $highestReachedIndex = -1;
            
            // Find all relevant milestones
            foreach ($milestoneProgress as $index => $milestone) {
                $qualifyingCount = $milestone['qualifying_count'];
                
                // Always show completed milestones (enough users to have earned at least one reward)
                if ($qualifyingCount >= ReferralService::REFERRALS_PER_REWARD) {
                    $filteredMilestoneProgress[] = $milestone;
                    $highestReachedIndex = $index;
                }
                // Show current milestone (some progress but not yet a full reward)
                elseif ($qualifyingCount > 0 && $qualifyingCount < ReferralService::REFERRALS_PER_REWARD) {
                    $filteredMilestoneProgress[] = $milestone;
                    $highestReachedIndex = $index;
                }
                // Show the next unreached milestone only once
                elseif ($qualifyingCount === 0 && $index === $highestReachedIndex + 1) {
                    $filteredMilestoneProgress[] = $milestone;
                    break; // Only show one unreached milestone
                }
            }
            
            // If no users have reached any milestone, show only the first one
            if (empty($filteredMilestoneProgress)) {
                $filteredMilestoneProgress[] = $milestoneProgress[0];
            }
        }
        $milestoneProgress = $filteredMilestoneProgress;

        return response()->json([
            'code' => $code,
            'invite_url' => url("/landing?ref={$code}"),
            'required_level' => ReferralService::REQUIRED_LEVEL,
            'referrals_per_reward' => ReferralService::REFERRALS_PER_REWARD,
            'reward_vip_days' => ReferralService::REWARD_VIP_DAYS,
            'reward_vip_tier' => ReferralService::REWARD_VIP_TIER,
            'referee_bonus_gems' => ReferralService::REFEREE_BONUS_GEMS,
            'milestone_gem_reward' => ReferralService::MILESTONE_GEM_REWARD,
            'referee_milestone_gem_reward' => ReferralService::REFEREE_MILESTONE_GEM_REWARD,
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
