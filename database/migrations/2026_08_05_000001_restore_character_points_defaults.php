<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** allow_negative_character_points changed these columns to signed integers but didn't restate
 * ->default(0) on the ->change() call, which drops the column default on MySQL — new characters
 * have relied on CharacterController::store() never touching these columns and getting DB default 0,
 * so this silently broke character creation under strict SQL mode. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->integer('attribute_points')->default(0)->change();
            $table->integer('skill_points')->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->integer('attribute_points')->change();
            $table->integer('skill_points')->change();
        });
    }
};
