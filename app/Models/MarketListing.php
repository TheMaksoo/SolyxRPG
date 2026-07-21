<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketListing extends Model
{
    protected $fillable = [
        'seller_character_id', 'item_id', 'qty', 'durability', 'durability_max',
        'price_gold', 'status', 'buyer_character_id', 'expires_at', 'sold_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'sold_at' => 'datetime',
    ];

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function sellerCharacter()
    {
        return $this->belongsTo(Character::class, 'seller_character_id');
    }

    public function buyerCharacter()
    {
        return $this->belongsTo(Character::class, 'buyer_character_id');
    }

    public function isExpired(): bool
    {
        return $this->status === 'active' && $this->expires_at->isPast();
    }
}
