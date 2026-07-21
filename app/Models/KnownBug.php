<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KnownBug extends Model
{
    protected $fillable = ['title', 'description', 'area', 'status', 'severity', 'fixed_at'];

    protected $casts = [
        'fixed_at' => 'datetime',
    ];

    // Stamps fixed_at the moment a GM flips status to "fixed" via the Content editor, rather than
    // requiring them to also remember to fill in the date field by hand.
    protected static function booted(): void
    {
        static::saving(function (KnownBug $bug) {
            if ($bug->status === 'fixed' && ! $bug->fixed_at) {
                $bug->fixed_at = now();
            } elseif ($bug->status !== 'fixed') {
                $bug->fixed_at = null;
            }
        });
    }
}
