<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameConfig extends Model
{
    protected $table = 'game_config';
    protected $fillable = ['key', 'value'];

    public static function number(string $key, float $default = 1.0): float
    {
        $row = static::where('key', $key)->first();

        return $row ? (float) $row->value : $default;
    }
}
