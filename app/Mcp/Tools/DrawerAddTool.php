<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\RequiresScope;
use App\Mcp\Concerns\RequiresWingAccess;
use App\Mcp\Concerns\ResolvesAgentSource;
use App\Mcp\Support\BrainSessionLogger;
use App\Models\Wing;
use App\Services\DrawerWriteService;
use Carbon\Carbon;
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
            'occurred_at' => 'nullable|date',
        ]);

        // Slugged the way Wing's creating event slugs a name, so the stored
        // slug -- the thing consent patterns are built from -- never carries
        // characters such as `%` or `_` from the raw argument.
        $params['wing'] = Wing::slugify($params['wing']);
        if ($params['wing'] === '') {
            return Response::error('Parameter "wing" must contain at least one letter or digit.');
        }

        if ($err = $this->requireWingAccess($request, $params['wing'])) {
            return $err;
        }

        $drawer = $this->writer->createDrawer(
            wingSlug: $params['wing'],
            roomSlug: $params['room'],
            content: $params['content'],
            source: $this->agentSource($request, $params['source'] ?? null),
            metadata: $params['metadata'] ?? null,
            occurredAt: isset($params['occurred_at']) ? Carbon::parse($params['occurred_at']) : null,
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
            'occurred_at' => $s->string()->description('Optional ISO-8601 time the content originally happened; a later time is clamped to now.'),
        ];
    }
}
