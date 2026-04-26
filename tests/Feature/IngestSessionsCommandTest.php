<?php

namespace Tests\Feature;

use App\Models\Drawer;
use App\Models\Room;
use App\Models\Wing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class IngestSessionsCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir().'/mnemon-sessions-test-'.uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempDir);
        parent::tearDown();
    }

    // ─── Basic Ingest ────────────────────────────────────────────────────────

    public function test_ingests_session_file_with_correct_wing_and_room(): void
    {
        file_put_contents($this->tempDir.'/2026-04-20-session.md', '# Session transcript');

        $this->artisan('mnemon:ingest-sessions', ['path' => $this->tempDir])
            ->assertSuccessful();

        $wing = Wing::where('slug', 'session-daily')->first();
        $this->assertNotNull($wing);
        $this->assertEquals('session:daily', $wing->name);

        $room = Room::where('wing_id', $wing->id)->where('slug', '2026-04')->first();
        $this->assertNotNull($room);

        $drawer = Drawer::where('room_id', $room->id)->first();
        $this->assertNotNull($drawer);
        $this->assertEquals('# Session transcript', $drawer->content);
        $this->assertEquals('openclaw', $drawer->source);
    }

    public function test_ingests_multiple_sessions_into_correct_rooms(): void
    {
        file_put_contents($this->tempDir.'/2026-03-15-morning.md', 'March session');
        file_put_contents($this->tempDir.'/2026-04-20-afternoon.md', 'April session');

        $this->artisan('mnemon:ingest-sessions', ['path' => $this->tempDir])
            ->assertSuccessful();

        $wing = Wing::where('slug', 'session-daily')->first();
        $this->assertNotNull($wing);

        $rooms = Room::where('wing_id', $wing->id)->pluck('slug')->sort()->values()->all();
        $this->assertEquals(['2026-03', '2026-04'], $rooms);
        $this->assertEquals(2, Drawer::count());
    }

    // ─── Source Field ────────────────────────────────────────────────────────

    public function test_source_is_set_to_openclaw(): void
    {
        file_put_contents($this->tempDir.'/2026-04-20.md', 'Content');

        $this->artisan('mnemon:ingest-sessions', ['path' => $this->tempDir])
            ->assertSuccessful();

        $drawer = Drawer::first();
        $this->assertEquals('openclaw', $drawer->source);
    }

    // ─── Deduplication ───────────────────────────────────────────────────────

    public function test_skips_duplicate_content_on_reingest(): void
    {
        file_put_contents($this->tempDir.'/2026-04-20.md', 'Same content');

        $this->artisan('mnemon:ingest-sessions', ['path' => $this->tempDir])
            ->assertSuccessful();
        $this->assertEquals(1, Drawer::count());

        // Ingest again — should skip
        $this->artisan('mnemon:ingest-sessions', ['path' => $this->tempDir])
            ->assertSuccessful();
        $this->assertEquals(1, Drawer::count());
    }

    // ─── Dry Run ─────────────────────────────────────────────────────────────

    public function test_dry_run_does_not_create_records(): void
    {
        file_put_contents($this->tempDir.'/2026-04-20.md', 'Test content');

        $this->artisan('mnemon:ingest-sessions', [
            'path' => $this->tempDir,
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertEquals(0, Wing::count());
        $this->assertEquals(0, Room::count());
        $this->assertEquals(0, Drawer::count());
    }

    // ─── Edge Cases ──────────────────────────────────────────────────────────

    public function test_skips_empty_files(): void
    {
        file_put_contents($this->tempDir.'/2026-04-20.md', '');
        file_put_contents($this->tempDir.'/2026-04-21.md', '   ');

        $this->artisan('mnemon:ingest-sessions', ['path' => $this->tempDir])
            ->assertSuccessful();

        $this->assertEquals(0, Drawer::count());
    }

    public function test_handles_nonexistent_directory(): void
    {
        $this->artisan('mnemon:ingest-sessions', ['path' => '/nonexistent/path'])
            ->assertFailed();
    }

    public function test_handles_empty_directory(): void
    {
        $emptyDir = $this->tempDir.'/empty';
        mkdir($emptyDir, 0755, true);

        $this->artisan('mnemon:ingest-sessions', ['path' => $emptyDir])
            ->assertSuccessful();

        $this->assertEquals(0, Drawer::count());
    }

    // ─── Room Inference ──────────────────────────────────────────────────────

    public function test_infers_room_from_date_in_filename(): void
    {
        file_put_contents($this->tempDir.'/2026-04-20-sprint-review.md', 'Sprint review');

        $this->artisan('mnemon:ingest-sessions', ['path' => $this->tempDir])
            ->assertSuccessful();

        $wing = Wing::where('slug', 'session-daily')->first();
        $room = Room::where('wing_id', $wing->id)->first();
        $this->assertEquals('2026-04', $room->slug);
    }

    public function test_uses_current_month_for_files_without_date(): void
    {
        file_put_contents($this->tempDir.'/random-session.md', 'No date in name');

        $this->artisan('mnemon:ingest-sessions', ['path' => $this->tempDir])
            ->assertSuccessful();

        $wing = Wing::where('slug', 'session-daily')->first();
        $room = Room::where('wing_id', $wing->id)->first();
        $this->assertEquals(now()->format('Y-m'), $room->slug);
    }

    // ─── Metadata ────────────────────────────────────────────────────────────

    public function test_metadata_contains_content_hash_and_original_path(): void
    {
        $filePath = $this->tempDir.'/2026-04-20.md';
        file_put_contents($filePath, 'Session content');

        $this->artisan('mnemon:ingest-sessions', ['path' => $this->tempDir])
            ->assertSuccessful();

        $drawer = Drawer::first();
        $this->assertIsArray($drawer->metadata);
        $this->assertArrayHasKey('content_hash', $drawer->metadata);
        $this->assertArrayHasKey('original_path', $drawer->metadata);
        $this->assertEquals(hash('sha256', 'Session content'), $drawer->metadata['content_hash']);
        // Path should be relative to the base import directory
        $this->assertEquals('2026-04-20.md', $drawer->metadata['original_path']);
    }

    // ─── Only Processes .md Files ────────────────────────────────────────────

    public function test_only_processes_markdown_files(): void
    {
        file_put_contents($this->tempDir.'/session.md', 'Valid session');
        file_put_contents($this->tempDir.'/notes.txt', 'Not markdown');
        file_put_contents($this->tempDir.'/data.json', '{"key": "value"}');

        $this->artisan('mnemon:ingest-sessions', ['path' => $this->tempDir])
            ->assertSuccessful();

        $this->assertEquals(1, Drawer::count());
    }
}
