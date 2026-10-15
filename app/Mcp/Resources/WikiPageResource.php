<?php

namespace App\Mcp\Resources;

use App\Mcp\Concerns\RequiresWingAccess;
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
    use RequiresWingAccess;

    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('mnemon://wiki/{slug}');
    }

    public function shouldRegister(Request $request): bool
    {
        return (bool) ($request?->user()?->currentAccessToken()?->can('mcp:use') ?? false);
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $slug = $request->get('slug');
        // URL-decode to handle colons encoded as %3A (e.g. person%3Acooper -> person:cooper)
        $name = urldecode($slug);

        $page = WikiPage::where('name', $name)->first();

        // The same rule context_get applies: a page outside the token's wings
        // must be indistinguishable from one that does not exist, or this
        // resource is a side door to pages the tools refuse to serve.
        if ($page !== null && ! $page->readableWith($this->wingPatternsFor($request))) {
            $page = null;
        }

        if (! $page) {
            return Response::error("Wiki page not found: {$name}");
        }

        return Response::text($page->content ?? '');
    }
}
