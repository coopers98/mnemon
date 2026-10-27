<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\Drawer;
use App\Models\Wing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\MakesMcpRequests;
use Tests\TestCase;

class DrawerAddProjectTest extends TestCase
{
    use MakesMcpRequests, RefreshDatabase;

    private function add(array $args, ?array $patterns = null): TestResponse
    {
        return $this->mcpCall('drawer_add', $args + ['room' => 'notes', 'content' => 'a note'], ['mcp:use'], $patterns);
    }

    public function test_project_resolves_to_existing_project_wing(): void
    {
        $wing = Wing::create(['name' => 'Project Atlas', 'slug' => 'project-atlas']);

        $res = $this->add(['project' => 'Atlas']);

        $res->assertOk();
        $this->assertSame('project-atlas', $res->json('result.structuredContent.wing'));
        $this->assertTrue($res->json('result.structuredContent.created'));
        $this->assertSame($wing->id, Drawer::firstOrFail()->room->wing_id);
        $this->assertSame(1, Wing::count());
    }

    public function test_project_resolves_through_an_alias(): void
    {
        $wing = Wing::create(['name' => 'Project Atlas', 'slug' => 'project-atlas', 'aliases' => ['old-atlas']]);

        $res = $this->add(['project' => 'old-atlas']);

        $this->assertSame('project-atlas', $res->json('result.structuredContent.wing'));
        $this->assertSame($wing->id, Drawer::firstOrFail()->room->wing_id);
        $this->assertSame(1, Wing::count());
    }

    public function test_project_creates_wing_for_unrestricted_token(): void
    {
        $res = $this->add(['project' => 'Brand New']);

        $this->assertSame('project-brand-new', $res->json('result.structuredContent.wing'));
        $this->assertNotNull(Wing::where('slug', 'project-brand-new')->first());
    }

    public function test_restricted_token_cannot_reach_a_hidden_project_wing(): void
    {
        Wing::create(['name' => 'Project Secret', 'slug' => 'project-secret']);

        $hidden = $this->add(['project' => 'secret'], ['work']);
        $missing = $this->add(['project' => 'ghost'], ['work']);

        $this->assertTrue($hidden->json('result.isError'));
        $this->assertSame(
            str_replace('secret', 'X', $hidden->json('result.content.0.text')),
            str_replace('ghost', 'X', $missing->json('result.content.0.text')),
        );
        $this->assertSame(
            'Token does not have access to wing: project-ghost',
            $missing->json('result.content.0.text'),
        );
        $this->assertSame(0, Drawer::count());
        $this->assertNull(Wing::where('slug', 'project-ghost')->first());
    }

    public function test_wing_and_project_together_is_an_error(): void
    {
        $this->add(['wing' => 'work', 'project' => 'atlas'])->assertJsonPath('result.isError', true);
        $this->assertSame(0, Drawer::count());
        $this->assertSame(0, Wing::count());
    }

    public function test_neither_wing_nor_project_is_an_error(): void
    {
        $this->add([])->assertJsonPath('result.isError', true);
    }

    public function test_result_reports_created_false_on_dedup(): void
    {
        $first = $this->add(['project' => 'atlas']);
        $second = $this->add(['project' => 'atlas']);

        $this->assertTrue($first->json('result.structuredContent.created'));
        $this->assertFalse($second->json('result.structuredContent.created'));
        $this->assertSame($first->json('result.structuredContent.id'), $second->json('result.structuredContent.id'));
    }

    public function test_wing_argument_still_reports_wing_and_created(): void
    {
        $res = $this->add(['wing' => 'My Work']);

        $this->assertSame('my-work', $res->json('result.structuredContent.wing'));
        $this->assertTrue($res->json('result.structuredContent.created'));
    }
}
