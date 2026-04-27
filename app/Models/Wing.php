<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Str;

class Wing extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
    ];

    protected static function booted(): void
    {
        static::creating(function (Wing $wing) {
            if (! $wing->slug) {
                $wing->slug = Str::slug($wing->name);
            }
        });
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    /**
     * HasManyThrough relation used by the Filament WingsTable aggregate count (`drawers_count`).
     */
    public function drawers(): HasManyThrough
    {
        return $this->hasManyThrough(Drawer::class, Room::class);
    }
}
