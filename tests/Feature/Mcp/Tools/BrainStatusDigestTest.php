<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\Room;
use App\Models\Wing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesMcpRequests;
use Tests\TestCase;

class BrainStatusDigestTest extends TestCase
{
    use MakesMcpRequests, RefreshDatabase;

    private function digest(array $args = [], ?array $patterns = null): array
    {
        // The guard caches the first request's token, and with it its wing restriction.
        $this->app['auth']->forgetGuards();

        return $this->mcpCall('brain_status', $args, ['mcp:use'], $patterns)->json('result.structuredContent.digest');
    }

    public function test_digest_block_reports_model_and_null_prices_by_default(): void
    {
        config([
            'mnemon.llm.model' => 'gpt-test',
            'mnemon.digest.openai_model' => null,
            'mnemon.digest.pricing.in_per_mtok' => null,
            'mnemon.digest.pricing.out_per_mtok' => null,
        ]);

        $digest = $this->digest();

        $this->assertSame('gpt-test', $digest['model']);
        $this->assertNull($digest['price_in_per_mtok']);
        $this->assertNull($digest['price_out_per_mtok']);
        $this->assertSame(3000, $digest['typical_output_tokens']);
        $this->assertSame(16000, $digest['max_completion_tokens']);
        $this->assertIsInt($digest['prompt_overhead_tokens']);
        $this->assertArrayNotHasKey('projects', $digest);
    }

    public function test_digest_block_reports_configured_prices(): void
    {
        config([
            'mnemon.digest.pricing.in_per_mtok' => '0.25',
            'mnemon.digest.pricing.out_per_mtok' => '2',
        ]);

        $digest = $this->digest();

        $this->assertSame(0.25, $digest['price_in_per_mtok']);
        $this->assertEquals(2, $digest['price_out_per_mtok']);
    }

    public function test_prompt_overhead_grows_with_wings_and_rooms(): void
    {
        $empty = $this->digest()['prompt_overhead_tokens'];

        $wing = Wing::create(['name' => 'Alpha', 'slug' => 'alpha-wing-with-a-long-name']);
        $withWing = $this->digest()['prompt_overhead_tokens'];

        Room::create(['wing_id' => $wing->id, 'name' => 'Meeting Notes', 'slug' => 'meeting-notes-and-more']);
        $withRoom = $this->digest()['prompt_overhead_tokens'];

        $this->assertGreaterThan($empty, $withWing);
        $this->assertGreaterThan($withWing, $withRoom);
        // The 50 x 200 character recent list is always counted.
        $this->assertGreaterThanOrEqual(2500, $empty);
    }

    public function test_prompt_overhead_counts_only_the_tokens_visible_wings(): void
    {
        Wing::create(['name' => 'Work', 'slug' => 'work']);
        $hidden = Wing::create(['name' => 'Personal', 'slug' => 'personal-wing-with-a-very-long-slug-indeed']);
        Room::create(['wing_id' => $hidden->id, 'name' => 'Diary', 'slug' => 'diary-room-with-a-long-slug']);

        $this->assertLessThan(
            $this->digest()['prompt_overhead_tokens'],
            $this->digest([], ['work'])['prompt_overhead_tokens'],
        );
    }

    public function test_projects_resolve_to_visible_wings_only(): void
    {
        Wing::create(['name' => 'Project Mnemon', 'slug' => 'project-mnemon']);
        Wing::create(['name' => 'Project Secret', 'slug' => 'project-secret']);

        $digest = $this->digest(['projects' => ['mnemon', 'secret', 'ghost']], ['project-mnemon']);

        $this->assertSame(['mnemon' => 'project-mnemon', 'secret' => null, 'ghost' => null], $digest['projects']);
    }

    public function test_an_unrestricted_token_gets_null_for_a_project_with_no_wing(): void
    {
        Wing::create(['name' => 'Project Mnemon', 'slug' => 'project-mnemon']);

        $digest = $this->digest(['projects' => ['mnemon', 'ghost']]);

        $this->assertSame(['mnemon' => 'project-mnemon', 'ghost' => null], $digest['projects']);
    }

    public function test_projects_resolve_through_aliases(): void
    {
        Wing::create(['name' => 'Project Cora', 'slug' => 'project-cora', 'aliases' => ['recital-lineup']]);

        $digest = $this->digest(['projects' => ['recital-lineup']]);

        $this->assertSame(['recital-lineup' => 'project-cora'], $digest['projects']);
    }

    public function test_more_than_500_projects_are_refused(): void
    {
        $res = $this->mcpCall('brain_status', ['projects' => array_map(fn ($i) => "p{$i}", range(1, 501))]);

        $this->assertTrue($res->json('result.isError') ?? false || $res->json('error') !== null);
    }
}
