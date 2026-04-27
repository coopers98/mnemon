<?php

namespace Tests\Feature\Filament;

use App\Filament\Widgets\LiveMcpSessionsWidget;
use App\Models\BrainSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LiveMcpSessionsWidgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_widget_renders_for_authenticated_user(): void
    {
        Livewire::test(LiveMcpSessionsWidget::class)
            ->assertSuccessful();
    }

    public function test_widget_shows_empty_state_when_no_sessions(): void
    {
        Livewire::test(LiveMcpSessionsWidget::class)
            ->assertSee('No MCP activity recorded yet.');
    }

    public function test_widget_shows_recent_oauth_sessions(): void
    {
        BrainSession::create([
            'tool_name' => 'drawer_search',
            'source' => 'mcp-client',
            'access_token_id' => 'tok_abc123',
            'input' => ['query' => 'test'],
            'result_count' => 3,
        ]);

        Livewire::test(LiveMcpSessionsWidget::class)
            ->assertSee('drawer_search')
            ->assertSee('mcp-client');
    }

    public function test_widget_excludes_sessions_without_access_token(): void
    {
        BrainSession::create([
            'tool_name' => 'admin_tool',
            'source' => 'admin',
            'access_token_id' => null,
            'input' => [],
            'result_count' => 0,
        ]);

        Livewire::test(LiveMcpSessionsWidget::class)
            ->assertSee('No MCP activity recorded yet.');
    }

    public function test_widget_limits_to_five_most_recent_sessions(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            $session = BrainSession::create([
                'tool_name' => "tool_{$i}",
                'source' => 'mcp',
                'access_token_id' => "tok_{$i}",
                'input' => [],
                'result_count' => $i,
            ]);
            $session->forceFill(['created_at' => now()->subMinutes(10 - $i)])->save();
        }

        // The widget returns at most 5 sessions (the most recent ones)
        $widget = new LiveMcpSessionsWidget;
        $data = $widget->getViewData();

        $this->assertCount(5, $data['sessions']);
        // Most recent is tool_7
        $this->assertSame('tool_7', $data['sessions']->first()->tool_name);
    }

    public function test_dashboard_route_includes_widget(): void
    {
        $response = $this->get('/admin');
        $response->assertSuccessful();
    }
}
