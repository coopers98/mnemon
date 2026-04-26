<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class WikiPage extends Model
{
    use SoftDeletes;

    public const TYPES = [
        'person' => 'person',
        'project' => 'project',
        'concept' => 'concept',
        'decision' => 'decision',
        'synthesis' => 'synthesis',
    ];

    public const TYPE_COLORS = [
        'person' => 'success',
        'project' => 'primary',
        'concept' => 'gray',
        'decision' => 'warning',
        'synthesis' => 'info',
    ];

    public const CONFIDENCE_LEVELS = ['high', 'medium', 'low'];

    public const DRAWER_TIERS = ['raw', 'reviewed', 'consolidated'];

    protected $fillable = [
        'name',
        'type',
        'title',
        'content',
        'description',
        'confidence',
        'confidence_score',
        'quality_score',
        'source_count',
        'sources',
        'related',
        'pending_drawers_since_compile',
        'embedding',
        'last_compiled_at',
        'last_accessed_at',
        'revision_count',
        'previous_content_hash',
    ];

    protected $casts = [
        'type' => 'string',
        'confidence_score' => 'float',
        'quality_score' => 'float',
        'source_count' => 'integer',
        'sources' => 'array',
        'related' => 'array',
        'pending_drawers_since_compile' => 'integer',
        'last_compiled_at' => 'datetime',
        'last_accessed_at' => 'datetime',
        'revision_count' => 'integer',
    ];

    public static function typeOptions(): array
    {
        return self::TYPES;
    }

    public static function typeBadgeColor(string $type): string
    {
        return self::TYPE_COLORS[$type] ?? 'gray';
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

    /**
     * Scope: wiki pages that have pending drawer content since their last compile.
     */
    public function scopePendingUpdates($query)
    {
        return $query->where('pending_drawers_since_compile', '>', 0)
            ->orderByDesc('pending_drawers_since_compile');
    }

    protected static function booted(): void
    {
        static::creating(function (WikiPage $page) {
            if (empty($page->title)) {
                $page->title = str($page->name)
                    ->afterLast(':')
                    ->replace(['-', '_'], ' ')
                    ->title()
                    ->toString() ?: $page->name;
            }
        });
    }

    /**
     * Calculate confidence score based on source count and recency.
     *
     * Formula: base score from source_count (capped at 1.0 with 5+ sources),
     * multiplied by a recency factor that decays over time since last_compiled_at.
     */
    public function calculateConfidenceScore(): float
    {
        // Base score: more sources = higher confidence, caps at 1.0 with 5+ sources
        $sourceCount = $this->source_count ?? 0;
        $baseScore = min(1.0, $sourceCount / 5.0);

        // Recency factor: decays over time since last compilation
        $decayDays = (int) config('mnemon.wiki.confidence_decay_days', 90);
        $compiledAt = $this->last_compiled_at;

        if ($compiledAt === null) {
            $recencyFactor = 0.1; // Minimal confidence for never-compiled pages
        } else {
            $daysSinceCompile = (float) abs(now()->diffInDays($compiledAt));
            $recencyFactor = max(0.1, 1.0 - ($daysSinceCompile / $decayDays));
        }

        return round($baseScore * $recencyFactor, 4);
    }

    public function outgoingRelationships(): HasMany
    {
        return $this->hasMany(EntityRelationship::class, 'from_page', 'name');
    }

    public function incomingRelationships(): HasMany
    {
        return $this->hasMany(EntityRelationship::class, 'to_page', 'name');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(WikiPageRevision::class, 'page_name', 'name')->orderByDesc('revision');
    }

    /**
     * Pages that can decay slower — architecture and decision pages have long half-lives.
     */
    public function isLongLived(): bool
    {
        return in_array($this->type, ['decision', 'concept'], true);
    }

    /**
     * Word count accessor — counts whitespace-separated words in the (HTML-stripped) content.
     *
     * NOTE: str_word_count is ASCII-only. Multibyte text (e.g. Chinese, accented Latin in
     * some configurations) may produce inaccurate results. Acceptable for now; revisit if
     * non-Latin content becomes common.
     */
    public function getWordCountAttribute(): int
    {
        return str_word_count(strip_tags($this->content ?? ''));
    }
}
