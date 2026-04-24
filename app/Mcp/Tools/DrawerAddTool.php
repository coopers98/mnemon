<?php

namespace App\Mcp\Tools;

use App\Mcp\BaseTool;
use App\Mcp\McpException;
use App\Models\ApiKey;
use App\Models\Drawer;
use App\Models\Room;
use App\Models\Wing;
use Illuminate\Support\Str;

class DrawerAddTool extends BaseTool
{
    public function requiredScope(): string
    {
        return 'palace:write';
    }

    public function execute(array $params, ApiKey $apiKey): array
    {
        $this->requireScope($apiKey, $this->requiredScope());

        $content = $params['content'] ?? null;
        $wingName = $params['wing'] ?? null;

        if (empty($content)) {
            throw McpException::invalidParams('Parameter "content" is required.');
        }

        if (empty($wingName)) {
            throw McpException::invalidParams('Parameter "wing" is required.');
        }

        $roomName = $params['room'] ?? now()->format('Y-m');
        $source = $params['source'] ?? null;
        $metadata = isset($params['metadata']) && is_array($params['metadata'])
            ? $params['metadata']
            : null;

        $wingSlug = Str::slug($wingName);

        $this->requireWingAccess($apiKey, $wingSlug);

        $wing = Wing::firstOrCreate(
            ['slug' => $wingSlug],
            ['name' => $wingName]
        );

        $roomSlug = Str::slug($roomName);

        $room = Room::firstOrCreate(
            ['wing_id' => $wing->id, 'slug' => $roomSlug],
            ['name' => $roomName, 'wing_id' => $wing->id]
        );

        $drawer = Drawer::create([
            'content' => $content,
            'room_id' => $room->id,
            'source' => $source,
            'metadata' => $metadata,
        ]);

        $result = [
            'drawer_id' => $drawer->id,
            'wing_slug' => $wing->slug,
            'room_slug' => $room->slug,
            'embedding_status' => 'pending',
        ];

        $this->logSession('drawer_add', $apiKey, array_filter([
            'wing' => $wingName,
            'room' => $roomName,
            'source' => $source,
        ]), 1);

        return $result;
    }
}
