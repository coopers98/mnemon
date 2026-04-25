<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class WikiPage extends Model
{
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

    /**
     * Mutator: convert array embeddings to pgvector literal string.
     */
    protected function embedding(): Attribute
    {
        return Attribute::make(
            set: function (mixed $value) {
                if (is_array($value)) {
                    return DB::raw("'[" . implode(',', $value) . "]'::vector");
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
}
