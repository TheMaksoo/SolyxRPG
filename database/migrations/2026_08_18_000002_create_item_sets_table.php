<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_sets', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('class_key')->nullable();
            $table->json('bonus_2pc_json')->nullable();
            $table->json('bonus_3pc_json')->nullable();
            $table->json('bonus_4pc_json')->nullable();
            $table->timestamps();
        });

        Schema::table('items', function (Blueprint $table) {
            $table->string('set_key')->nullable()->after('class_key');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('set_key');
        });
        Schema::dropIfExists('item_sets');
    }
};
