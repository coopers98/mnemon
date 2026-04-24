<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Drawer extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'content',
        'room_id',
        'source',
        'metadata',
        'embedding',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function wing(): Wing
    {
        return $this->room->wing;
    }
}
