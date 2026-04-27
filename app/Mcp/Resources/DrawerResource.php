<?php

namespace App\Mcp\Resources;

use App\Mcp\Concerns\RequiresWingAccess;
use App\Models\Drawer;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Support\UriTemplate;

#[Description('Verbatim drawer content addressable by ID.')]
#[MimeType('text/markdown')]
class DrawerResource extends Resource implements HasUriTemplate
{
    use RequiresWingAccess;

    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('mnemon://drawer/{id}');
    }

    public function shouldRegister(Request $request): bool
    {
        return (bool) ($request?->user()?->currentAccessToken()?->can('palace.read') ?? false);
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $drawer = Drawer::with('room.wing')->find($request->get('id'));
        if (! $drawer) {
            return Response::error("Drawer not found: {$request->get('id')}");
        }

        if ($err = $this->requireWingAccess($request, $drawer->room->wing->slug)) {
            return $err;
        }

        return Response::text($drawer->content);
    }
}
