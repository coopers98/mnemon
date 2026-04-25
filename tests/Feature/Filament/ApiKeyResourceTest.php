<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ApiKeys\Pages\CreateApiKey;
use App\Filament\Resources\ApiKeys\Pages\EditApiKey;
use App\Filament\Resources\ApiKeys\Pages\ListApiKeys;
use App\Models\ApiKey;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ApiKeyResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_list_page_renders_for_authenticated_user(): void
    {
        Livewire::test(ListApiKeys::class)
            ->assertSuccessful();
    }

    public function test_default_filter_hides_revoked_keys(): void
    {
        $active = ApiKey::create([
            'name' => 'Active Key',
            'key_hash' => hash('sha256', 'plain-active'),
            'scopes' => ['*'],
        ]);

        $revoked = ApiKey::create([
            'name' => 'Revoked Key',
            'key_hash' => hash('sha256', 'plain-revoked'),
            'scopes' => ['*'],
            'revoked_at' => now(),
        ]);

        Livewire::test(ListApiKeys::class)
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$revoked]);
    }

    public function test_revoked_filter_shows_only_revoked_keys(): void
    {
        $active = ApiKey::create([
            'name' => 'Active Key',
            'key_hash' => hash('sha256', 'plain-active'),
            'scopes' => ['*'],
        ]);

        $revoked = ApiKey::create([
            'name' => 'Revoked Key',
            'key_hash' => hash('sha256', 'plain-revoked'),
            'scopes' => ['*'],
            'revoked_at' => now(),
        ]);

        Livewire::test(ListApiKeys::class)
            ->filterTable('status', false)
            ->assertCanSeeTableRecords([$revoked])
            ->assertCanNotSeeTableRecords([$active]);
    }

    public function test_status_column_shows_active_and_revoked_states(): void
    {
        ApiKey::create([
            'name' => 'My Active Key',
            'key_hash' => hash('sha256', 'plain-active'),
            'scopes' => ['*'],
        ]);

        ApiKey::create([
            'name' => 'My Revoked Key',
            'key_hash' => hash('sha256', 'plain-revoked'),
            'scopes' => ['*'],
            'revoked_at' => now(),
        ]);

        Livewire::test(ListApiKeys::class)
            ->filterTable('status', null)
            ->assertSee('Active')
            ->assertSee('Revoked');
    }

    public function test_create_form_persists_a_key_and_hashes_plaintext(): void
    {
        Livewire::test(CreateApiKey::class)
            ->fillForm([
                'name' => 'Brand New Key',
                'scopes' => ['palace:read', 'wiki:read'],
                'wing_restrictions' => ['work', 'project:*'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('api_keys', [
            'name' => 'Brand New Key',
        ]);

        $key = ApiKey::where('name', 'Brand New Key')->firstOrFail();

        $this->assertEquals(['palace:read', 'wiki:read'], $key->scopes);
        $this->assertEquals(['work', 'project:*'], $key->wing_restrictions);

        // The plaintext is not stored — only the SHA-256 hash.
        $this->assertNotEmpty($key->key_hash);
        $this->assertEquals(64, strlen($key->key_hash));
    }

    public function test_create_form_requires_name(): void
    {
        Livewire::test(CreateApiKey::class)
            ->fillForm([
                'name' => '',
                'scopes' => ['palace:read'],
            ])
            ->call('create')
            ->assertHasFormErrors(['name' => 'required']);
    }

    public function test_create_form_requires_at_least_one_scope(): void
    {
        Livewire::test(CreateApiKey::class)
            ->fillForm([
                'name' => 'No-Scope Key',
                'scopes' => [],
            ])
            ->call('create')
            ->assertHasFormErrors(['scopes']);
    }

    public function test_edit_updates_name_and_scopes_without_changing_hash(): void
    {
        $key = ApiKey::create([
            'name' => 'Original Name',
            'key_hash' => hash('sha256', 'original-plaintext'),
            'scopes' => ['palace:read'],
        ]);

        $originalHash = $key->key_hash;

        Livewire::test(EditApiKey::class, ['record' => $key->getRouteKey()])
            ->fillForm([
                'name' => 'Updated Name',
                'scopes' => ['palace:read', 'palace:write'],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $key->refresh();

        $this->assertEquals('Updated Name', $key->name);
        $this->assertEquals(['palace:read', 'palace:write'], $key->scopes);
        $this->assertEquals($originalHash, $key->key_hash);
    }

    public function test_revoke_action_sets_revoked_at(): void
    {
        $key = ApiKey::create([
            'name' => 'To Be Revoked',
            'key_hash' => hash('sha256', 'plain-tbr'),
            'scopes' => ['*'],
        ]);

        $this->assertNull($key->revoked_at);

        Livewire::test(ListApiKeys::class)
            ->callTableAction('revoke', $key);

        $key->refresh();

        $this->assertNotNull($key->revoked_at);
        $this->assertTrue($key->isRevoked());
    }

    public function test_already_revoked_key_cannot_be_revoked_again(): void
    {
        $original = now()->subDay();

        $key = ApiKey::create([
            'name' => 'Already Revoked',
            'key_hash' => hash('sha256', 'plain-already'),
            'scopes' => ['*'],
            'revoked_at' => $original,
        ]);

        // The revoke action should not be available on an already-revoked record.
        Livewire::test(ListApiKeys::class)
            ->filterTable('status', false)
            ->assertTableActionHidden('revoke', $key);
    }

    public function test_default_sort_is_created_at_descending(): void
    {
        $a = ApiKey::create([
            'name' => 'A',
            'key_hash' => hash('sha256', 'plain-a'),
            'scopes' => ['*'],
        ]);
        $a->forceFill(['created_at' => now()->subDays(2)])->save();

        $b = ApiKey::create([
            'name' => 'B',
            'key_hash' => hash('sha256', 'plain-b'),
            'scopes' => ['*'],
        ]);
        $b->forceFill(['created_at' => now()->subDay()])->save();

        $c = ApiKey::create([
            'name' => 'C',
            'key_hash' => hash('sha256', 'plain-c'),
            'scopes' => ['*'],
        ]);
        $c->forceFill(['created_at' => now()->subDays(5)])->save();

        Livewire::test(ListApiKeys::class)
            ->assertCanSeeTableRecords([$b, $a, $c], inOrder: true);
    }

    public function test_create_notification_exposes_plaintext_key_once(): void
    {
        Livewire::test(CreateApiKey::class)
            ->fillForm([
                'name' => 'Notification Key',
                'scopes' => ['palace:read'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        // Filament v5 ships `Notification::assertNotified($title)` (see
        // `Filament\Notifications\Testing\TestsNotifications` and
        // `Filament\Notifications\Notification::assertNotified()`), but it only
        // matches against the title. To assert the body — which is the
        // load-bearing surface here, since this is the only place the plaintext
        // key is ever exposed — we read the queued notification array directly
        // out of `session('filament.notifications')`. `Notification::send()`
        // calls `session()->push('filament.notifications', $this->toArray())`,
        // so the body is available verbatim there. Note: calling
        // `Notification::assertNotified()` first would `pull()` the
        // notifications off the session and consume them, so we do the session
        // read first.
        $queued = session('filament.notifications', []);

        $this->assertNotEmpty($queued, 'Expected a Filament notification to be queued in the session.');

        $notification = collect($queued)->firstWhere('title', 'API key created');

        $this->assertNotNull($notification, 'No notification with title "API key created" was queued.');
        $this->assertNotEmpty($notification['body'] ?? null, 'Notification body must not be empty.');

        // The body should embed a 64-character plaintext key (Str::random(64)).
        $this->assertMatchesRegularExpression(
            '/[A-Za-z0-9]{64}/',
            $notification['body'],
            'Notification body should contain the 64-character plaintext key.',
        );

        preg_match('/([A-Za-z0-9]{64})/', $notification['body'], $matches);
        $plaintext = $matches[1] ?? null;

        $this->assertNotNull($plaintext, 'Could not extract plaintext key from notification body.');

        // The plaintext extracted from the notification must hash to the stored key_hash.
        $created = ApiKey::where('name', 'Notification Key')->firstOrFail();
        $this->assertSame(hash('sha256', $plaintext), $created->key_hash);

        // Sanity-check the helper while we're here — it confirms a notification fired.
        Notification::assertNotified('API key created');
    }

    public function test_edit_form_does_not_expose_key_hash_field(): void
    {
        $key = ApiKey::generate('Existing', ['*'])['model'];

        Livewire::test(EditApiKey::class, ['record' => $key->getRouteKey()])
            ->assertFormFieldExists('name')
            ->assertFormFieldExists('scopes')
            ->assertFormFieldExists('wing_restrictions')
            ->assertFormFieldDoesNotExist('key_hash');
    }
}
