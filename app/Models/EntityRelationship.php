<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntityRelationship extends Model
{
    public const EDGE_TYPES = ['uses', 'depends-on', 'contradicts', 'caused', 'references'];

    protected $fillable = [
        'from_page',
        'to_page',
        'edge_type',
        'description',
    ];

    public function fromPage(): BelongsTo
    {
        return $this->belongsTo(WikiPage::class, 'from_page', 'name');
    }

    public function toPage(): BelongsTo
    {
        return $this->belongsTo(WikiPage::class, 'to_page', 'name');
    }
}
