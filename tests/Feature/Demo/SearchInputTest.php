<?php

namespace Tests\Feature\Demo;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchInputTest extends TestCase
{
    use RefreshDatabase;

    public function test_array_query_is_treated_as_empty_when_signed_in(): void
    {
        $this->actingAs(User::factory()->create())->get('/wiki/search?q[]=x')->assertOk();
    }

    public function test_array_query_is_treated_as_empty_in_demo_mode(): void
    {
        config(['mnemon.demo.enabled' => true, 'app.debug' => false]);
        $this->get('/wiki/search?q[]=x')->assertOk();
    }

    public function test_search_tolerates_sql_punctuation(): void
    {
        config(['mnemon.demo.enabled' => true, 'app.debug' => false]);
        foreach (['%', '_', "'", '"', '\\', '%%_\'--'] as $q) {
            $this->get('/wiki/search?q='.urlencode($q))->assertOk();
        }
    }
}
