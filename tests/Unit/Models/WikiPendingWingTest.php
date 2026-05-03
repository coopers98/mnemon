<?php

namespace Tests\Unit\Models;

use App\Models\WikiPendingWing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WikiPendingWingTest extends TestCase
{
    use RefreshDatabase;

    public function test_drawer_payload_is_cast_to_array(): void
    {
        $row = WikiPendingWing::create([
            'wing_slug' => 'project:atlas',
            'wing_name' => 'Atlas',
            'rationale' => 'Multiple references to Atlas project',
            'drawer_payload' => ['content' => 'hello', 'room_slug' => 'meetings'],
        ]);

        $this->assertIsArray($row->fresh()->drawer_payload);
        $this->assertEquals('hello', $row->fresh()->drawer_payload['content']);
    }

    public function test_default_status_is_pending(): void
    {
        $row = WikiPendingWing::create([
            'wing_slug' => 'foo',
            'wing_name' => 'Foo',
            'drawer_payload' => [],
        ]);

        $this->assertEquals('pending', $row->fresh()->status);
    }
}
