<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportTicket extends Model
{
    protected $fillable = ['user_id', 'subject', 'body', 'priority', 'status', 'assigned_gm_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignedGm(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_gm_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class)->orderBy('created_at');
    }
}
