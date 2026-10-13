<?php

namespace Tests\Feature\Demo;

use App\Models\Drawer;
use App\Models\WikiPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoCommandGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_decay_confidence_refuses_in_demo_mode(): void
    {
        config(['mnemon.demo.enabled' => true]);
        $page = WikiPage::factory()->create(['confidence_score' => 0.987]);

        $this->artisan('mnemon:decay-confidence')->assertFailed();

        $this->assertSame(0.987, (float) $page->fresh()->confidence_score);
    }

    public function test_apply_retention_refuses_in_demo_mode(): void
    {
        config(['mnemon.demo.enabled' => true]);
        $drawer = Drawer::factory()->create(['retention_score' => 0.0, 'created_at' => now()->subYears(5)]);

        $this->artisan('mnemon:apply-retention --force')->assertFailed();

        $this->assertNotSoftDeleted($drawer);
    }
}
