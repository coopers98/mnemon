<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\OauthAccessTokens\OauthAccessTokenResource;
use App\Filament\Resources\OauthAccessTokens\Pages\ListOauthAccessTokens;
use App\Models\McpTokenRestriction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\ClientRepository;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class OauthAccessTokenResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());

        // Ensure a personal access client exists for token issuance.
        $clients = app(ClientRepository::class);

        try {
            $clients->personalAccessClient('users');
        } catch (RuntimeException) {
            $clients->createPersonalAccessGrantClient('Test Client', 'users');
        }
    }

    public function test_list_page_renders_for_authenticated_user(): void
    {
        Livewire::test(ListOauthAccessTokens::class)
            ->assertSuccessful();
    }

    public function test_list_shows_active_tokens(): void
    {
        $user = User::factory()->create();
        $tokenResult = $user->createToken('my-token');
        $token = $tokenResult->token;

        Livewire::test(ListOauthAccessTokens::class)
            ->assertCanSeeTableRecords([$token]);
    }

    public function test_revoked_tokens_are_excluded(): void
    {
        $user = User::factory()->create();
        $tokenResult = $user->createToken('revoked-token');
        $tokenResult->token->revoke();

        Livewire::test(ListOauthAccessTokens::class)
            ->assertDontSee('revoked-token');
    }

    public function test_wing_restrictions_column_shows_unrestricted_when_no_restriction_row(): void
    {
        $user = User::factory()->create();
        $user->createToken('open-token');

        Livewire::test(ListOauthAccessTokens::class)
            ->assertSee('(unrestricted)');
    }

    public function test_wing_restrictions_column_shows_patterns_when_restriction_exists(): void
    {
        $user = User::factory()->create();
        $tokenResult = $user->createToken('restricted-token');
        $tokenId = $tokenResult->token->id;

        McpTokenRestriction::create([
            'access_token_id' => $tokenId,
            'wing_patterns' => ['work:*', 'personal'],
            'created_at' => now(),
        ]);

        Livewire::test(ListOauthAccessTokens::class)
            ->assertSee('work:*')
            ->assertSee('personal');
    }

    public function test_revoke_action_marks_token_as_revoked(): void
    {
        $user = User::factory()->create();
        $tokenResult = $user->createToken('revoke-me');
        $token = $tokenResult->token;
        $this->assertFalse((bool) $token->revoked);

        Livewire::test(ListOauthAccessTokens::class)
            ->callTableAction('revoke', $token);

        $this->assertTrue((bool) $token->fresh()->revoked);
    }

    public function test_resource_does_not_allow_create_edit_or_delete(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('no-edit')->token;

        $this->assertFalse(OauthAccessTokenResource::canCreate());
        $this->assertFalse(OauthAccessTokenResource::canEdit($token));
        $this->assertFalse(OauthAccessTokenResource::canDelete($token));
        $this->assertFalse(OauthAccessTokenResource::canDeleteAny());
    }
}
