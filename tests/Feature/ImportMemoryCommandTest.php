<?php

namespace Tests\Feature;

use App\Models\Drawer;
use App\Models\Room;
use App\Models\WikiPage;
use App\Models\Wing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ImportMemoryCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir().'/mnemon-test-'.uniqid();
        mkdir($this->tempDir, 0755, true);
        mkdir($this->tempDir.'/memory', 0755, true);
        mkdir($this->tempDir.'/memory/projects', 0755, true);
        mkdir($this->tempDir.'/memory/people', 0755, true);
        mkdir($this->tempDir.'/memory/decisions', 0755, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempDir);
        parent::tearDown();
    }

    // ─── Daily Files ─────────────────────────────────────────────────────────

    public function test_imports_daily_file_with_correct_wing_and_room(): void
    {
        file_put_contents($this->tempDir.'/memory/2026-04-20.md', '# Daily Notes');

        $this->artisan('mnemon:import-memory', ['path' => $this->tempDir])
            ->assertSuccessful();

        $wing = Wing::where('slug', 'session-daily')->first();
        $this->assertNotNull($wing);
        $this->assertEquals('session:daily', $wing->name);

        $room = Room::where('wing_id', $wing->id)->where('slug', '2026-04')->first();
        $this->assertNotNull($room);

        $drawer = Drawer::where('room_id', $room->id)->first();
        $this->assertNotNull($drawer);
        $this->assertEquals('# Daily Notes', $drawer->content);
        $this->assertEquals('openclaw-import', $drawer->source);
        $this->assertEquals(hash('sha256', '# Daily Notes'), $drawer->metadata['content_hash']);
    }

    public function test_imports_multiple_daily_files_into_correct_rooms(): void
    {
        file_put_contents($this->tempDir.'/memory/2026-03-15.md', 'March notes');
        file_put_contents($this->tempDir.'/memory/2026-04-20.md', 'April notes');

        $this->artisan('mnemon:import-memory', ['path' => $this->tempDir])
            ->assertSuccessful();

        $wing = Wing::where('slug', 'session-daily')->first();
        $this->assertNotNull($wing);

        $rooms = Room::where('wing_id', $wing->id)->pluck('slug')->sort()->values()->all();
        $this->assertEquals(['2026-03', '2026-04'], $rooms);
    }

    // ─── Project Files ───────────────────────────────────────────────────────

    public function test_imports_project_file_with_correct_wing_and_creates_wiki_page(): void
    {
        file_put_contents($this->tempDir.'/memory/projects/atlas-abs.md', '# Atlas ABS Project');

        $this->artisan('mnemon:import-memory', ['path' => $this->tempDir])
            ->assertSuccessful();

        $wing = Wing::where('slug', 'project-atlas-abs')->first();
        $this->assertNotNull($wing);

        $drawer = Drawer::whereHas('room', fn ($q) => $q->where('wing_id', $wing->id))->first();
        $this->assertNotNull($drawer);
        $this->assertEquals('# Atlas ABS Project', $drawer->content);
        $this->assertEquals('openclaw-import', $drawer->source);

        // Wiki page should also be created
        $wikiPage = WikiPage::where('name', 'project:atlas-abs')->first();
        $this->assertNotNull($wikiPage);
        $this->assertEquals('project', $wikiPage->type);
        $this->assertEquals('# Atlas ABS Project', $wikiPage->content);
        $this->assertNotNull($wikiPage->last_compiled_at);
    }

    // ─── People Files ────────────────────────────────────────────────────────

    public function test_imports_person_file_with_correct_wing_and_creates_wiki_page(): void
    {
        file_put_contents($this->tempDir.'/memory/people/cooper.md', '# Cooper Profile');

        $this->artisan('mnemon:import-memory', ['path' => $this->tempDir])
            ->assertSuccessful();

        $wing = Wing::where('slug', 'person-cooper')->first();
        $this->assertNotNull($wing);

        $drawer = Drawer::whereHas('room', fn ($q) => $q->where('wing_id', $wing->id))->first();
        $this->assertNotNull($drawer);
        $this->assertEquals('# Cooper Profile', $drawer->content);

        // Wiki page should also be created
        $wikiPage = WikiPage::where('name', 'person:cooper')->first();
        $this->assertNotNull($wikiPage);
        $this->assertEquals('person', $wikiPage->type);
        $this->assertEquals('# Cooper Profile', $wikiPage->content);
    }

    // ─── Decision Files ──────────────────────────────────────────────────────

    public function test_imports_decision_file_with_correct_wing_and_room(): void
    {
        file_put_contents($this->tempDir.'/memory/decisions/use-laravel.md', '# Why Laravel');

        $this->artisan('mnemon:import-memory', ['path' => $this->tempDir])
            ->assertSuccessful();

        $wing = Wing::where('slug', 'decision')->first();
        $this->assertNotNull($wing);

        $room = Room::where('wing_id', $wing->id)->where('slug', 'use-laravel')->first();
        $this->assertNotNull($room);

        $drawer = Drawer::where('room_id', $room->id)->first();
        $this->assertNotNull($drawer);
        $this->assertEquals('# Why Laravel', $drawer->content);
    }

    // ─── MEMORY.md ───────────────────────────────────────────────────────────

    public function test_imports_memory_md_file_as_memory_index(): void
    {
        file_put_contents($this->tempDir.'/MEMORY.md', '# My Memory Index');

        $this->artisan('mnemon:import-memory', ['path' => $this->tempDir])
            ->assertSuccessful();

        $wing = Wing::where('slug', 'memory-index')->first();
        $this->assertNotNull($wing);

        $room = Room::where('wing_id', $wing->id)->where('slug', 'index')->first();
        $this->assertNotNull($room);

        $drawer = Drawer::where('room_id', $room->id)->first();
        $this->assertNotNull($drawer);
        $this->assertEquals('# My Memory Index', $drawer->content);
    }

    public function test_imports_memory_md_file_directly(): void
    {
        $filePath = $this->tempDir.'/MEMORY.md';
        file_put_contents($filePath, '# Direct MEMORY.md');

        $this->artisan('mnemon:import-memory', ['path' => $filePath])
            ->assertSuccessful();

        $wing = Wing::where('slug', 'memory-index')->first();
        $this->assertNotNull($wing);

        $drawer = Drawer::whereHas('room', fn ($q) => $q->where('wing_id', $wing->id))->first();
        $this->assertNotNull($drawer);
        $this->assertEquals('# Direct MEMORY.md', $drawer->content);
    }

    // ─── Deduplication ───────────────────────────────────────────────────────

    public function test_skips_duplicate_content_on_reimport(): void
    {
        file_put_contents($this->tempDir.'/memory/2026-04-20.md', 'Same content');

        $this->artisan('mnemon:import-memory', ['path' => $this->tempDir])
            ->assertSuccessful();

        $this->assertEquals(1, Drawer::count());

        // Import again — should skip
        $this->artisan('mnemon:import-memory', ['path' => $this->tempDir])
            ->assertSuccessful();

        $this->assertEquals(1, Drawer::count());
    }

    // ─── Dry Run ─────────────────────────────────────────────────────────────

    public function test_dry_run_does_not_create_records(): void
    {
        file_put_contents($this->tempDir.'/memory/2026-04-20.md', 'Test content');
        file_put_contents($this->tempDir.'/memory/projects/test-project.md', 'Project content');

        $this->artisan('mnemon:import-memory', [
            'path' => $this->tempDir,
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertEquals(0, Wing::count());
        $this->assertEquals(0, Room::count());
        $this->assertEquals(0, Drawer::count());
        $this->assertEquals(0, WikiPage::count());
    }

    // ─── Wing Filter ─────────────────────────────────────────────────────────

    public function test_wing_filter_only_imports_specified_type(): void
    {
        file_put_contents($this->tempDir.'/memory/2026-04-20.md', 'Daily notes');
        file_put_contents($this->tempDir.'/memory/projects/test.md', 'Project notes');
        file_put_contents($this->tempDir.'/memory/people/john.md', 'Person notes');

        $this->artisan('mnemon:import-memory', [
            'path' => $this->tempDir,
            '--wing' => 'daily',
        ])->assertSuccessful();

        // Only daily wing should exist
        $this->assertNotNull(Wing::where('slug', 'session-daily')->first());
        $this->assertNull(Wing::where('slug', 'project-test')->first());
        $this->assertNull(Wing::where('slug', 'person-john')->first());
        $this->assertEquals(1, Drawer::count());
    }

    public function test_wing_filter_project_only_imports_projects(): void
    {
        file_put_contents($this->tempDir.'/memory/2026-04-20.md', 'Daily notes');
        file_put_contents($this->tempDir.'/memory/projects/test.md', 'Project notes');

        $this->artisan('mnemon:import-memory', [
            'path' => $this->tempDir,
            '--wing' => 'project',
        ])->assertSuccessful();

        $this->assertNull(Wing::where('slug', 'session-daily')->first());
        $this->assertNotNull(Wing::where('slug', 'project-test')->first());
        $this->assertEquals(1, Drawer::count());
    }

    // ─── Source Field ────────────────────────────────────────────────────────

    public function test_source_is_set_to_openclaw_import(): void
    {
        file_put_contents($this->tempDir.'/memory/2026-04-20.md', 'Content');

        $this->artisan('mnemon:import-memory', ['path' => $this->tempDir])
            ->assertSuccessful();

        $drawer = Drawer::first();
        $this->assertEquals('openclaw-import', $drawer->source);
    }

    // ─── Edge Cases ──────────────────────────────────────────────────────────

    public function test_skips_empty_files(): void
    {
        file_put_contents($this->tempDir.'/memory/2026-04-20.md', '');
        file_put_contents($this->tempDir.'/memory/2026-04-21.md', '   ');

        $this->artisan('mnemon:import-memory', ['path' => $this->tempDir])
            ->assertSuccessful();

        $this->assertEquals(0, Drawer::count());
    }

    public function test_handles_nonexistent_path(): void
    {
        $this->artisan('mnemon:import-memory', ['path' => '/nonexistent/path'])
            ->assertFailed();
    }

    public function test_handles_empty_directory(): void
    {
        $emptyDir = $this->tempDir.'/empty';
        mkdir($emptyDir, 0755, true);

        $this->artisan('mnemon:import-memory', ['path' => $emptyDir])
            ->assertSuccessful();

        $this->assertEquals(0, Drawer::count());
    }

    // ─── Wiki Page Updates ───────────────────────────────────────────────────

    public function test_wiki_page_updated_on_reimport_with_changed_content(): void
    {
        file_put_contents($this->tempDir.'/memory/projects/test.md', 'Version 1');

        $this->artisan('mnemon:import-memory', ['path' => $this->tempDir])
            ->assertSuccessful();

        $page = WikiPage::where('name', 'project:test')->first();
        $this->assertEquals('Version 1', $page->content);

        // Change content and reimport
        file_put_contents($this->tempDir.'/memory/projects/test.md', 'Version 2');

        $this->artisan('mnemon:import-memory', ['path' => $this->tempDir])
            ->assertSuccessful();

        $page->refresh();
        $this->assertEquals('Version 2', $page->content);

        // Should have 2 drawers (different content hashes)
        $this->assertEquals(2, Drawer::count());
    }

    // ─── Security Guards ─────────────────────────────────────────────────────

    public function test_skips_oversized_files(): void
    {
        $filePath = $this->tempDir.'/memory/2026-04-20.md';
        // Create a file just over 10 MB
        $handle = fopen($filePath, 'w');
        fseek($handle, (10 * 1024 * 1024) + 1);
        fwrite($handle, 'x');
        fclose($handle);

        $this->artisan('mnemon:import-memory', ['path' => $this->tempDir])
            ->assertSuccessful();

        $this->assertEquals(0, Drawer::count());
    }

    public function test_validates_path_prefix_correctly_with_sibling_directories(): void
    {
        // Create a sibling directory whose name is a prefix of the base
        $siblingDir = $this->tempDir.'-escape';
        mkdir($siblingDir, 0755, true);
        file_put_contents($siblingDir.'/evil.md', 'Escaped content');

        // Create a symlink inside the base dir pointing to the sibling
        $linkPath = $this->tempDir.'/memory/evil-link.md';
        symlink($siblingDir.'/evil.md', $linkPath);

        file_put_contents($this->tempDir.'/memory/2026-04-20.md', 'Good content');

        $this->artisan('mnemon:import-memory', ['path' => $this->tempDir])
            ->assertSuccessful();

        // Only the legitimate file should be imported
        $this->assertEquals(1, Drawer::count());
        $this->assertEquals('Good content', Drawer::first()->content);

        // Cleanup sibling
        File::deleteDirectory($siblingDir);
    }

    // ─── Metadata ────────────────────────────────────────────────────────────

    public function test_metadata_contains_content_hash_and_original_path(): void
    {
        $filePath = $this->tempDir.'/memory/2026-04-20.md';
        file_put_contents($filePath, 'Some content');

        $this->artisan('mnemon:import-memory', ['path' => $this->tempDir])
            ->assertSuccessful();

        $drawer = Drawer::first();
        $this->assertIsArray($drawer->metadata);
        $this->assertArrayHasKey('content_hash', $drawer->metadata);
        $this->assertArrayHasKey('original_path', $drawer->metadata);
        $this->assertEquals(hash('sha256', 'Some content'), $drawer->metadata['content_hash']);
        // Path should be relative to the base import directory
        $this->assertEquals('memory/2026-04-20.md', $drawer->metadata['original_path']);
    }
}
