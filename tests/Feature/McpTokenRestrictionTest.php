<?php

namespace Tests\Feature;

use App\Models\McpTokenRestriction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPassportClient;
use Tests\TestCase;

class McpTokenRestrictionTest extends TestCase
{
    use CreatesPassportClient, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPassportClient();
    }

    public function test_can_persist_wing_patterns_for_a_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test', ['palace.read'])->token;

        $restriction = McpTokenRestriction::create([
            'access_token_id' => $token->id,
            'wing_patterns' => ['work:*', 'research'],
        ]);

        $fresh = McpTokenRestriction::find($token->id);
        $this->assertEquals(['work:*', 'research'], $fresh->wing_patterns);
    }

    public function test_null_wing_patterns_means_unrestricted(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->token;

        $restriction = McpTokenRestriction::create([
            'access_token_id' => $token->id,
            'wing_patterns' => null,
        ]);

        $this->assertNull($restriction->fresh()->wing_patterns);
    }

    public function test_deleting_token_cascades_restriction(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->token;
        McpTokenRestriction::create(['access_token_id' => $token->id, 'wing_patterns' => ['work']]);

        $token->delete();

        $this->assertNull(McpTokenRestriction::find($token->id));
    }

    public function test_matches_handles_literal_and_wildcard_patterns(): void
    {
        $r = new McpTokenRestriction(['wing_patterns' => ['work:*', 'research']]);

        $this->assertTrue($r->matches('research'));
        $this->assertTrue($r->matches('work:project-a'));
        $this->assertFalse($r->matches('personal'));

        $unrestricted = new McpTokenRestriction(['wing_patterns' => null]);
        $this->assertTrue($unrestricted->matches('anything'));
    }
}
