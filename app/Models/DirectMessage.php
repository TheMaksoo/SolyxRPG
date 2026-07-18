<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DirectMessage extends Model
{
    public $timestamps = false;
    protected $fillable = ['sender_id', 'recipient_id', 'body', 'read_at', 'created_at'];
    protected $casts = ['read_at' => 'datetime', 'created_at' => 'datetime'];

    public function sender(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'sender_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'recipient_id');
    }
}
