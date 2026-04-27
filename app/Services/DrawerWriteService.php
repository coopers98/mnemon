<?php

namespace App\Services;

use App\Models\Drawer;
use App\Models\Room;
use App\Models\Wing;
use Illuminate\Support\Str;

class DrawerWriteService
{
    public function __construct(
        private readonly ContentSanitizer $sanitizer,
    ) {}

    /**
     * Create a drawer, auto-creating the wing and room if they don't exist.
     * Content is sanitized before storage to redact secrets.
     *
     * @param  string       $wingSlug  Slug for the wing (e.g. "work")
     * @param  string       $roomSlug  Slug for the room (e.g. "notes")
     * @param  string       $content   Verbatim drawer content
     * @param  string       $source    Source attribution (token name or explicit override)
     * @param  array|null   $metadata  Optional JSON metadata
     */
    public function createDrawer(
        string $wingSlug,
        string $roomSlug,
        string $content,
        string $source,
        ?array $metadata = null,
    ): Drawer {
        $wing = Wing::firstOrCreate(
            ['slug' => $wingSlug],
            ['name' => Str::title(str_replace('-', ' ', $wingSlug))],
        );

        $room = Room::firstOrCreate(
            ['wing_id' => $wing->id, 'slug' => $roomSlug],
            ['name' => Str::title(str_replace('-', ' ', $roomSlug)), 'wing_id' => $wing->id],
        );

        return Drawer::create([
            'content'  => $this->sanitizer->sanitize($content),
            'room_id'  => $room->id,
            'source'   => $source,
            'metadata' => $metadata,
            'tier'     => 'raw',
        ]);
    }
}
