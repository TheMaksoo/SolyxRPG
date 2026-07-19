<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->unsignedInteger('result_qty')->default(1)->after('craft_seconds');
        });
        Schema::table('crafting_jobs', function (Blueprint $table) {
            $table->unsignedInteger('result_qty')->default(1)->after('result_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->dropColumn('result_qty');
        });
        Schema::table('crafting_jobs', function (Blueprint $table) {
            $table->dropColumn('result_qty');
        });
    }
};
