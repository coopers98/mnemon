<?php

namespace Tests\Feature;

use App\Models\BrainSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BrainSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_brain_session_auto_sets_created_at(): void
    {
        $session = BrainSession::create([
            'tool_name' => 'store_drawer',
            'source' => 'mcp',
            'input' => ['content' => 'Test', 'room' => 'ideas'],
        ]);

        $this->assertNotNull($session->created_at);
        $this->assertInstanceOf(Carbon::class, $session->created_at);
    }

    public function test_brain_session_casts_input_to_array(): void
    {
        $input = [
            'content' => 'Store this thought',
            'wing' => 'personal',
            'room' => 'ideas',
        ];

        $session = BrainSession::create([
            'tool_name' => 'store_drawer',
            'source' => 'mcp',
            'input' => $input,
        ]);

        $session->refresh();

        $this->assertIsArray($session->input);
        $this->assertEquals('Store this thought', $session->input['content']);
    }

    public function test_brain_session_allows_null_result_count(): void
    {
        $session = BrainSession::create([
            'tool_name' => 'retrieve',
            'source' => 'api',
            'input' => ['query' => 'test'],
        ]);

        $this->assertNull($session->result_count);
    }

    public function test_brain_session_can_store_result_count(): void
    {
        $session = BrainSession::create([
            'tool_name' => 'search_drawers',
            'source' => 'mcp',
            'input' => ['query' => 'Laravel'],
            'result_count' => 5,
        ]);

        $this->assertEquals(5, $session->result_count);
    }

    public function test_brain_session_is_create_only(): void
    {
        $session = BrainSession::create([
            'tool_name' => 'test',
            'source' => 'api',
            'input' => ['test' => 'data'],
        ]);

        // Brain sessions have no updated_at
        $this->assertObjectNotHasProperty('updated_at', $session);
    }
}
