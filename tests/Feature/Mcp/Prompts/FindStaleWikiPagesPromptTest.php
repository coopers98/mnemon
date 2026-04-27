<?php

namespace Tests\Feature\Mcp\Prompts;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesMcpRequests;
use Tests\TestCase;

class FindStaleWikiPagesPromptTest extends TestCase
{
    use RefreshDatabase, MakesMcpRequests;

    public function test_prompt_appears_in_list(): void
    {
        $r = $this->mcpPromptList(['wiki.read']);
        $r->assertStatus(200);
        $names = collect($r->json('result.prompts'))->pluck('name')->all();
        $this->assertContains('find_stale_wiki_pages', $names);
    }

    public function test_prompt_renders_with_argument(): void
    {
        $r = $this->mcpPromptGet('find_stale_wiki_pages', ['days' => 14], ['wiki.read']);
        $r->assertStatus(200);
        $messages = $r->json('result.messages');
        $this->assertNotEmpty($messages);
        $combined = collect($messages)->pluck('content.text')->implode(' ');
        $this->assertStringContainsString('14', $combined);
    }

    public function test_prompt_hidden_for_token_without_scope(): void
    {
        $r = $this->mcpPromptList(['palace.read']);
        $names = collect($r->json('result.prompts'))->pluck('name')->all();
        $this->assertNotContains('find_stale_wiki_pages', $names);
    }
}
