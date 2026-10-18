<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\RequiresScope;
use App\Mcp\Concerns\RequiresWingAccess;
use App\Mcp\Support\BrainSessionLogger;
use App\Services\ContentSanitizer;
use App\Services\Digest\DigestUnavailable;
use App\Services\SessionDigestService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Throwable;

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
            'project' => 'nullable|string|max:100',
            'project_filing' => 'nullable|string|in:model',
            'replay' => 'nullable|string|in:by_start',
        ]);

        $cleaned = $this->sanitizer->sanitize($params['transcript']);

        $audit = [
            'session_id' => $params['session_id'],
            'harness' => $params['harness'],
            'turn_range' => $params['turn_range'],
            'project' => $params['project'] ?? null,
            'project_filing' => $params['project_filing'] ?? null,
        ];

        try {
            $result = $this->digest->run(
                sessionId: $params['session_id'],
                harness: $params['harness'],
                turnRange: $params['turn_range'],
                transcript: $cleaned,
                recentDrawerIds: $params['recent_drawer_ids'] ?? [],
                allowedWingPatterns: $this->wingPatternsFor($request),
                project: $params['project'] ?? null,
                projectFiling: $params['project_filing'] ?? null,
                replayByStart: ($params['replay'] ?? null) === 'by_start',
            );
        } catch (DigestUnavailable $e) {
            // Nothing was persisted and the slice is not recorded, so the same
            // range sent again is digested afresh rather than replayed empty.
            // An error, not an empty success: the client keeps its place.
            BrainSessionLogger::logFailure($request, 'session_digest', $audit, $e->getMessage());

            // The verdict leads: the hooks keep the first 160 characters.
            return Response::error($e->retryable
                ? "Retryable: the digest did not run ({$e->getMessage()}). Nothing was stored; send the same start again, with the same or a later end."
                : "Not retryable: the digest did not run ({$e->getMessage()}). Nothing was stored; retrying fails the same way until the server is fixed.");
        } catch (Throwable $e) {
            // Anything else -- a deadlock, a proposal the service could not
            // persist -- rolled the digest back. It used to escape as a bare
            // JSON-RPC -32603 with no audit row. Its detail stays in the log.
            Log::error('mnemon: session_digest failed', [
                'session_id' => $params['session_id'],
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            BrainSessionLogger::logFailure($request, 'session_digest', $audit, $e::class.': '.$e->getMessage());

            return Response::error('Retryable: the digest did not run (the server failed while storing it). Nothing was stored; send the same start again, with the same or a later end.');
        }

        BrainSessionLogger::log($request, 'session_digest', $audit, count($result['persisted']));

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
            'project' => $s->string()->description('The project the session ran in (e.g. its repository name). Project-specific drawers are filed in that project\'s wing, matched by slug or alias; an unrestricted token creates the wing if it does not exist. Without a project, project-specific drawers go to the `unfiled` wing.'),
            'project_filing' => $s->string()->enum(['model'])->description('Send "model" when the project is left out on purpose (a repository spanning many projects): the model then picks the wing instead of `unfiled`.'),
            'replay' => $s->string()->enum(['by_start'])->description('Send "by_start" when the client advances to the reply\'s `turn_end`: a range whose start this session already digested is then replayed (not digested again) even when its end is later, and `turn_end` says where the recorded slice ended. Without it only the exact same range replays.'),
        ];
    }
}
