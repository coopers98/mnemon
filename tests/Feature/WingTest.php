<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\Wing;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WingTest extends TestCase
{
    use RefreshDatabase;

    public function test_wing_auto_generates_slug_on_create(): void
    {
        $wing = Wing::create([
            'name' => 'Work Projects',
            'description' => 'All work-related content',
        ]);

        $this->assertEquals('work-projects', $wing->slug);
    }

    public function test_wing_slug_can_be_manually_set(): void
    {
        $wing = Wing::create([
            'name' => 'Work Projects',
            'slug' => 'custom-slug',
        ]);

        $this->assertEquals('custom-slug', $wing->slug);
    }

    public function test_wing_has_many_rooms(): void
    {
        $wing = Wing::create(['name' => 'Personal']);

        $room1 = Room::create(['name' => 'Ideas', 'wing_id' => $wing->id]);
        $room2 = Room::create(['name' => 'Notes', 'wing_id' => $wing->id]);

        $this->assertCount(2, $wing->rooms);
        $this->assertTrue($wing->rooms->contains($room1));
        $this->assertTrue($wing->rooms->contains($room2));
    }

    public function test_wing_slug_is_unique(): void
    {
        Wing::create(['name' => 'Test Wing', 'slug' => 'test-wing']);

        $this->expectException(QueryException::class);

        Wing::create(['name' => 'Another Wing', 'slug' => 'test-wing']);
    }
}
