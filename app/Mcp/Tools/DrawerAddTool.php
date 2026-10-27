<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\RequiresScope;
use App\Mcp\Concerns\RequiresWingAccess;
use App\Mcp\Concerns\ResolvesAgentSource;
use App\Mcp\Support\BrainSessionLogger;
use App\Models\Wing;
use App\Services\DrawerWriteService;
use App\Support\ProjectWings;
use Carbon\Carbon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Add a drawer (verbatim content) to a room within a wing.')]
class DrawerAddTool extends Tool
{
    use RequiresScope, RequiresWingAccess, ResolvesAgentSource;

    protected string $name = 'drawer_add';

    public function __construct(protected DrawerWriteService $writer) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        if ($err = $this->requireScope($request)) {
            return $err;
        }

        $params = $request->validate([
            'wing' => 'required_without:project|prohibits:project|nullable|string',
            'project' => 'required_without:wing|prohibits:wing|nullable|string|max:100',
            'room' => 'required|string',
            'content' => 'required|string',
            'source' => 'nullable|string',
            'metadata' => 'nullable|array',
            'occurred_at' => 'nullable|date',
        ]);

        if (isset($params['project'])) {
            $slug = $this->wingForProject($request, $params['project']);
            if ($slug instanceof Response) {
                return $slug;
            }
            $params['wing'] = $slug;
        } else {
            // Slugged the way Wing's creating event slugs a name, so the stored
            // slug -- the thing consent patterns are built from -- never carries
            // characters such as `%` or `_` from the raw argument.
            $params['wing'] = Wing::slugify($params['wing']);
            if ($params['wing'] === '') {
                return Response::error('Parameter "wing" must contain at least one letter or digit.');
            }

            if ($err = $this->requireWingAccess($request, $params['wing'])) {
                return $err;
            }
        }

        $drawer = $this->writer->createDrawer(
            wingSlug: $params['wing'],
            roomSlug: $params['room'],
            content: $params['content'],
            source: $this->agentSource($request, $params['source'] ?? null),
            metadata: $params['metadata'] ?? null,
            occurredAt: isset($params['occurred_at']) ? Carbon::parse($params['occurred_at']) : null,
        );

        BrainSessionLogger::log($request, 'drawer_add', $params, 1);

        return Response::structured([
            'id' => $drawer->id,
            'tier' => $drawer->tier,
            'wing' => $params['wing'],
            'created' => $drawer->wasRecentlyCreated,
        ]);
    }

    /**
     * The wing a project files into, or the refusal to return. A project whose
     * wing the token cannot reach is refused exactly as a missing wing is.
     */
    private function wingForProject(Request $request, string $project): string|Response
    {
        $name = Wing::slugify($project);
        if ($name === '') {
            return Response::error('Parameter "project" must contain at least one letter or digit.');
        }

        $patterns = $this->wingPatternsFor($request);
        $ids = Wing::readableIds($patterns);
        $wings = Wing::query()
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->get()
            ->all();

        $slug = ProjectWings::wingSlugForProject($project, $wings, $patterns);
        if ($slug !== null) {
            return $slug;
        }

        $missing = "project-{$name}";

        return $this->requireWingAccess($request, $missing)
            ?? Response::error("Token does not have access to wing: {$missing}");
    }

    public function schema(JsonSchema $s): array
    {
        return [
            'wing' => $s->string()->description('Wing slug (auto-created if missing). Give exactly one of wing or project.'),
            'project' => $s->string()->description('Project name; files into that project\'s wing, aliases included, the same wing session digests use. Give exactly one of wing or project.'),
            'room' => $s->string()->required()->description('Room slug within wing.'),
            'content' => $s->string()->required()->description('Verbatim drawer content.'),
            'source' => $s->string()->description('Optional source override; defaults to OAuth client name.'),
            'metadata' => $s->object()->description('Optional JSON metadata.'),
            'occurred_at' => $s->string()->description('Optional ISO-8601 time the content originally happened; a later time is clamped to now.'),
        ];
    }
}
