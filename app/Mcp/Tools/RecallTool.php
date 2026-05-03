<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\RequiresScope;
use App\Mcp\Concerns\RequiresWingAccess;
use App\Mcp\Support\BrainSessionLogger;
use App\Services\RecallService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Hybrid recall: returns the most relevant wiki excerpts and drawer snippets for a given prompt, packed into a token budget. Used by harness hooks to silently inject palace context.')]
#[IsReadOnly]
class RecallTool extends Tool
{
    use RequiresScope, RequiresWingAccess;

    protected string $name = 'recall';

    public function __construct(protected RecallService $recall) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        if ($err = $this->requireScope($request)) {
            return $err;
        }

        $params = $request->validate([
            'prompt' => 'required|string|max:4000',
            'token_budget' => 'integer|min:200|max:4000',
            'wing' => 'nullable|string',
        ]);

        if (! empty($params['wing'])) {
            if ($err = $this->requireWingAccess($request, $params['wing'])) {
                return $err;
            }
        }

        $payload = $this->recall->run(
            prompt: $params['prompt'],
            tokenBudget: $params['token_budget'] ?? (int) config('mnemon.recall.default_token_budget', 1500),
            wing: $params['wing'] ?? null,
            allowedWingPatterns: $this->wingPatternsFor($request),
        );

        $count = count($payload['wiki']) + count($payload['drawers']);
        BrainSessionLogger::log($request, 'recall', $params, $count);

        return Response::structured($payload);
    }

    public function schema(JsonSchema $s): array
    {
        return [
            'prompt' => $s->string()->required()->description('The user prompt to find context for.'),
            'token_budget' => $s->integer()->description('Max tokens of context to return (200-4000). Default 1500.'),
            'wing' => $s->string()->description('Optional wing slug to restrict recall to.'),
        ];
    }
}
