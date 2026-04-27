<?php

namespace App\Mcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

#[Description('Find wiki pages that have not been compiled recently and may be stale.')]
class FindStaleWikiPagesPrompt extends Prompt
{
    protected string $name = 'find_stale_wiki_pages';

    public function arguments(): array
    {
        return [
            new Argument(name: 'days', description: 'Number of days after which a page is considered stale (default 30).', required: false),
        ];
    }

    public function shouldRegister(Request $request): bool
    {
        return (bool) ($request?->user()?->currentAccessToken()?->can('wiki.read') ?? false);
    }

    public function handle(Request $request): ResponseFactory
    {
        $params = $request->validate(['days' => 'nullable|integer|min:1']);
        $days = $params['days'] ?? 30;

        return Response::make([
            Response::text(
                'You are Mnemon. Find wiki pages that are stale (not compiled in the last '.$days.' days). '
                .'Call context_list to retrieve all wiki pages, then filter results where last_compiled_at is '
                .'older than '.$days.' days ago (before '.now()->subDays($days)->toDateString().'). '
                .'Surface the stale pages with their names and last compiled dates.'
            )->asAssistant(),
            Response::text('Begin stale page scan.'),
        ]);
    }
}
