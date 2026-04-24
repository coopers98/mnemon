<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_key_has_scope_with_wildcard(): void
    {
        $key = ApiKey::create([
            'name' => 'Admin Key',
            'key_hash' => hash('sha256', 'test'),
            'scopes' => ['*'],
        ]);

        $this->assertTrue($key->hasScope('drawers.write'));
        $this->assertTrue($key->hasScope('wiki.read'));
        $this->assertTrue($key->hasScope('anything'));
    }

    public function test_api_key_has_scope_with_specific_scopes(): void
    {
        $key = ApiKey::create([
            'name' => 'Limited Key',
            'key_hash' => hash('sha256', 'test'),
            'scopes' => ['drawers.read', 'wiki.read'],
        ]);

        $this->assertTrue($key->hasScope('drawers.read'));
        $this->assertTrue($key->hasScope('wiki.read'));
        $this->assertFalse($key->hasScope('drawers.write'));
        $this->assertFalse($key->hasScope('admin'));
    }

    public function test_api_key_can_access_wing_with_no_restrictions(): void
    {
        $key = ApiKey::create([
            'name' => 'Unrestricted',
            'key_hash' => hash('sha256', 'test'),
            'scopes' => ['*'],
        ]);

        $this->assertTrue($key->canAccessWing('work'));
        $this->assertTrue($key->canAccessWing('personal'));
        $this->assertTrue($key->canAccessWing('anything'));
    }

    public function test_api_key_can_access_wing_with_specific_wings(): void
    {
        $key = ApiKey::create([
            'name' => 'Work Only',
            'key_hash' => hash('sha256', 'test'),
            'scopes' => ['*'],
            'wing_restrictions' => ['work', 'projects'],
        ]);

        $this->assertTrue($key->canAccessWing('work'));
        $this->assertTrue($key->canAccessWing('projects'));
        $this->assertFalse($key->canAccessWing('personal'));
    }

    public function test_api_key_can_access_wing_with_wildcard_pattern(): void
    {
        $key = ApiKey::create([
            'name' => 'Project Wildcard',
            'key_hash' => hash('sha256', 'test'),
            'scopes' => ['*'],
            'wing_restrictions' => ['project:*', 'personal'],
        ]);

        $this->assertTrue($key->canAccessWing('project:alpha'));
        $this->assertTrue($key->canAccessWing('project:beta'));
        $this->assertTrue($key->canAccessWing('personal'));
        $this->assertFalse($key->canAccessWing('work'));
        $this->assertFalse($key->canAccessWing('other:something'));
    }

    public function test_api_key_is_revoked(): void
    {
        $key = ApiKey::create([
            'name' => 'Revoked Key',
            'key_hash' => hash('sha256', 'test'),
            'scopes' => ['*'],
            'revoked_at' => now(),
        ]);

        $this->assertTrue($key->isRevoked());
    }

    public function test_api_key_is_not_revoked(): void
    {
        $key = ApiKey::create([
            'name' => 'Active Key',
            'key_hash' => hash('sha256', 'test'),
            'scopes' => ['*'],
        ]);

        $this->assertFalse($key->isRevoked());
    }

    public function test_api_key_generate_creates_key_and_returns_plaintext(): void
    {
        $result = ApiKey::generate('Test Key', ['drawers.write']);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('key', $result);
        $this->assertArrayHasKey('model', $result);
        $this->assertInstanceOf(ApiKey::class, $result['model']);
        $this->assertEquals('Test Key', $result['model']->name);
        $this->assertEquals(['drawers.write'], $result['model']->scopes);
        $this->assertEquals(hash('sha256', $result['key']), $result['model']->key_hash);
    }

    public function test_api_key_generate_supports_wing_restrictions(): void
    {
        $result = ApiKey::generate(
            'Restricted Key',
            ['*'],
            ['work', 'project:*']
        );

        $this->assertEquals(['work', 'project:*'], $result['model']->wing_restrictions);
        $this->assertTrue($result['model']->canAccessWing('work'));
        $this->assertTrue($result['model']->canAccessWing('project:alpha'));
        $this->assertFalse($result['model']->canAccessWing('personal'));
    }

    public function test_api_key_hash_is_unique(): void
    {
        $hash = hash('sha256', 'unique-key');

        ApiKey::create([
            'name' => 'First',
            'key_hash' => $hash,
            'scopes' => ['*'],
        ]);

        $this->expectException(QueryException::class);

        ApiKey::create([
            'name' => 'Second',
            'key_hash' => $hash,
            'scopes' => ['*'],
        ]);
    }
}
