<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** allow_negative_character_points changed these columns to signed integers but didn't restate
 * ->default(0) on the ->change() call, which drops the column default on MySQL — new characters
 * have relied on CharacterController::store() never touching these columns and getting DB default 0,
 * so this silently broke character creation under strict SQL mode.
 *
 * The cleanup below runs first because MODIFY ... NOT NULL DEFAULT '0' fails outright (or truncates
 * data) on some real databases: any existing row already holding NULL, or a value outside the signed
 * INT range (leftover from before allow_negative_character_points, or an old unsigned-underflow), makes
 * MySQL raise "Data truncated for column ... at row N" under strict SQL mode. Sanitizing first makes
 * this migration succeed regardless of what's already sitting in the column on a given environment. */
return new class extends Migration
{
    private const INT_MIN = -2147483648;
    private const INT_MAX = 2147483647;

    public function up(): void
    {
        foreach (['attribute_points', 'skill_points'] as $column) {
            DB::table('characters')->whereNull($column)->update([$column => 0]);
            DB::table('characters')->where($column, '>', self::INT_MAX)->update([$column => self::INT_MAX]);
            DB::table('characters')->where($column, '<', self::INT_MIN)->update([$column => self::INT_MIN]);
        }

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
