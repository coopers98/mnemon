<?php

namespace Tests\Feature\Mcp\Prompts;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesMcpRequests;
use Tests\TestCase;

class DrawerToWikiPromptTest extends TestCase
{
    use RefreshDatabase, MakesMcpRequests;

    public function test_prompt_appears_in_list(): void
    {
        $r = $this->mcpPromptList(['wiki.write']);
        $r->assertStatus(200);
        $names = collect($r->json('result.prompts'))->pluck('name')->all();
        $this->assertContains('drawer_to_wiki', $names);
    }

    public function test_prompt_renders_with_argument(): void
    {
        $r = $this->mcpPromptGet('drawer_to_wiki', ['drawer_id' => 42], ['wiki.write']);
        $r->assertStatus(200);
        $messages = $r->json('result.messages');
        $this->assertNotEmpty($messages);
        $combined = collect($messages)->pluck('content.text')->implode(' ');
        $this->assertStringContainsString('42', $combined);
    }

    public function test_prompt_hidden_for_token_without_scope(): void
    {
        $r = $this->mcpPromptList(['palace.read']);
        $names = collect($r->json('result.prompts'))->pluck('name')->all();
        $this->assertNotContains('drawer_to_wiki', $names);
    }
}
