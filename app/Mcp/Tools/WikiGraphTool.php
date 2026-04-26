<?php

namespace App\Mcp\Tools;

use App\Mcp\BaseTool;
use App\Mcp\McpException;
use App\Models\ApiKey;
use App\Models\EntityRelationship;
use App\Models\WikiPage;
use App\Services\KnowledgeGraphService;

class WikiGraphTool extends BaseTool
{
    public function __construct(private readonly KnowledgeGraphService $graphService) {}

    public function requiredScope(): string
    {
        return 'wiki:read';
    }

    public function execute(array $params, ApiKey $apiKey): array
    {
        $this->requireScope($apiKey, $this->requiredScope());

        $startPage = $params['start_page'] ?? null;
        if (empty($startPage)) {
            throw McpException::invalidParams('Parameter "start_page" is required.');
        }

        $page = WikiPage::where('name', $startPage)->first();
        if ($page === null) {
            $this->logSession('wiki_graph', $apiKey, $params, 0);

            return ['error' => 'Wiki page not found', 'name' => $startPage];
        }

        $maxDepth = (int) ($params['max_depth'] ?? 2);
        if ($maxDepth < 1 || $maxDepth > 5) {
            throw McpException::invalidParams('Parameter "max_depth" must be between 1 and 5.');
        }

        $edgeTypes = $params['edge_types'] ?? null;
        if ($edgeTypes !== null) {
            if (! is_array($edgeTypes)) {
                throw McpException::invalidParams('Parameter "edge_types" must be an array.');
            }
            $validTypes = EntityRelationship::EDGE_TYPES;
            foreach ($edgeTypes as $type) {
                if (! in_array($type, $validTypes, true)) {
                    throw McpException::invalidParams(
                        "Invalid edge type: {$type}. Valid types: ".implode(', ', $validTypes)
                    );
                }
            }
        }

        $graph = $this->graphService->traverse($startPage, $maxDepth, $edgeTypes);

        $this->logSession('wiki_graph', $apiKey, $params, $graph['node_count']);

        return $graph;
    }
}
