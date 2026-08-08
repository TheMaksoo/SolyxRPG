<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Timestamp set when a user submits a tester application. Null = never applied.
            $table->timestamp('tester_applied_at')->nullable()->after('tester_mode_disabled');
            // Timestamp set when a GM approves the application. Null = still pending or rejected.
            $table->timestamp('tester_approved_at')->nullable()->after('tester_applied_at');
            // Set on rejection so the applicant can see why.
            $table->string('tester_rejection_reason')->nullable()->after('tester_approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['tester_applied_at', 'tester_approved_at', 'tester_rejection_reason']);
        });
    }
};
