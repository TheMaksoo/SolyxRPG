<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE battles MODIFY status ENUM('active', 'won', 'lost', 'fled') DEFAULT 'active'");
    }

    public function down(): void
    {
        DB::statement("UPDATE battles SET status = 'lost' WHERE status = 'fled'");
        DB::statement("ALTER TABLE battles MODIFY status ENUM('active', 'won', 'lost') DEFAULT 'active'");
    }
};
