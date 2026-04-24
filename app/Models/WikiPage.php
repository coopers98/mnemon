<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    protected static function booted(): void
    {
        static::creating(function (WikiPage $page) {
            if (empty($page->title)) {
                // Auto-generate title from name: "project:atlas" → "Atlas"
                $page->title = str($page->name)
                    ->afterLast(':')
                    ->replace(['-', '_'], ' ')
                    ->title()
                    ->toString() ?: $page->name;
            }
        });
    }
}
