<?php

namespace Tests\Unit\Mcp;

use App\Mcp\Concerns\RequiresScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\Concerns\CreatesPassportClient;
use Tests\Concerns\MakesMcpRequest;
use Tests\TestCase;

class RequiresScopeTest extends TestCase
{
    use CreatesPassportClient, MakesMcpRequest, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPassportClient();
    }

    public function test_returns_null_when_token_has_mcp_use_scope(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('t', ['mcp:use'])->token;
        $request = $this->mcpRequestFor($user, $token);

        $tool = new class
        {
            use RequiresScope;

            public function check(Request $r): ?Response
            {
                return $this->requireScope($r);
            }
        };

        $this->assertNull($tool->check($request));
    }

    public function test_returns_error_response_when_token_lacks_mcp_use_scope(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('t', [])->token;
        $request = $this->mcpRequestFor($user, $token);

        $tool = new class
        {
            use RequiresScope;

            public function check(Request $r): ?Response
            {
                return $this->requireScope($r);
            }
        };

        $result = $tool->check($request);
        $this->assertInstanceOf(Response::class, $result);
        $this->assertTrue($result->isError());
    }
}
