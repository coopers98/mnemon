<?php

namespace Tests\Feature;

use App\Models\BrainSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPassportClient;
use Tests\TestCase;

class BrainSessionOauthColumnsTest extends TestCase
{
    use CreatesPassportClient, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPassportClient();
    }

    public function test_brain_session_persists_oauth_columns(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->token;

        $session = BrainSession::create([
            'tool_name' => 'drawer_search',
            'source' => 'Test Client as user@... (token …abc1)',
            'oauth_client_id' => $token->client_id,
            'user_id' => $user->id,
            'access_token_id' => $token->id,
            'input' => ['query' => 'foo'],
            'result_count' => 3,
        ]);

        $fresh = $session->fresh();
        $this->assertEquals($token->client_id, $fresh->oauth_client_id);
        $this->assertEquals($user->id, $fresh->user_id);
        $this->assertEquals($token->id, $fresh->access_token_id);
    }
}
