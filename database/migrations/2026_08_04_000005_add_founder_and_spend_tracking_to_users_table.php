<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('lifetime_spend_cents')->default(0)->after('vip_lifetime');
            $table->boolean('is_founder')->default(false)->after('lifetime_spend_cents');
            $table->timestamp('founder_purchased_at')->nullable()->after('is_founder');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['lifetime_spend_cents', 'is_founder', 'founder_purchased_at']);
        });
    }
};
