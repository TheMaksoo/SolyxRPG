<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\User;
use App\Services\LeaderboardService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LeaderboardServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('leaderboard_snapshots');
        Schema::dropIfExists('guild_members');
        Schema::dropIfExists('guilds');
        Schema::dropIfExists('cosmetics');
        Schema::dropIfExists('pvp_records');
        Schema::dropIfExists('characters');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('gems')->default(0);
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('remember_token')->nullable();
            $table->timestamps();
        });

        Schema::create('characters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->string('name');
            $table->string('base_class');
            $table->unsignedInteger('level')->default(1);
            $table->unsignedBigInteger('gold')->default(0);
            $table->unsignedInteger('quests_completed')->default(0);
            $table->unsignedInteger('hp')->default(100);
            $table->unsignedInteger('hp_max')->default(100);
            $table->unsignedInteger('mana')->default(50);
            $table->unsignedInteger('mana_max')->default(50);
            $table->unsignedInteger('base_atk')->default(10);
            $table->unsignedInteger('base_def')->default(5);
            $table->unsignedInteger('battles_won')->default(0);
            $table->unsignedInteger('battles_lost')->default(0);
            $table->unsignedInteger('bosses_slain')->default(0);
            $table->unsignedInteger('times_mined')->default(0);
            $table->unsignedInteger('times_chopped')->default(0);
            $table->unsignedInteger('times_smelted')->default(0);
            $table->unsignedInteger('times_foraged')->default(0);
            $table->unsignedInteger('times_crafted')->default(0);
            $table->foreignId('active_title_id')->nullable();
            $table->foreignId('active_color_id')->nullable();
            $table->foreignId('active_banner_id')->nullable();
            $table->foreignId('active_icon_id')->nullable();
            $table->timestamps();
        });

        Schema::create('cosmetics', function (Blueprint $table) {
            $table->id();
            $table->string('type')->nullable();
            $table->string('name')->nullable();
            $table->string('value')->nullable();
            $table->timestamps();
        });

        Schema::create('guilds', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('tag');
            $table->unsignedInteger('level')->default(1);
            $table->timestamps();
        });

        Schema::create('guild_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guild_id');
            $table->foreignId('character_id');
            $table->string('role')->nullable();
            $table->timestamps();
        });

        Schema::create('pvp_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id');
            $table->unsignedInteger('rating')->default(1000);
            $table->timestamps();
        });

        Schema::create('leaderboard_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('category');
            $table->string('period');
            $table->string('period_key');
            $table->foreignId('character_id');
            $table->unsignedBigInteger('baseline_value')->default(0);
            $table->unsignedInteger('rank_at_boundary')->default(0);
            $table->timestamp('created_at')->nullable();
        });
    }

    public function test_gems_leaderboards_use_account_gems(): void
    {
        $lowGemCharacter = $this->createCharacterWithUserGems(10, 'Low Gems');
        $highGemCharacter = $this->createCharacterWithUserGems(200, 'High Gems');

        $service = app(LeaderboardService::class);

        $allTimeRanking = $service->fullRanking('gems');
        $dailyBoard = $service->board('gems', null, 'daily');

        $this->assertSame(
            [$highGemCharacter->id, $lowGemCharacter->id],
            $allTimeRanking->pluck('character_id')->all()
        );
        $this->assertSame(
            [200, 10],
            $dailyBoard['rows'] ? array_column($dailyBoard['rows'], 'value') : []
        );
    }

    private function createCharacterWithUserGems(int $gems, string $name): Character
    {
        $user = User::factory()->create(['gems' => $gems]);

        return Character::create([
            'user_id' => $user->id,
            'name' => $name,
            'base_class' => 'warrior',
            'level' => 1,
            'hp' => 100,
            'hp_max' => 100,
            'mana' => 50,
            'mana_max' => 50,
            'base_atk' => 10,
            'base_def' => 5,
        ]);
    }
}
