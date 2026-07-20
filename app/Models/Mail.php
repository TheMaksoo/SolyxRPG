<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Mail extends Model
{
    public $timestamps = false;
    protected $fillable = ['recipient_user_id', 'sender_gm_id', 'subject', 'body', 'read_at', 'dismissed_at', 'created_at'];
    protected $casts = [
        'recipient_user_id' => 'integer',
        'sender_gm_id' => 'integer',
        'read_at' => 'datetime',
        'dismissed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_gm_id');
    }
}
