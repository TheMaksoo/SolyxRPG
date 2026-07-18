<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Purchase extends Model
{
    protected $fillable = ['user_id', 'sku', 'provider', 'amount_cents', 'currency', 'status', 'stripe_session_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
