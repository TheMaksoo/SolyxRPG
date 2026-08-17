<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A single art submission awaiting (or past) GM review — see GmArtController. The "needed" list itself
 * isn't stored anywhere; it's computed live from each content table's own `sprite IS NULL` rows. */
class ArtAsset extends Model
{
    protected $fillable = [
        'entity_type', 'entity_id', 'entity_label', 'grade', 'upload_path',
        'status', 'notes', 'review_notes', 'width', 'height', 'file_size_kb', 'has_alpha',
        'submitted_by', 'reviewed_by', 'reviewed_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
        'has_alpha' => 'boolean',
    ];

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
