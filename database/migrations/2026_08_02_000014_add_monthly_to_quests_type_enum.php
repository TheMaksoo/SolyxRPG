<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Additive: widen the existing enum to also accept 'monthly', alongside daily/weekly/main/raid.
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE quests MODIFY type ENUM('daily', 'weekly', 'monthly', 'main', 'raid') NOT NULL");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE quests MODIFY type ENUM('daily', 'weekly', 'main', 'raid') NOT NULL");
        }
    }
};
