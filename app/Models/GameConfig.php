<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameConfig extends Model
{
    protected $table = 'game_config';
    protected $fillable = ['key', 'value'];

    /** Request-scoped memoization — game_config is read many times per request (battle, shop, crafting,
     * dungeons, ...), often for the exact same key more than once (e.g. every companion in a fight
     * re-reading the same redirect-% tunable), and was previously a bare query every single time. A
     * plain static array (not the Cache facade) is intentional: this app has no persistent-process
     * runtime (no Octane), so a static array is automatically fresh on every new request — no
     * invalidation logic needed — and it avoids adding a round trip to the 'database' cache store, which
     * would just relocate the same query cost rather than remove it. */
    private static array $memo = [];

    public static function number(string $key, float $default = 1.0): float
    {
        if (! array_key_exists($key, self::$memo)) {
            self::$memo[$key] = static::where('key', $key)->value('value');
        }

        return self::$memo[$key] !== null ? (float) self::$memo[$key] : $default;
    }
}
