<?php

namespace App\Mcp\Resources;

use App\Models\WikiPage;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Support\UriTemplate;

#[Description('Synthesized wiki page content addressable by name.')]
#[MimeType('text/markdown')]
class WikiPageResource extends Resource implements HasUriTemplate
{
    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('mnemon://wiki/{slug}');
    }

    public function shouldRegister(Request $request): bool
    {
        return (bool) ($request?->user()?->currentAccessToken()?->can('wiki.read') ?? false);
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $slug = $request->get('slug');
        // URL-decode to handle colons encoded as %3A (e.g. person%3Acooper -> person:cooper)
        $name = urldecode($slug);

        $page = WikiPage::where('name', $name)->first();
        if (! $page) {
            return Response::error("Wiki page not found: {$name}");
        }

        return Response::text($page->content ?? '');
    }
}
