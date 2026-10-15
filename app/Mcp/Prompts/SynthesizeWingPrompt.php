<?php

namespace App\Mcp\Prompts;

use App\Mcp\Concerns\RequiresWingAccess;
use App\Models\Wing;
use App\Support\WingPatterns;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

#[Description('Synthesize a wiki page for all drawers in a given wing.')]
class SynthesizeWingPrompt extends Prompt
{
    use RequiresWingAccess;

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
        $slug = $params['wing_slug'];

        return Response::make([
            Response::text(
                'You are Mnemon. Synthesize a wiki page for the wing "'.$slug.'". '
                .'Use drawer_search with wing="'.$slug.'" to gather source material, '
                .'then call context_set with name="'.$this->pageName($request, $slug).'" (a wiki page belongs to the wing its name maps to) and the synthesized content.'
            )->asAssistant(),
            Response::text('Begin synthesis.'),
        ]);
    }

    /**
     * The wiki page for a wing is named like the wing: `project:mnemon` for
     * slug `project-mnemon`. context_set does not normalise names, so the
     * slug itself would create a sibling page next to the real one.
     *
     * The name is only looked up in a wing the token may reach -- otherwise
     * the prompt would disclose a hidden wing's name -- and only used when it
     * maps back to the slug, so a restricted token may write it.
     */
    private function pageName(Request $request, string $slug): string
    {
        $patterns = $this->wingPatternsFor($request);

        $wing = $patterns === null || WingPatterns::matches($slug, $patterns)
            ? Wing::where('slug', $slug)->first()
            : null;

        return $wing !== null && Wing::slugify($wing->name) === $wing->slug ? $wing->name : $slug;
    }
}
