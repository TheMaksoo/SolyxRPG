<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('battles', function (Blueprint $table) {
            // Null = the ≤10%-hp tame-eligibility roll hasn't happened yet this fight; true/false caches
            // its result so repeated tame attempts against the same target don't re-roll it every time.
            $table->boolean('tame_eligible')->nullable()->after('monster_hp_max');
        });

        DB::statement("ALTER TABLE battles MODIFY status ENUM('active', 'won', 'lost', 'fled', 'tamed') DEFAULT 'active'");
    }

    public function down(): void
    {
        DB::statement("UPDATE battles SET status = 'lost' WHERE status = 'tamed'");
        DB::statement("ALTER TABLE battles MODIFY status ENUM('active', 'won', 'lost', 'fled') DEFAULT 'active'");

        Schema::table('battles', function (Blueprint $table) {
            $table->dropColumn('tame_eligible');
        });
    }
};
