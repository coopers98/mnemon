<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\RequiresScope;
use App\Mcp\Concerns\RequiresWingAccess;
use App\Mcp\Concerns\ResolvesAgentSource;
use App\Mcp\Support\BrainSessionLogger;
use App\Models\Drawer;
use App\Models\Room;
use App\Models\WikiPage;
use App\Models\Wing;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Gather palace drawers for a wiki page and return compilation context. Marks raw drawers as reviewed.')]
class WikiCompileTool extends Tool
{
    use RequiresScope, RequiresWingAccess, ResolvesAgentSource;

    protected string $name = 'wiki_compile';

    public function handle(Request $request): Response|ResponseFactory
    {
        if ($err = $this->requireScope($request)) {
            return $err;
        }

        $params = $request->validate([
            'name' => 'required|string|max:255',
            'limit' => 'nullable|integer|min:1|max:100',
            'agent_id' => 'nullable|string',
        ]);

        $name = $params['name'];
        $limit = (int) ($params['limit'] ?? 20);

        // Convert wiki page name to wing slug: e.g. "project:atlas" → "project-atlas"
        $wingSlug = Wing::slugify($name);

        if ($err = $this->requireWingAccess($request, $wingSlug)) {
            return $err;
        }

        $wing = Wing::where('slug', $wingSlug)->first();

        if ($wing === null) {
            BrainSessionLogger::log($request, 'wiki_compile', ['name' => $name], 0);

            return Response::error("No wing found matching page name '{$name}' (looked for slug '{$wingSlug}')");
        }

        $resolvedAgent = $this->agentSource($request, $params['agent_id'] ?? null);

        // Wrap drawer-tier update + page read in a transaction with lockForUpdate so
        // concurrent compile calls don't race on the same drawer rows or wiki page state.
        $result = DB::transaction(function () use ($name, $wing, $limit, $resolvedAgent) {
            $roomIds = Room::where('wing_id', $wing->id)->pluck('id');

            $drawerModels = Drawer::whereIn('room_id', $roomIds)
                ->orderByDesc('created_at')
                ->take($limit)
                ->lockForUpdate()
                ->get();

            // Mark raw drawers as reviewed when gathered for compilation
            $rawDrawerIds = $drawerModels->where('tier', 'raw')->pluck('id');
            if ($rawDrawerIds->isNotEmpty()) {
                Drawer::whereIn('id', $rawDrawerIds)->update(['tier' => 'reviewed']);
            }

            $drawers = $drawerModels->map(fn ($d) => [
                'id' => $d->id,
                'content' => $d->content,
                'source' => $d->source,
                'tier' => $d->tier,
                'created_at' => $d->created_at->toIso8601String(),
            ])->values()->all();

            // Lock the wiki page row to prevent concurrent reads of stale state
            $page = WikiPage::where('name', $name)->lockForUpdate()->first();

            return [
                'page' => $page ? [
                    'name' => $page->name,
                    'current_content' => $page->content,
                    'last_compiled_at' => $page->last_compiled_at?->toIso8601String(),
                    'confidence' => $page->confidence,
                    'revision_count' => $page->revision_count,
                ] : null,
                'drawers' => $drawers,
                'drawer_count' => count($drawers),
                'wing' => [
                    'name' => $wing->name,
                    'slug' => $wing->slug,
                ],
                'agent' => $resolvedAgent,
            ];
        });

        BrainSessionLogger::log($request, 'wiki_compile', [
            'name' => $name,
            'limit' => $limit,
        ], $result['drawer_count']);

        return Response::structured($result);
    }

    public function schema(JsonSchema $s): array
    {
        return [
            'name' => $s->string()->required()->description('Wiki page name (e.g. "project:atlas", "person:alice"). Used to locate the corresponding palace wing.'),
            'limit' => $s->integer()->description('Max drawers to gather (1-100). Default 20.')->default(20),
            'agent_id' => $s->string()->description('Agent identifier for audit purposes. Defaults to OAuth client name.'),
        ];
    }
}
