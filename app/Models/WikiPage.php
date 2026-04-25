<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class WikiPage extends Model
{
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

    protected $fillable = [
        'name',
        'type',
        'title',
        'content',
        'description',
        'embedding',
        'last_compiled_at',
    ];

    protected $casts = [
        'type' => 'string',
        'last_compiled_at' => 'datetime',
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
