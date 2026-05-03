<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\RequiresScope;
use App\Mcp\Concerns\RequiresWingAccess;
use App\Mcp\Support\BrainSessionLogger;
use App\Services\ContentSanitizer;
use App\Services\SessionDigestService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Digest a sanitized transcript slice into 0-N drawer proposals; persists high-confidence ones, queues new-wing proposals for admin review.')]
class SessionDigestTool extends Tool
{
    use RequiresScope, RequiresWingAccess;

    protected string $name = 'session_digest';

    public function __construct(
        protected SessionDigestService $digest,
        protected ContentSanitizer $sanitizer,
    ) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        if ($err = $this->requireScope($request)) {
            return $err;
        }

        $params = $request->validate([
            'session_id' => 'required|string|max:100',
            'harness' => 'required|string|max:50',
            'turn_range' => 'required|array',
            'turn_range.start' => 'required|integer|min:0',
            'turn_range.end' => 'required|integer|min:0',
            'transcript' => 'required|string|max:200000',
            'recent_drawer_ids' => 'array',
            'recent_drawer_ids.*' => 'integer',
            'auto_persist' => 'boolean',
        ]);

        $cleaned = $this->sanitizer->sanitize($params['transcript']);

        $result = $this->digest->run(
            sessionId: $params['session_id'],
            harness: $params['harness'],
            turnRange: $params['turn_range'],
            transcript: $cleaned,
            recentDrawerIds: $params['recent_drawer_ids'] ?? [],
            allowedWingPatterns: $this->wingPatternsFor($request),
        );

        BrainSessionLogger::log($request, 'session_digest', [
            'session_id' => $params['session_id'],
            'harness' => $params['harness'],
            'turn_range' => $params['turn_range'],
        ], count($result['persisted']));

        return Response::structured($result);
    }

    public function schema(JsonSchema $s): array
    {
        return [
            'session_id' => $s->string()->required()->description('Stable session identifier from the harness.'),
            'harness' => $s->string()->required()->description('Harness name (e.g. claude-code).'),
            'turn_range' => $s->object()->required()->description('Object with start/end turn indices.'),
            'transcript' => $s->string()->required()->description('Sanitized transcript slice (turns since last digest).'),
            'recent_drawer_ids' => $s->array()->description('Drawer IDs already captured this session, for dedup.'),
            'auto_persist' => $s->boolean()->description('When false, returns proposals without writing. Default true.'),
        ];
    }
}
