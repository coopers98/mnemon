<?php

namespace App\Mcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

#[Description('Synthesize a wiki page for all drawers in a given wing.')]
class SynthesizeWingPrompt extends Prompt
{
    protected string $name = 'synthesize_wing';

    public function arguments(): array
    {
        return [
            new Argument(name: 'wing_slug', description: 'The wing slug to synthesize.', required: true),
        ];
    }

    public function shouldRegister(Request $request): bool
    {
        return (bool) ($request?->user()?->currentAccessToken()?->can('mcp:use') ?? false);
    }

    public function handle(Request $request): ResponseFactory
    {
        $params = $request->validate(['wing_slug' => 'required|string']);

        return Response::make([
            Response::text(
                'You are Mnemon. Synthesize a wiki page for the wing "'.$params['wing_slug'].'". '
                .'Use drawer_search with wing="'.$params['wing_slug'].'" to gather source material, '
                .'then call context_set with name="synthesis:'.$params['wing_slug'].'" and the synthesized content.'
            )->asAssistant(),
            Response::text('Begin synthesis.'),
        ]);
    }
}
