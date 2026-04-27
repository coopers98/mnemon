<?php

namespace Tests\Unit\Mcp;

use App\Mcp\Concerns\RequiresWingAccess;
use App\Models\McpTokenRestriction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\Concerns\CreatesPassportClient;
use Tests\Concerns\MakesMcpRequest;
use Tests\TestCase;

class RequiresWingAccessTest extends TestCase
{
    use RefreshDatabase, CreatesPassportClient, MakesMcpRequest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPassportClient();
    }

    public function test_unrestricted_token_passes_any_wing(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('t')->token;

        $request = $this->mcpRequestFor($user, $token);
        $tool = $this->harness();
        $this->assertNull($tool->check($request, 'personal'));
        $this->assertNull($tool->check($request, 'work:foo'));
    }

    public function test_restricted_token_passes_matching_wing(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('t')->token;
        McpTokenRestriction::create(['access_token_id' => $token->id, 'wing_patterns' => ['work:*']]);

        $request = $this->mcpRequestFor($user, $token);
        $tool = $this->harness();
        $this->assertNull($tool->check($request, 'work:project-a'));
    }

    public function test_restricted_token_rejects_non_matching_wing(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('t')->token;
        McpTokenRestriction::create(['access_token_id' => $token->id, 'wing_patterns' => ['work:*']]);

        $request = $this->mcpRequestFor($user, $token);
        $tool = $this->harness();
        $result = $tool->check($request, 'personal');

        $this->assertInstanceOf(Response::class, $result);
        $this->assertTrue($result->isError());
    }

    public function test_wing_patterns_for_returns_list(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('t')->token;
        McpTokenRestriction::create(['access_token_id' => $token->id, 'wing_patterns' => ['work:*', 'research']]);

        $request = $this->mcpRequestFor($user, $token);
        $tool = $this->harness();
        $this->assertEquals(['work:*', 'research'], $tool->patterns($request));
    }

    private function harness()
    {
        return new class {
            use RequiresWingAccess;
            public function check(Request $r, string $w) { return $this->requireWingAccess($r, $w); }
            public function patterns(Request $r) { return $this->wingPatternsFor($r); }
        };
    }
}
