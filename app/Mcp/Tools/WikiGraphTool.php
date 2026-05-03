<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\RequiresScope;
use App\Mcp\Support\BrainSessionLogger;
use App\Models\EntityRelationship;
use App\Models\WikiPage;
use App\Services\KnowledgeGraphService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Traverse the knowledge graph from a starting wiki page and return nodes and edges up to a configurable depth.')]
#[IsReadOnly]
class WikiGraphTool extends Tool
{
    use RequiresScope;

    protected string $name = 'wiki_graph';

    public function __construct(private readonly KnowledgeGraphService $graphService) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        if ($err = $this->requireScope($request)) {
            return $err;
        }

        $params = $request->validate([
            'start_page' => 'required|string|max:255',
            'max_depth' => 'nullable|integer|min:1|max:5',
            'edge_types' => 'nullable|array',
            'edge_types.*' => 'string|in:'.implode(',', EntityRelationship::EDGE_TYPES),
        ]);

        $startPage = $params['start_page'];
        $maxDepth = (int) ($params['max_depth'] ?? 2);
        $edgeTypes = $params['edge_types'] ?? null;

        $page = WikiPage::where('name', $startPage)->first();

        if ($page === null) {
            BrainSessionLogger::log($request, 'wiki_graph', ['start_page' => $startPage], 0);

            return Response::error("Wiki page not found: {$startPage}");
        }

        $graph = $this->graphService->traverse($startPage, $maxDepth, $edgeTypes);

        BrainSessionLogger::log($request, 'wiki_graph', $params, $graph['node_count']);

        return Response::structured($graph);
    }

    public function schema(JsonSchema $s): array
    {
        $validTypes = implode(', ', EntityRelationship::EDGE_TYPES);

        return [
            'start_page' => $s->string()->required()->description('Name of the starting wiki page (e.g. "person:alice").'),
            'max_depth' => $s->integer()->description('Maximum traversal depth (1-5). Default 2.')->default(2),
            'edge_types' => $s->array($s->string())->description("Filter edges by type. Valid: {$validTypes}. Omit for all types."),
        ];
    }
}
