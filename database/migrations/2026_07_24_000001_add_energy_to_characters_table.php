<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->unsignedInteger('energy')->default(100)->after('mana_max');
            $table->unsignedInteger('energy_max')->default(100)->after('energy');
            $table->timestamp('last_energy_regen_at')->nullable()->after('energy_max');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn(['energy', 'energy_max', 'last_energy_regen_at']);
        });
    }
};
