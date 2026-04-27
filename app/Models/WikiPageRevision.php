<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WikiPageRevision extends Model
{
    use HasFactory;

    protected $fillable = [
        'page_name',
        'revision',
        'content',
        'content_hash',
        'agent_id',
        'written_at',
    ];

    protected $casts = [
        'written_at' => 'datetime',
        'revision' => 'integer',
    ];

    public function page(): BelongsTo
    {
        return $this->belongsTo(WikiPage::class, 'page_name', 'name');
    }
}
