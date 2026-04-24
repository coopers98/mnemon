<?php

namespace Tests\Feature;

use App\Models\WikiPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WikiPageEmbeddingTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_wiki_page_with_none_driver_sets_embedding_to_null(): void
    {
        config(['mnemon.embedding.driver' => 'none']);

        $wikiPage = WikiPage::create([
            'name' => 'test-page',
            'type' => 'concept',
            'title' => 'Test Page',
            'content' => 'Test content',
        ]);

        $this->assertNull($wikiPage->embedding);
    }

    public function test_updating_wiki_page_content_with_none_driver_keeps_embedding_null(): void
    {
        config(['mnemon.embedding.driver' => 'none']);

        $wikiPage = WikiPage::create([
            'name' => 'test-page',
            'type' => 'concept',
            'title' => 'Test Page',
            'content' => 'Original content',
        ]);

        $wikiPage->update(['content' => 'Updated content']);

        $this->assertNull($wikiPage->fresh()->embedding);
    }

    public function test_updating_wiki_page_without_content_change_does_not_reembed(): void
    {
        config(['mnemon.embedding.driver' => 'none']);

        $wikiPage = WikiPage::create([
            'name' => 'test-page',
            'type' => 'concept',
            'title' => 'Test Page',
            'content' => 'Test content',
        ]);

        $originalEmbedding = $wikiPage->embedding;

        $wikiPage->update(['title' => 'Updated Title']);

        $this->assertEquals($originalEmbedding, $wikiPage->fresh()->embedding);
    }
}
