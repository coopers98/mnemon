<?php

namespace App\Mcp\Tools;

use App\Mcp\BaseTool;
use App\Mcp\McpException;
use App\Models\ApiKey;
use App\Services\PalaceSearchService;

class DrawerSearchTool extends BaseTool
{
    public function __construct(
        private readonly PalaceSearchService $searchService,
    ) {}

    public function requiredScope(): string
    {
        return 'palace:read';
    }

    public function execute(array $params, ApiKey $apiKey): array
    {
        $this->requireScope($apiKey, $this->requiredScope());

        $query = $params['query'] ?? null;

        if (empty($query)) {
            throw McpException::invalidParams('Parameter "query" is required.');
        }

        $wing = $params['wing'] ?? null;
        $room = $params['room'] ?? null;
        $limit = isset($params['limit']) ? (int) $params['limit'] : 5;
        $mode = $params['mode'] ?? 'hybrid';

        $limit = max(1, min($limit, (int) config('mnemon.retrieval.max_limit', 20)));

        $validModes = ['semantic', 'fulltext', 'hybrid'];
        if (! in_array($mode, $validModes, true)) {
            throw McpException::invalidParams('Parameter "mode" must be one of: semantic, fulltext, hybrid.');
        }

        if ($wing !== null) {
            $this->requireWingAccess($apiKey, $wing);
        }

        $results = $this->searchService->search($query, $wing, $room, $limit, $mode);

        $mapped = $results->map(fn ($r) => [
            'id' => $r->id,
            'content' => $r->content,
            'wing' => $r->wing,
            'wing_slug' => $r->wing_slug,
            'room' => $r->room,
            'room_slug' => $r->room_slug,
            'source' => $r->source,
            'metadata' => $r->metadata,
            'created_at' => $r->created_at,
            'score' => $r->score,
        ])->values()->all();

        $this->logSession('drawer_search', $apiKey, [
            'query' => $query,
            'wing' => $wing,
            'room' => $room,
            'limit' => $limit,
            'mode' => $mode,
        ], count($mapped));

        return ['results' => $mapped];
    }
}
