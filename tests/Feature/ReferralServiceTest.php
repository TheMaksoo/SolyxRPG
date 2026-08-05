<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\ReferralMilestone;
use App\Models\User;
use App\Services\ReferralService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReferralServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('gem_ledger');
        Schema::dropIfExists('referral_milestones');
        Schema::dropIfExists('characters');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('gems')->default(0);
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('referral_code')->nullable();
            $table->foreignId('referred_by_user_id')->nullable();
            $table->unsignedInteger('referral_rewards_claimed')->default(0);
            $table->timestamp('referral_bonus_granted_at')->nullable();
            $table->string('vip_tier')->default('none');
            $table->timestamp('vip_expires_at')->nullable();
            $table->boolean('vip_lifetime')->default(false);
            $table->timestamps();
        });

        Schema::create('characters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->string('name');
            $table->string('base_class');
            $table->unsignedInteger('level')->default(1);
            $table->timestamps();
        });

        Schema::create('referral_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_id');
            $table->foreignId('referee_id');
            $table->unsignedInteger('level_milestone');
            $table->timestamp('reward_granted_at')->nullable();
            $table->timestamp('referee_reward_granted_at')->nullable();
            $table->timestamps();
            $table->unique(['referrer_id', 'referee_id', 'level_milestone']);
        });

        Schema::create('gem_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->foreignId('character_id')->nullable();
            $table->integer('delta');
            $table->string('reason');
            $table->timestamp('created_at');
        });
    }

    public function test_milestone_rewards_are_granted_immediately_and_multiple_times_per_level(): void
    {
        $service = $this->app->make(ReferralService::class);
        $referrer = $this->createUser();

        $referee1 = $this->createQualifyingReferral($referrer, 10);
        $referee2 = $this->createQualifyingReferral($referrer, 10);

        // 2 referrals reaching level 10 → 1 referrer reward (100 gems).
        // Each referee also gets REFEREE_MILESTONE_GEM_REWARD (20 gems) individually.
        $this->assertSame(1, $service->checkAndGrantMilestones($referrer->fresh()));
        $this->assertSame(100, $referrer->fresh()->gems);
        $this->assertSame(20, $referee1->fresh()->gems);
        $this->assertSame(20, $referee2->fresh()->gems);
        $this->assertSame(2, ReferralMilestone::where('referrer_id', $referrer->id)->whereNotNull('reward_granted_at')->count());
        $this->assertSame(2, ReferralMilestone::where('referrer_id', $referrer->id)->whereNotNull('referee_reward_granted_at')->count());
        // Ledger: 1 referrer reward + 2 referee bonuses = 3 entries.
        $this->assertDatabaseCount('gem_ledger', 3);

        $referee3 = $this->createQualifyingReferral($referrer, 10);
        $referee4 = $this->createQualifyingReferral($referrer, 10);

        // 2 more referrals → 1 more referrer reward, 2 more referee bonuses.
        $this->assertSame(1, $service->checkAndGrantMilestones($referrer->fresh()));
        $this->assertSame(200, $referrer->fresh()->gems);
        $this->assertSame(20, $referee3->fresh()->gems);
        $this->assertSame(20, $referee4->fresh()->gems);
        $this->assertSame(4, ReferralMilestone::where('referrer_id', $referrer->id)->whereNotNull('reward_granted_at')->count());
        $this->assertDatabaseCount('gem_ledger', 6);
    }

    public function test_referee_milestone_bonus_granted_independently(): void
    {
        $service = $this->app->make(ReferralService::class);
        $referrer = $this->createUser();
        $referee = $this->createQualifyingReferral($referrer, 10);

        // Only 1 qualifying referral — referrer doesn't reach the 2-per-reward threshold.
        $service->checkAndGrantMilestones($referrer->fresh());
        $this->assertSame(0, $referrer->fresh()->gems); // No referrer reward yet.
        $this->assertSame(20, $referee->fresh()->gems); // Referee still gets their 20 gems.
        $this->assertSame(0, ReferralMilestone::where('referrer_id', $referrer->id)->whereNotNull('reward_granted_at')->count());
        $this->assertSame(1, ReferralMilestone::where('referrer_id', $referrer->id)->whereNotNull('referee_reward_granted_at')->count());
    }

    public function test_referee_milestone_bonus_idempotent(): void
    {
        $service = $this->app->make(ReferralService::class);
        $referrer = $this->createUser();
        $this->createQualifyingReferral($referrer, 10);

        $service->checkAndGrantMilestones($referrer->fresh());
        $service->checkAndGrantMilestones($referrer->fresh()); // Second call — should not double-grant.

        $this->assertDatabaseCount('gem_ledger', 1); // Only 1 referee bonus, no double-grant.
    }

    private function createUser(?int $referrerId = null): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'Test User '.str()->uuid(),
            'email' => str()->uuid().'@example.com',
            'password' => 'password',
            'referred_by_user_id' => $referrerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::query()->findOrFail($id);
    }

    private function createQualifyingReferral(User $referrer, int $level): User
    {
        $referee = $this->createUser($referrer->id);

        Character::create([
            'user_id' => $referee->id,
            'name' => 'Referee '.$referee->id,
            'base_class' => 'warrior',
            'level' => $level,
        ]);

        return $referee;
    }
}
