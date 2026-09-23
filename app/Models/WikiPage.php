<?php

namespace App\Models;

use App\Support\WingPatterns;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class WikiPage extends Model
{
    use HasFactory, SoftDeletes;

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

    /**
     * The wing this page belongs to, derived from its name.
     *
     * `wiki_compile` already defines this mapping — it slugifies the page name
     * and refuses to compile a page whose wing the token cannot reach. Reusing
     * it here means the read path and the compile path agree, and it needs no
     * column or backfill: on the live instance 28 of 32 pages map to an
     * existing wing by name.
     */
    public function wingSlug(): string
    {
        return Wing::slugify($this->name);
    }

    /**
     * Whether a token holding these patterns may read this page.
     *
     * `null` patterns mean unrestricted. A page whose derived wing matches
     * nothing — `wiki/index`, `wiki/log`, anything off-convention — is
     * unreadable by a restricted token rather than readable by everyone.
     * Failing closed is the point: the alternative is the hole this replaces,
     * and those index pages enumerate other pages by name.
     */
    public function readableWith(?array $patterns): bool
    {
        return static::nameReadableWith($this->name, $patterns);
    }

    /**
     * The same check against a bare page name.
     *
     * WikiSearchService returns raw stdClass rows rather than models, so the
     * predicate cannot assume an instance -- it only needs the name, which both
     * shapes carry.
     */
    public static function nameReadableWith(string $name, ?array $patterns): bool
    {
        return $patterns === null || WingPatterns::matches(Wing::slugify($name), $patterns);
    }

    /**
     * Filter pages -- models or raw rows -- to those a token may read.
     */
    public static function readableSubset($pages, ?array $patterns)
    {
        if ($patterns === null) {
            return $pages;
        }

        return $pages->filter(
            fn ($p) => static::nameReadableWith($p->name, $patterns)
        )->values();
    }

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
