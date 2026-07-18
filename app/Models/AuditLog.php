<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public $timestamps = false;
    protected $fillable = ['gm_id', 'action', 'target_type', 'target_id', 'meta_json', 'created_at'];
    protected $casts = ['meta_json' => 'array', 'created_at' => 'datetime'];

    public function gm(): BelongsTo
    {
        return $this->belongsTo(User::class, 'gm_id');
    }

    public static function record(int $gmId, string $action, ?string $targetType = null, ?int $targetId = null, array $meta = []): self
    {
        return static::create([
            'gm_id' => $gmId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'meta_json' => $meta,
            'created_at' => now(),
        ]);
    }
}
