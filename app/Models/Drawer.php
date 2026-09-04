<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Drawer extends Model
{
    use HasFactory, SoftDeletes;

    public const TIERS = ['raw', 'reviewed', 'consolidated'];

    protected $attributes = [
        'tier' => 'raw',
    ];

    protected $fillable = [
        'content',
        'room_id',
        'source',
        'metadata',
        'embedding',
        'tier',
        'content_hash',
        'access_count',
        'retention_score',
        'last_accessed_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'tier' => 'string',
        'access_count' => 'integer',
        'retention_score' => 'float',
        'last_accessed_at' => 'datetime',
    ];

    /**
     * Keep the content fingerprint in step with the content.
     *
     * A data invariant rather than an observer concern: a drawer without a
     * correct hash would be invisible to deduplication, which is the kind of
     * silent gap this codebase keeps finding.
     */
    protected static function booted(): void
    {
        static::saving(function (self $drawer) {
            if ($drawer->isDirty('content') || $drawer->content_hash === null) {
                $drawer->content_hash = hash('sha256', (string) $drawer->content);
            }
        });
    }

    /**
     * Compute retention score using exponential decay per tier.
     *
     * Half-life values (configurable): raw=30d, reviewed=90d, consolidated=365d
     */
    public function computeRetentionScore(): float
    {
        $halfLives = config('mnemon.retention.half_lives', [
            'raw' => 30,
            'reviewed' => 90,
            'consolidated' => 365,
        ]);

        $halfLife = (float) ($halfLives[$this->tier] ?? 30);
        $lastAccessed = $this->last_accessed_at ?? $this->created_at ?? now();
        $daysSince = (float) abs(now()->diffInDays($lastAccessed));

        // Exponential decay: score = e^(-ln(2) * days / half_life)
        $score = exp(-M_LN2 * $daysSince / $halfLife);

        return round(max(0.0, min(1.0, $score)), 6);
    }

    /**
     * Mutator: convert array embeddings to pgvector literal string.
     */
    protected function embedding(): Attribute
    {
        return Attribute::make(
            set: function (mixed $value) {
                if (is_array($value)) {
                    return DB::raw("'[".implode(',', $value)."]'::vector");
                }

                return $value;
            },
        );
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function wing(): Wing
    {
        return $this->room->wing;
    }
}
