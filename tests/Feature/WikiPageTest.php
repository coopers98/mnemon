<?php

namespace Tests\Feature;

use App\Models\WikiPage;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WikiPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_wiki_page_casts_type_as_string(): void
    {
        $page = WikiPage::create([
            'name' => 'alice-cooper',
            'type' => 'person',
            'title' => 'Alice Cooper',
            'content' => 'Biography of Alice Cooper',
        ]);

        $this->assertIsString($page->type);
        $this->assertEquals('person', $page->type);
    }

    public function test_wiki_page_casts_last_compiled_at_as_datetime(): void
    {
        $page = WikiPage::create([
            'name' => 'project-mnemon',
            'type' => 'project',
            'title' => 'Project Mnemon',
            'content' => 'Details about Mnemon',
            'last_compiled_at' => now(),
        ]);

        $this->assertInstanceOf(Carbon::class, $page->last_compiled_at);
    }

    public function test_wiki_page_allows_null_last_compiled_at(): void
    {
        $page = WikiPage::create([
            'name' => 'draft-concept',
            'type' => 'concept',
            'title' => 'Draft Concept',
            'content' => 'Not yet compiled',
        ]);

        $this->assertNull($page->last_compiled_at);
    }

    public function test_wiki_page_name_is_unique(): void
    {
        WikiPage::create([
            'name' => 'alice',
            'type' => 'person',
            'title' => 'Alice',
            'content' => 'First version',
        ]);

        $this->expectException(QueryException::class);

        WikiPage::create([
            'name' => 'alice',
            'type' => 'person',
            'title' => 'Alice v2',
            'content' => 'Second version',
        ]);
    }

    public function test_wiki_page_supports_all_types(): void
    {
        $types = ['person', 'project', 'concept', 'decision', 'synthesis'];

        foreach ($types as $index => $type) {
            $page = WikiPage::create([
                'name' => "test-{$type}-{$index}",
                'type' => $type,
                'title' => ucfirst($type),
                'content' => "Content for {$type}",
            ]);

            $this->assertEquals($type, $page->type);
        }
    }

    public function test_wiki_page_has_optional_description(): void
    {
        $page = WikiPage::create([
            'name' => 'with-description',
            'type' => 'concept',
            'title' => 'Concept with Description',
            'content' => 'Full content',
            'description' => 'Short summary',
        ]);

        $this->assertEquals('Short summary', $page->description);
    }
}
