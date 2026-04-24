<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Wing extends Model
{
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
}
