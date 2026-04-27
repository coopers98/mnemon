<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\HasApiTokens;
use Tests\TestCase;

class UserHasApiTokensTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_uses_passport_has_api_tokens_trait(): void
    {
        $traits = class_uses(User::class);
        $this->assertContains(HasApiTokens::class, $traits);
    }

    public function test_user_can_create_personal_access_token(): void
    {
        // Passport requires a personal access client in the DB
        app(ClientRepository::class)->createPersonalAccessGrantClient('Test Client', 'users');

        $user = User::factory()->create();
        $token = $user->createToken('test', ['palace.read']);

        $this->assertNotNull($token->accessToken);
        $this->assertTrue($token->token->can('palace.read'));
    }
}
