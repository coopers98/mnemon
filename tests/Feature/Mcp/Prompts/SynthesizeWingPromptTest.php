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

    public function test_prompt_hidden_for_token_without_mcp_use(): void
    {
        $r = $this->mcpPromptList([]);
        $names = collect($r->json('result.prompts') ?? [])->pluck('name')->all();
        $this->assertNotContains('synthesize_wing', $names);
    }
}
