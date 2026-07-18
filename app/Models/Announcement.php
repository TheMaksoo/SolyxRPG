<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Announcement extends Model
{
    public $timestamps = false;
    protected $fillable = ['gm_id', 'body', 'created_at'];
    protected $casts = ['created_at' => 'datetime'];

    public function gm(): BelongsTo
    {
        return $this->belongsTo(User::class, 'gm_id');
    }
}
