<?php

namespace App\Mcp\Tools;

use App\Mcp\BaseTool;
use App\Mcp\McpException;
use App\Models\ApiKey;
use App\Models\Drawer;

class DrawerGetTool extends BaseTool
{
    public function requiredScope(): string
    {
        return 'palace:read';
    }

    public function execute(array $params, ApiKey $apiKey): array
    {
        $this->requireScope($apiKey, $this->requiredScope());

        $id = $params['id'] ?? null;

        if (empty($id)) {
            throw McpException::invalidParams('Parameter "id" is required.');
        }

        $drawer = Drawer::with(['room.wing'])->find((int) $id);

        if ($drawer === null) {
            throw McpException::notFound("Drawer with id {$id} not found.");
        }

        $wing = $drawer->room->wing;

        $this->requireWingAccess($apiKey, $wing->slug);

        // Item 14: Retention — track access count + timestamp on read
        $drawer->access_count = ($drawer->access_count ?? 0) + 1;
        $drawer->last_accessed_at = now();
        $drawer->save();

        $result = [
            'id' => $drawer->id,
            'content' => $drawer->content,
            'source' => $drawer->source,
            'metadata' => $drawer->metadata,
            'tier' => $drawer->tier,
            'access_count' => $drawer->access_count,
            'retention_score' => $drawer->retention_score,
            'last_accessed_at' => $drawer->last_accessed_at?->toIso8601String(),
            'wing' => $wing->name,
            'wing_slug' => $wing->slug,
            'room' => $drawer->room->name,
            'room_slug' => $drawer->room->slug,
            'created_at' => $drawer->created_at->toIso8601String(),
            'updated_at' => $drawer->updated_at->toIso8601String(),
        ];

        $this->logSession('drawer_get', $apiKey, ['id' => $id], 1);

        return $result;
    }
}
