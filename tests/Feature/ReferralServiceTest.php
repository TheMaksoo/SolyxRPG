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

        $this->createQualifyingReferral($referrer, 10);
        $this->createQualifyingReferral($referrer, 10);

        // With REFERRALS_PER_REWARD = 1, each qualifying referral earns its own reward.
        // 2 referrals reaching level 10 → 2 rewards (200 gems total).
        $this->assertSame(2, $service->checkAndGrantMilestones($referrer->fresh()));
        $this->assertSame(200, $referrer->fresh()->gems);
        $this->assertSame(2, ReferralMilestone::where('referrer_id', $referrer->id)->whereNotNull('reward_granted_at')->count());
        $this->assertDatabaseCount('gem_ledger', 2);

        $this->createQualifyingReferral($referrer, 10);
        $this->createQualifyingReferral($referrer, 10);

        // 2 more referrals → 2 more rewards (400 gems total, 4 ledger entries).
        $this->assertSame(2, $service->checkAndGrantMilestones($referrer->fresh()));
        $this->assertSame(400, $referrer->fresh()->gems);
        $this->assertSame(4, ReferralMilestone::where('referrer_id', $referrer->id)->whereNotNull('reward_granted_at')->count());
        $this->assertDatabaseCount('gem_ledger', 4);
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
