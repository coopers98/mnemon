<?php

namespace Tests\Feature\Mcp\Prompts;

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

    public function test_prompt_names_a_page_the_wing_restricted_token_may_write(): void
    {
        // context_set maps a page name to a wing; "synthesis:work" maps to
        // synthesis-work, which a token restricted to work is refused.
        $r = $this->mcpPromptGet('synthesize_wing', ['wing_slug' => 'work'], ['mcp:use'], ['work']);
        $combined = collect($r->json('result.messages'))->pluck('content.text')->implode(' ');

        $this->assertStringContainsString('context_set with name="work"', $combined);
    }

    public function test_prompt_hidden_for_token_without_mcp_use(): void
    {
        $r = $this->mcpPromptList([]);
        $names = collect($r->json('result.prompts') ?? [])->pluck('name')->all();
        $this->assertNotContains('synthesize_wing', $names);
    }
}
