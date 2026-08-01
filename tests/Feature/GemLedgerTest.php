<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\GemLedger;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GemLedgerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('gem_ledger');
        Schema::dropIfExists('characters');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('gems')->default(0);
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
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

        Schema::create('gem_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->foreignId('character_id')->nullable();
            $table->integer('delta');
            $table->string('reason');
            $table->timestamp('created_at');
        });
    }

    public function test_log_accepts_character_callers_during_migration_window(): void
    {
        $user = $this->createUser();
        $character = Character::create([
            'user_id' => $user->id,
            'name' => 'Hero',
            'base_class' => 'warrior',
            'level' => 1,
        ]);

        GemLedger::log($character, -35, 'auto_battle:15min');
        GemLedger::log($user, 100, 'manual_credit', $character);

        $this->assertDatabaseHas('gem_ledger', [
            'user_id' => $user->id,
            'character_id' => $character->id,
            'delta' => -35,
            'reason' => 'auto_battle:15min',
        ]);

        $this->assertDatabaseHas('gem_ledger', [
            'user_id' => $user->id,
            'character_id' => $character->id,
            'delta' => 100,
            'reason' => 'manual_credit',
        ]);
    }

    private function createUser(): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'Test User '.str()->uuid(),
            'email' => str()->uuid().'@example.com',
            'password' => 'password',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::query()->findOrFail($id);
    }
}
