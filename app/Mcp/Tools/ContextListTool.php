<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\RequiresScope;
use App\Mcp\Support\BrainSessionLogger;
use App\Models\WikiPage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List wiki pages, optionally filtered by type.')]
#[IsReadOnly]
class ContextListTool extends Tool
{
    use RequiresScope;

    protected string $name = 'context_list';

    protected string $scope = 'wiki.read';

    public function handle(Request $request): Response|ResponseFactory
    {
        if ($err = $this->requireScope($request)) {
            return $err;
        }

        $params = $request->validate([
            'type' => 'nullable|in:person,project,concept,decision,synthesis',
            'limit' => 'integer|min:1|max:200',
        ]);

        $query = WikiPage::query()->orderBy('name');
        if (! empty($params['type'])) {
            $query->where('type', $params['type']);
        }

        $pages = $query->limit($params['limit'] ?? 100)
            ->get(['id', 'name', 'title', 'type', 'description', 'confidence', 'pending_drawers_since_compile', 'last_compiled_at', 'content'])
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'type' => $p->type,
                'title' => $p->title,
                'description' => $p->description,
                'confidence' => $p->confidence,
                'pending_drawers_since_compile' => $p->pending_drawers_since_compile,
                'last_compiled_at' => $p->last_compiled_at?->toIso8601String(),
                'word_count' => $p->content ? str_word_count($p->content) : 0,
            ])->all();

        BrainSessionLogger::log($request, 'context_list', $params, count($pages));

        return Response::structured(['pages' => $pages]);
    }

    public function schema(JsonSchema $s): array
    {
        return [
            'type' => $s->string()->enum(['person', 'project', 'concept', 'decision', 'synthesis'])->description('Filter by page type.'),
            'limit' => $s->integer()->description('Max pages (1-200, default 100).')->default(100),
        ];
    }
}
