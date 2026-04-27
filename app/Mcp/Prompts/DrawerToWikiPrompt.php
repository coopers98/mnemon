<?php

namespace App\Mcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

#[Description('Propose wiki page updates based on the content of a specific drawer.')]
class DrawerToWikiPrompt extends Prompt
{
    protected string $name = 'drawer_to_wiki';

    public function arguments(): array
    {
        return [
            new Argument(name: 'drawer_id', description: 'The ID of the drawer to convert into wiki content.', required: true),
        ];
    }

    public function shouldRegister(Request $request): bool
    {
        return (bool) ($request?->user()?->currentAccessToken()?->can('wiki.write') ?? false);
    }

    public function handle(Request $request): ResponseFactory
    {
        $params = $request->validate(['drawer_id' => 'required|integer']);

        return Response::make([
            Response::text(
                'You are Mnemon. Propose wiki page updates based on drawer #'.$params['drawer_id'].'. '
                .'Use drawer_get with id='.$params['drawer_id'].' to fetch the drawer content, '
                .'then analyze the content and propose a context_set call to create or update relevant wiki pages '
                .'with synthesized knowledge derived from the drawer.'
            )->asAssistant(),
            Response::text('Begin drawer-to-wiki conversion.'),
        ]);
    }
}
