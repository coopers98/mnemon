<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Entity extends Model
{
    public const TYPES = ['person', 'project', 'decision', 'system', 'concept'];

    protected $fillable = [
        'name',
        'type',
        'source_page',
        'description',
    ];

    public function outgoingRelationships(): HasMany
    {
        return $this->hasMany(EntityRelationship::class, 'from_page', 'source_page');
    }
}
