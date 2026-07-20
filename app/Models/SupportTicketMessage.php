<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportTicketMessage extends Model
{
    public $timestamps = false;
    protected $fillable = ['support_ticket_id', 'sender_id', 'body', 'created_at'];
    protected $casts = ['created_at' => 'datetime'];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
