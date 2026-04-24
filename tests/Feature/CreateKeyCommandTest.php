<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateKeyCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_key_command_creates_key_and_outputs_plaintext(): void
    {
        $this->artisan('mnemon:create-key', [
            'name' => 'test-key',
            '--scopes' => ['drawers:read', 'drawers:write'],
        ])
            ->assertExitCode(0)
            ->expectsOutput('✓ API key created successfully!')
            ->expectsOutput('Name: test-key')
            ->expectsOutput('Scopes: drawers:read, drawers:write');

        $this->assertDatabaseHas('api_keys', [
            'name' => 'test-key',
        ]);

        $apiKey = ApiKey::where('name', 'test-key')->first();
        $this->assertEquals(['drawers:read', 'drawers:write'], $apiKey->scopes);
    }

    public function test_create_key_command_with_wing_restrictions(): void
    {
        $this->artisan('mnemon:create-key', [
            'name' => 'restricted-key',
            '--scopes' => ['*'],
            '--wings' => 'project:*,personal',
        ])
            ->assertExitCode(0)
            ->expectsOutput('Wing Restrictions: project:*, personal');

        $apiKey = ApiKey::where('name', 'restricted-key')->first();
        $this->assertEquals(['project:*', 'personal'], $apiKey->wing_restrictions);
    }

    public function test_create_key_command_without_scopes_fails(): void
    {
        $this->artisan('mnemon:create-key', ['name' => 'no-scopes'])
            ->assertExitCode(1)
            ->expectsOutput('At least one scope is required. Use --scopes=scope1 --scopes=scope2 or --scopes=*');
    }
}
