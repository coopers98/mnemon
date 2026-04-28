<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\RequiresScope;
use App\Mcp\Concerns\RequiresWingAccess;
use App\Mcp\Support\BrainSessionLogger;
use App\Models\Drawer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Fetch a single drawer by id.')]
#[IsReadOnly]
class DrawerGetTool extends Tool
{
    use RequiresScope, RequiresWingAccess;

    protected string $name = 'drawer_get';


    public function handle(Request $request): Response|ResponseFactory
    {
        if ($err = $this->requireScope($request)) {
            return $err;
        }

        $params = $request->validate(['id' => 'required|integer']);

        $drawer = Drawer::with('room.wing')->find($params['id']);
        if (! $drawer) {
            return Response::error("Drawer not found: {$params['id']}");
        }

        if ($err = $this->requireWingAccess($request, $drawer->room->wing->slug)) {
            return $err;
        }

        $payload = [
            'id' => $drawer->id,
            'content' => $drawer->content,
            'wing' => $drawer->room->wing->slug,
            'room' => $drawer->room->slug,
            'source' => $drawer->source,
            'metadata' => $drawer->metadata,
            'tier' => $drawer->tier,
            'created_at' => $drawer->created_at?->toIso8601String(),
        ];

        BrainSessionLogger::log($request, 'drawer_get', $params, 1);

        return Response::structured($payload);
    }

    public function schema(JsonSchema $s): array
    {
        return ['id' => $s->integer()->description('Drawer ID.')->required()];
    }
}
