<?php

namespace App\Mcp\Resources;

use App\Mcp\Concerns\RequiresWingAccess;
use App\Models\Wing;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Support\UriTemplate;

#[Description('Wing index — rooms in a wing with per-room drawer counts.')]
#[MimeType('text/markdown')]
class WingResource extends Resource implements HasUriTemplate
{
    use RequiresWingAccess;

    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('mnemon://wing/{slug}');
    }

    public function shouldRegister(Request $request): bool
    {
        return (bool) ($request?->user()?->currentAccessToken()?->can('mcp:use') ?? false);
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $slug = urldecode($request->get('slug'));

        if ($err = $this->requireWingAccess($request, $slug)) {
            return $err;
        }

        $wing = Wing::with(['rooms' => fn ($q) => $q->withCount('drawers')])->where('slug', $slug)->first();
        if (! $wing) {
            return Response::error("Wing not found: {$slug}");
        }

        $lines = ["# Wing: {$wing->slug}\n"];
        if ($wing->name && $wing->name !== $wing->slug) {
            $lines[] = "**Name:** {$wing->name}\n";
        }
        $lines[] = "## Rooms\n";
        foreach ($wing->rooms as $room) {
            $count = $room->drawers_count ?? 0;
            $lines[] = "- **{$room->slug}** ({$count} drawers)";
        }

        return Response::text(implode("\n", $lines));
    }
}
