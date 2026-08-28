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

    public function test_slugify_converts_namespace_colons_to_dashes(): void
    {
        $this->assertSame('project-atlas', Wing::slugify('project:atlas'));
        $this->assertSame('person-jane-doe', Wing::slugify('person:jane-doe'));
        $this->assertSame('work', Wing::slugify('Work'));
    }

    public function test_wing_creation_produces_the_slug_wiki_compile_looks_up(): void
    {
        // wiki_compile derives its lookup slug from a page name such as
        // "project:atlas". A wing created from the same name must land on the
        // same slug, or the page can never find its wing.
        $wing = Wing::create(['name' => 'project:atlas']);

        $this->assertSame('project-atlas', $wing->slug);
    }
}
