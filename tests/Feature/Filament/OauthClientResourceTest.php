<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\OauthClients\OauthClientResource;
use App\Filament\Resources\OauthClients\Pages\ListOauthClients;
use App\Filament\Resources\OauthClients\Pages\ViewOauthClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\ClientRepository;
use Livewire\Livewire;
use Tests\TestCase;

class OauthClientResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_list_page_renders_for_authenticated_user(): void
    {
        Livewire::test(ListOauthClients::class)
            ->assertSuccessful();
    }

    public function test_list_page_shows_registered_clients(): void
    {
        $repo = app(ClientRepository::class);
        $repo->createPersonalAccessGrantClient('Atlas CLI', 'users');

        Livewire::test(ListOauthClients::class)
            ->assertSee('Atlas CLI');
    }

    public function test_resource_does_not_allow_create_edit_or_delete(): void
    {
        $client = app(ClientRepository::class)
            ->createPersonalAccessGrantClient('Test Client', 'users');

        $this->assertFalse(OauthClientResource::canCreate());
        $this->assertFalse(OauthClientResource::canEdit($client));
        $this->assertFalse(OauthClientResource::canDelete($client));
        $this->assertFalse(OauthClientResource::canDeleteAny());
    }

    public function test_resource_only_registers_index_and_view_pages(): void
    {
        $pages = OauthClientResource::getPages();

        $this->assertSame(['index', 'view'], array_keys($pages));
    }

    public function test_view_page_renders_client_details(): void
    {
        $client = app(ClientRepository::class)
            ->createPersonalAccessGrantClient('DetailableClient', 'users');

        Livewire::test(ViewOauthClient::class, ['record' => $client->id])
            ->assertSuccessful();
    }

    public function test_revoke_action_marks_all_tokens_revoked(): void
    {
        $user = User::factory()->create();
        $repo = app(ClientRepository::class);
        $client = $repo->createPersonalAccessGrantClient('RevokeMe', 'users');

        // Issue a personal access token via the client
        $token = $user->createToken('test-token')->token;
        $this->assertFalse((bool) $token->revoked);

        Livewire::test(ListOauthClients::class)
            ->callTableAction('revoke', $client);

        $this->assertTrue((bool) $token->fresh()->revoked);
    }
}
