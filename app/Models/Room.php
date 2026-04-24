<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Room extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'wing_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (Room $room) {
            if (! $room->slug) {
                $room->slug = Str::slug($room->name);
            }
        });
    }

    public function wing(): BelongsTo
    {
        return $this->belongsTo(Wing::class);
    }

    public function drawers(): HasMany
    {
        return $this->hasMany(Drawer::class);
    }
}
