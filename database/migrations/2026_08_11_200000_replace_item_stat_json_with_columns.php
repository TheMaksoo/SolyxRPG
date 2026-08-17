<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Replaces items.stat_json (one freeform JSON blob) with a real, named column per stat — a future GM
// "balance editor" role can then bind a form field directly to e.g. `atk` instead of hand-editing JSON.
// See app/Models/Item.php's STAT_KEYS/getStatJsonAttribute()/setStatJsonAttribute(): every existing
// consumer that reads/writes `$item->stat_json` keeps working unchanged via that virtual attribute,
// which assembles/spreads the same ['atk' => 42, ...] shape from/to these columns.
return new class extends Migration
{
    private const STAT_KEYS = [
        'atk', 'def', 'crit', 'dodge_pct', 'mp', 'luck', 'lifesteal_pct',
        'heal_hp_flat', 'heal_hp_pct', 'heal_mp_flat', 'heal_mp_pct',
        'hp_regen_pct_buff', 'mana_regen_pct_buff', 'duration_seconds',
        'atk_pct_buff', 'buff_fights', 'revive_chance_pct', 'pet_xp',
        'gather_speed_pct', 'gather_yield_bonus', 'craft_speed_pct',
    ];

    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            // Signed, not unsigned — specialty gear's "tradeoff penalty" stores atk/def as negative.
            foreach (self::STAT_KEYS as $key) {
                $table->integer($key)->nullable()->after('stat_json');
            }
        });

        foreach (DB::table('items')->whereNotNull('stat_json')->get(['id', 'stat_json']) as $row) {
            $stats = json_decode($row->stat_json, true) ?? [];
            $update = array_intersect_key($stats, array_flip(self::STAT_KEYS));
            if ($update !== []) {
                DB::table('items')->where('id', $row->id)->update($update);
            }
        }

        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('stat_json');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->json('stat_json')->nullable()->after('description');
        });

        foreach (DB::table('items')->get(['id', ...self::STAT_KEYS]) as $row) {
            $stats = [];
            foreach (self::STAT_KEYS as $key) {
                if ($row->$key !== null) {
                    $stats[$key] = $row->$key;
                }
            }
            DB::table('items')->where('id', $row->id)->update(['stat_json' => json_encode($stats)]);
        }

        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(self::STAT_KEYS);
        });
    }
};
