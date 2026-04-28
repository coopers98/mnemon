<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\HasApiTokens;
use Tests\Concerns\CreatesPassportClient;
use Tests\TestCase;

class UserHasApiTokensTest extends TestCase
{
    use CreatesPassportClient, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPassportClient();
    }

    public function test_user_uses_passport_has_api_tokens_trait(): void
    {
        $traits = class_uses(User::class);
        $this->assertContains(HasApiTokens::class, $traits);
    }

    public function test_user_can_create_personal_access_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test', ['mcp:use']);

        $this->assertNotNull($token->accessToken);
        $this->assertTrue($token->token->can('mcp:use'));
    }
}
