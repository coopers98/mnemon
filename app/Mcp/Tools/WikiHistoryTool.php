<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\RequiresScope;
use App\Mcp\Support\BrainSessionLogger;
use App\Models\WikiPage;
use App\Models\WikiPageRevision;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Return the revision history for a wiki page, ordered newest-first.')]
#[IsReadOnly]
class WikiHistoryTool extends Tool
{
    use RequiresScope;

    protected string $name = 'wiki_history';


    public function handle(Request $request): Response|ResponseFactory
    {
        if ($err = $this->requireScope($request)) {
            return $err;
        }

        $params = $request->validate([
            'name' => 'required|string|max:255',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        $name = $params['name'];
        $limit = (int) ($params['limit'] ?? 10);

        $page = WikiPage::where('name', $name)->first();

        if ($page === null) {
            BrainSessionLogger::log($request, 'wiki_history', ['name' => $name], 0);

            return Response::error("Wiki page not found: {$name}");
        }

        $revisions = WikiPageRevision::where('page_name', $name)
            ->orderByDesc('revision')
            ->limit($limit)
            ->get()
            ->map(fn (WikiPageRevision $r) => [
                'revision' => $r->revision,
                'content_hash' => $r->content_hash,
                'agent_id' => $r->agent_id,
                'written_at' => $r->written_at?->toIso8601String(),
            ])
            ->all();

        $result = [
            'name' => $page->name,
            'revision_count' => $page->revision_count ?? 1,
            'previous_content_hash' => $page->previous_content_hash,
            'last_compiled_at' => $page->last_compiled_at?->toIso8601String(),
            'last_accessed_at' => $page->last_accessed_at?->toIso8601String(),
            'confidence_score' => $page->confidence_score,
            'quality_score' => $page->quality_score,
            'source_count' => $page->source_count,
            'revisions' => $revisions,
        ];

        BrainSessionLogger::log($request, 'wiki_history', ['name' => $name], count($revisions));

        return Response::structured($result);
    }

    public function schema(JsonSchema $s): array
    {
        return [
            'name' => $s->string()->required()->description('Wiki page name (e.g. "person:alice", "project:atlas").'),
            'limit' => $s->integer()->description('Max revisions to return (1-50). Default 10.')->default(10),
        ];
    }
}
