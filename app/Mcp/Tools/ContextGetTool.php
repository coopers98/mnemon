<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\RequiresScope;
use App\Mcp\Concerns\RequiresWingAccess;
use App\Mcp\Support\BrainSessionLogger;
use App\Models\Drawer;
use App\Models\WikiPage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Read a synthesized wiki page by name.')]
#[IsReadOnly]
class ContextGetTool extends Tool
{
    use RequiresScope, RequiresWingAccess;

    protected string $name = 'context_get';

    public function handle(Request $request): Response|ResponseFactory
    {
        if ($err = $this->requireScope($request)) {
            return $err;
        }

        $params = $request->validate(['name' => 'required|string|max:255']);

        $page = WikiPage::where('name', $params['name'])->first();

        // A page outside the token's wings must be indistinguishable from one
        // that does not exist, or the error message becomes an existence
        // oracle across wings -- the same reasoning ContextSetTool applies to
        // source drawers.
        if ($page !== null && ! $page->readableWith($this->wingPatternsFor($request))) {
            $page = null;
        }

        if (! $page) {
            BrainSessionLogger::log($request, 'context_get', $params, 0);

            return Response::error("Wiki page not found: {$params['name']}");
        }

        $page->update(['last_accessed_at' => now()]);

        $payload = [
            'id' => $page->id,
            'name' => $page->name,
            'type' => $page->type,
            'title' => $page->title,
            'description' => $page->description,
            'confidence' => $page->confidence,
            'sources' => $page->sources,
            'related' => $page->related,
            'confidence_score' => $page->confidence_score,
            'source_count' => $page->source_count,
            'pending_drawers_since_compile' => $page->pending_drawers_since_compile,
            'revision_count' => $page->revision_count,
            'content' => $page->content,
            'last_compiled_at' => $page->last_compiled_at?->toIso8601String(),
            'last_accessed_at' => $page->last_accessed_at?->toIso8601String(),
            'word_count' => $page->content ? str_word_count($page->content) : 0,
        ];

        if (! empty($page->sources)) {
            $payload['source_details'] = Drawer::whereIn('id', $page->sources)->get()->map(fn ($d) => [
                'id' => $d->id,
                'content_preview' => mb_substr($d->content, 0, 200),
                'source' => $d->source,
            ])->all();
        }

        BrainSessionLogger::log($request, 'context_get', $params, 1);

        return Response::structured($payload);
    }

    public function schema(JsonSchema $s): array
    {
        return ['name' => $s->string()->required()->description('Wiki page name (e.g. "person:cooper").')];
    }
}
