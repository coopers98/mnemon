<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Drawer extends Model
{
    use SoftDeletes;

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
    ];

    protected $casts = [
        'metadata' => 'array',
        'tier' => 'string',
    ];

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
