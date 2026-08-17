<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('player', 'tester', 'gm', 'owner', 'artist') NOT NULL DEFAULT 'player'");
    }

    public function down(): void
    {
        DB::table('users')->where('role', 'artist')->update(['role' => 'player']);
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('player', 'tester', 'gm', 'owner') NOT NULL DEFAULT 'player'");
    }
};
