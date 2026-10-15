<?php

namespace Tests\Feature\Mcp\Prompts;

use App\Models\Wing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesMcpRequests;
use Tests\TestCase;

class SynthesizeWingPromptTest extends TestCase
{
    use MakesMcpRequests, RefreshDatabase;

    public function test_prompt_appears_in_list(): void
    {
        $r = $this->mcpPromptList(['mcp:use']);
        $r->assertStatus(200);
        $names = collect($r->json('result.prompts'))->pluck('name')->all();
        $this->assertContains('synthesize_wing', $names);
    }

    public function test_prompt_renders_with_argument(): void
    {
        $r = $this->mcpPromptGet('synthesize_wing', ['wing_slug' => 'work'], ['mcp:use']);
        $r->assertStatus(200);
        $messages = $r->json('result.messages');
        $this->assertNotEmpty($messages);
        $combined = collect($messages)->pluck('content.text')->implode(' ');
        $this->assertStringContainsString('work', $combined);
    }

    public function test_prompt_names_the_wings_own_page(): void
    {
        // Wings are named `kind:x` with slug `kind-x`, and the wiki page for a
        // wing carries the wing's name. Pointing at the slug would create a
        // sibling page `project-x`; "synthesis:x" maps to a different wing,
        // which a token restricted to project-x is refused.
        Wing::create(['name' => 'project:x', 'slug' => 'project-x']);

        $r = $this->mcpPromptGet('synthesize_wing', ['wing_slug' => 'project-x'], ['mcp:use'], ['project-x']);
        $combined = collect($r->json('result.messages'))->pluck('content.text')->implode(' ');

        $this->assertStringContainsString('context_set with name="project:x"', $combined);
    }

    public function test_the_named_page_is_one_the_restricted_token_may_write(): void
    {
        Wing::create(['name' => 'project:x', 'slug' => 'project-x']);

        $r = $this->mcpCall('context_set', ['name' => 'project:x', 'content' => 'synthesis'], ['mcp:use'], ['project-x']);

        $this->assertFalse($r->json('result.isError') ?? false, $r->getContent());
    }

    public function test_prompt_hidden_for_token_without_mcp_use(): void
    {
        $r = $this->mcpPromptList([]);
        $names = collect($r->json('result.prompts') ?? [])->pluck('name')->all();
        $this->assertNotContains('synthesize_wing', $names);
    }
}
