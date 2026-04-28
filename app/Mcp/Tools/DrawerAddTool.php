<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\RequiresScope;
use App\Mcp\Concerns\RequiresWingAccess;
use App\Mcp\Concerns\ResolvesAgentSource;
use App\Mcp\Support\BrainSessionLogger;
use App\Services\DrawerWriteService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Add a drawer (verbatim content) to a room within a wing.')]
class DrawerAddTool extends Tool
{
    use RequiresScope, RequiresWingAccess, ResolvesAgentSource;

    protected string $name = 'drawer_add';


    public function __construct(protected DrawerWriteService $writer) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        if ($err = $this->requireScope($request)) {
            return $err;
        }

        $params = $request->validate([
            'wing' => 'required|string',
            'room' => 'required|string',
            'content' => 'required|string',
            'source' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        if ($err = $this->requireWingAccess($request, $params['wing'])) {
            return $err;
        }

        $drawer = $this->writer->createDrawer(
            wingSlug: $params['wing'],
            roomSlug: $params['room'],
            content: $params['content'],
            source: $this->agentSource($request, $params['source'] ?? null),
            metadata: $params['metadata'] ?? null,
        );

        BrainSessionLogger::log($request, 'drawer_add', $params, 1);

        return Response::structured(['id' => $drawer->id, 'tier' => $drawer->tier]);
    }

    public function schema(JsonSchema $s): array
    {
        return [
            'wing' => $s->string()->required()->description('Wing slug (auto-created if missing).'),
            'room' => $s->string()->required()->description('Room slug within wing.'),
            'content' => $s->string()->required()->description('Verbatim drawer content.'),
            'source' => $s->string()->description('Optional source override; defaults to OAuth client name.'),
            'metadata' => $s->object()->description('Optional JSON metadata.'),
        ];
    }
}
