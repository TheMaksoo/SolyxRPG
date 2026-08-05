<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ReferralService;
use Illuminate\Console\Command;

/**
 * Backstop sweep for referral rewards — ReferralController::index() already grants both the referrer's
 * milestone reward and the referee's one-time bonus the moment either of them opens the Referrals page,
 * this just catches accounts that never do, so a reward isn't stuck waiting on a page visit.
 */
class CheckReferralMilestones extends Command
{
    protected $signature = 'referrals:check-milestones';

    protected $description = 'Grants any newly-earned referral rewards/bonuses to accounts that haven\'t opened the Referrals page.';

    public function handle(ReferralService $referrals): int
    {
        $rewardsGranted = 0;

        User::whereHas('referrals')->each(function (User $referrer) use ($referrals, &$rewardsGranted) {
            $rewardsGranted += $referrals->checkAndGrant($referrer);
            $rewardsGranted += $referrals->checkAndGrantMilestones($referrer);
        });

        $bonusesGranted = 0;
        $milestoneBonusesGranted = 0;

        User::whereNotNull('referred_by_user_id')
            ->each(function (User $referee) use ($referrals, &$bonusesGranted, &$milestoneBonusesGranted) {
                if ($referrals->checkAndGrantRefereeBonus($referee)) {
                    $bonusesGranted++;
                }
                $milestoneBonusesGranted += $referrals->checkAndGrantRefereeMilestoneBonus($referee);
            });

        $this->info("Referral sweep complete — {$rewardsGranted} referrer reward(s), {$bonusesGranted} referee level-5 bonus(es), {$milestoneBonusesGranted} referee milestone bonus(es) granted.");

        return self::SUCCESS;
    }
}
