<?php

namespace Tests\Feature;

use App\Models\WikiPage;
use App\Services\WikiSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WikiSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_returns_matching_wiki_pages(): void
    {
        WikiPage::create([
            'name' => 'project:atlas',
            'type' => 'project',
            'title' => 'Atlas ABS',
            'content' => 'Atlas is an asset-backed securities platform built with Laravel',
        ]);
        WikiPage::create([
            'name' => 'project:cora',
            'type' => 'project',
            'title' => 'Cora',
            'content' => 'Cora is a recital optimization system for dance studios',
        ]);

        $service = app(WikiSearchService::class);
        $results = $service->search('Laravel securities');

        $this->assertCount(1, $results);
        $this->assertEquals('project:atlas', $results->first()->name);
    }

    public function test_type_filter_works(): void
    {
        WikiPage::create([
            'name' => 'person:cooper',
            'type' => 'person',
            'title' => 'Cooper',
            'content' => 'Cooper is the human. Timezone: US Central.',
        ]);
        WikiPage::create([
            'name' => 'project:mnemon',
            'type' => 'project',
            'title' => 'Mnemon',
            'content' => 'Mnemon is a self-hosted second brain built with Laravel.',
        ]);

        $service = app(WikiSearchService::class);
        $results = $service->search('Laravel', type: 'project');

        $this->assertCount(1, $results);
        $this->assertEquals('project', $results->first()->type);
    }

    public function test_limit_is_respected(): void
    {
        for ($i = 0; $i < 10; $i++) {
            WikiPage::create([
                'name' => "concept:thing-{$i}",
                'type' => 'concept',
                'title' => "Thing {$i}",
                'content' => "This concept involves testing and validation number {$i}",
            ]);
        }

        $service = app(WikiSearchService::class);
        $results = $service->search('testing validation', limit: 3);

        $this->assertCount(3, $results);
    }

    public function test_empty_query_returns_empty_collection(): void
    {
        WikiPage::create([
            'name' => 'test:page',
            'type' => 'concept',
            'title' => 'Test',
            'content' => 'Some content',
        ]);

        $service = app(WikiSearchService::class);
        $results = $service->search('');

        $this->assertCount(0, $results);
    }

    public function test_results_include_expected_fields(): void
    {
        WikiPage::create([
            'name' => 'decision:use-pgvector',
            'type' => 'decision',
            'title' => 'Use pgvector',
            'content' => 'We decided to use pgvector for vector similarity search.',
            'description' => 'Vector search backend decision',
            'last_compiled_at' => now(),
        ]);

        $service = app(WikiSearchService::class);
        $results = $service->search('pgvector');

        $this->assertCount(1, $results);
        $result = $results->first();
        $this->assertObjectHasProperty('id', $result);
        $this->assertObjectHasProperty('name', $result);
        $this->assertObjectHasProperty('type', $result);
        $this->assertObjectHasProperty('title', $result);
        $this->assertObjectHasProperty('content', $result);
        $this->assertObjectHasProperty('description', $result);
        $this->assertObjectHasProperty('last_compiled_at', $result);
        $this->assertObjectHasProperty('score', $result);
    }
}
