<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_wings_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('wings'));
        $this->assertTrue(Schema::hasColumns('wings', [
            'id',
            'name',
            'slug',
            'description',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_rooms_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('rooms'));
        $this->assertTrue(Schema::hasColumns('rooms', [
            'id',
            'wing_id',
            'name',
            'slug',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_drawers_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('drawers'));
        $this->assertTrue(Schema::hasColumns('drawers', [
            'id',
            'room_id',
            'content',
            'source',
            'metadata',
            'deleted_at',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_wiki_pages_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('wiki_pages'));
        $this->assertTrue(Schema::hasColumns('wiki_pages', [
            'id',
            'name',
            'type',
            'title',
            'content',
            'description',
            'last_compiled_at',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_brain_sessions_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('brain_sessions'));
        $this->assertTrue(Schema::hasColumns('brain_sessions', [
            'id',
            'tool_name',
            'source',
            'input',
            'result_count',
            'created_at',
        ]));
    }

    public function test_api_keys_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('api_keys'));
        $this->assertTrue(Schema::hasColumns('api_keys', [
            'id',
            'name',
            'key_hash',
            'scopes',
            'wing_restrictions',
            'last_used_at',
            'revoked_at',
            'created_at',
            'updated_at',
        ]));
    }
}
