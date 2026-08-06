<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\FeatureFlag;
use App\Models\User;
use App\Notifications\TesterApprovedNotification;
use Illuminate\Console\Command;

class AutoApproveTesterApplications extends Command
{
    protected $signature = 'testers:auto-approve';

    protected $description = 'Approves pending tester applications that have been waiting for at least one hour, when the tester_auto_approve feature flag is enabled.';

    public function handle(): int
    {
        if (! FeatureFlag::where('key', 'tester_auto_approve')->value('enabled')) {
            return self::SUCCESS;
        }

        $pending = User::whereNotNull('tester_applied_at')
            ->whereNull('tester_approved_at')
            ->whereNull('tester_rejection_reason')
            ->where('tester_applied_at', '<=', now()->subHour())
            ->get();

        foreach ($pending as $user) {
            $user->tester_approved_at = now();
            $user->role = 'tester';
            $user->is_tester = true;
            $user->save();

            $user->notify(new TesterApprovedNotification());

            AuditLog::record(0, 'system.tester.auto_approve', 'users', $user->id);
        }

        if ($pending->isNotEmpty()) {
            $this->info("Auto-approved {$pending->count()} tester application(s).");
        }

        return self::SUCCESS;
    }
}
