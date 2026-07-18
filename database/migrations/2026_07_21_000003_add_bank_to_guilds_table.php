<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guilds', function (Blueprint $table) {
            $table->unsignedBigInteger('bank_gold')->default(0)->after('member_cap');
            $table->unsignedBigInteger('bank_gems')->default(0)->after('bank_gold');
        });
    }

    public function down(): void
    {
        Schema::table('guilds', function (Blueprint $table) {
            $table->dropColumn(['bank_gold', 'bank_gems']);
        });
    }
};
