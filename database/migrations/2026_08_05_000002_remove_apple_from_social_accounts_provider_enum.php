<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Remove the unused 'apple' provider value. Any existing rows with provider='apple'
        // must be cleaned up before running this migration.
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `social_accounts` MODIFY COLUMN `provider` ENUM('discord', 'google') NOT NULL");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `social_accounts` MODIFY COLUMN `provider` ENUM('discord', 'google', 'apple') NOT NULL");
        }
    }
};
