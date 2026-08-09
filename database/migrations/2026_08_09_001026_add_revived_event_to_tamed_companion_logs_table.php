<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE tamed_companion_logs MODIFY event ENUM('captured', 'died', 'freed', 'revived') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE tamed_companion_logs MODIFY event ENUM('captured', 'died', 'freed') NOT NULL");
    }
};
