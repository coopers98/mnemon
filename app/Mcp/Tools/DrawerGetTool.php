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

        $result = [
            'id' => $drawer->id,
            'content' => $drawer->content,
            'source' => $drawer->source,
            'metadata' => $drawer->metadata,
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
