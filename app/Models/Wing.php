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
        'aliases',
    ];

    protected $casts = [
        'aliases' => 'array',
    ];

    /**
     * The project wing for a project name, matched by slug ("mnemon" →
     * project-mnemon) or by alias ("recital-lineup" → project-cora).
     */
    public static function forProject(string $project): ?self
    {
        $name = static::slugify($project);

        if ($name === '') {
            return null;
        }

        return static::where('slug', "project-{$name}")->first()
            ?? static::where('slug', 'like', 'project-%')
                ->whereNotNull('aliases')
                ->get()
                ->first(fn (Wing $wing) => in_array($name, array_map(static::slugify(...), $wing->aliases), true));
    }

    /**
     * The single canonical wing-slug function.
     *
     * Namespace colons ("project:atlas") become dashes before slugging, so
     * every path that derives a wing slug agrees. Str::slug alone strips the
     * colon entirely, which produced "projectatlas" here while wiki_compile
     * looked up "project-atlas".
     */
    public static function slugify(string $name): string
    {
        return Str::slug(str_replace(':', '-', $name));
    }

    protected static function booted(): void
    {
        static::creating(function (Wing $wing) {
            if (! $wing->slug) {
                $wing->slug = static::slugify($wing->name);
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
