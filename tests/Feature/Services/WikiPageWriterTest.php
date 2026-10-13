<?php

namespace Tests\Feature\Services;

use App\Models\Drawer;
use App\Models\EntityRelationship;
use App\Models\WikiPage;
use App\Models\WikiPageRevision;
use App\Services\WikiPageWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WikiPageWriterTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_writes_make_two_revisions_and_consolidate_sources(): void
    {
        $drawer = Drawer::factory()->create(['tier' => 'raw']);
        WikiPage::factory()->create(['name' => 'concept:beta']);
        $writer = app(WikiPageWriter::class);

        $writer->write('project:alpha', 'First body, long enough to score.', 'tester', sources: [$drawer->id]);
        $page = $writer->write('project:alpha', 'Second body, long enough to score.', 'tester',
            description: 'Alpha', confidence: 'high', sources: [$drawer->id], related: ['concept:beta']);

        $this->assertSame('project', $page->type);
        $this->assertSame(2, $page->revision_count);
        $this->assertSame(2, WikiPageRevision::where('page_name', 'project:alpha')->count());
        $this->assertSame('Second body, long enough to score.',
            WikiPageRevision::where('page_name', 'project:alpha')->orderByDesc('revision')->value('content'));
        $this->assertSame(hash('sha256', 'First body, long enough to score.'), $page->previous_content_hash);
        $this->assertSame(1, $page->source_count);
        $this->assertNotNull($page->confidence_score);
        $this->assertNotNull($page->quality_score);
        $this->assertSame('consolidated', $drawer->fresh()->tier);
        $this->assertTrue(EntityRelationship::where('from_page', 'project:alpha')->where('to_page', 'concept:beta')->exists());
    }

    public function test_stale_expected_revision_is_a_conflict(): void
    {
        $writer = app(WikiPageWriter::class);
        $writer->write('project:alpha', 'First body, long enough to score.', 'tester');

        try {
            $writer->write('project:alpha', 'Second body, long enough.', 'tester', expectedRevision: 0);
            $this->fail('Expected a conflict');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Conflict', $e->getMessage());
        }

        $this->assertSame(1, WikiPageRevision::where('page_name', 'project:alpha')->count());
        $this->assertSame(1, WikiPage::where('name', 'project:alpha')->value('revision_count'));

        $page = $writer->write('project:alpha', 'Third body, long enough.', 'tester', expectedRevision: 1);
        $this->assertSame(2, $page->revision_count);
    }

    public function test_type_for(): void
    {
        $this->assertSame('person', WikiPageWriter::typeFor('person:rowan'));
        $this->assertSame('decision', WikiPageWriter::typeFor('decision:x'));
        $this->assertSame('synthesis', WikiPageWriter::typeFor('lessons'));
    }
}
