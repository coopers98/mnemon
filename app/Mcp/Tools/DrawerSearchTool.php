<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\RequiresScope;
use App\Mcp\Concerns\RequiresWingAccess;
use App\Mcp\Support\BrainSessionLogger;
use App\Services\DrawerSearchService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Semantic + fulltext search across drawers in the palace.')]
#[IsReadOnly]
class DrawerSearchTool extends Tool
{
    use RequiresScope, RequiresWingAccess;

    protected string $name = 'drawer_search';


    public function __construct(protected DrawerSearchService $search) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        if ($err = $this->requireScope($request)) {
            return $err;
        }

        $params = $request->validate([
            'query' => 'required|string|max:500',
            'limit' => 'integer|min:1|max:50',
            'wing' => 'nullable|string',
        ]);

        if (! empty($params['wing'])) {
            if ($err = $this->requireWingAccess($request, $params['wing'])) {
                return $err;
            }
        }

        $results = $this->search->run(
            query: $params['query'],
            limit: $params['limit'] ?? 10,
            wing: $params['wing'] ?? null,
            allowedWingPatterns: $this->wingPatternsFor($request),
        );

        BrainSessionLogger::log($request, 'drawer_search', $params, count($results));

        return Response::structured(['results' => $results]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Search query.')->required(),
            'limit' => $schema->integer()->description('Max results (1-50).')->default(10),
            'wing' => $schema->string()->description('Optional wing slug to restrict search.'),
        ];
    }
}
