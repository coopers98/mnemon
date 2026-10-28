<?php

namespace Tests\Unit\Models;

use App\Models\DigestUsage;
use App\Models\LlmModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LlmModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_cost_charges_cached_input_at_its_own_price(): void
    {
        $m = new LlmModel(['price_in_per_mtok' => '1', 'price_cached_in_per_mtok' => '0.1', 'price_out_per_mtok' => '10']);

        // 600k uncached at $1, 400k cached at $0.10, 100k out at $10.
        $this->assertEqualsWithDelta(0.6 + 0.04 + 1.0, $m->costOf(1_000_000, 400_000, 100_000), 1e-9);
    }

    public function test_cached_input_falls_back_to_the_input_price(): void
    {
        $m = new LlmModel(['price_in_per_mtok' => '1', 'price_out_per_mtok' => '10']);

        $this->assertEqualsWithDelta(1.0, $m->costOf(1_000_000, 400_000, 0), 1e-9);
    }

    public function test_cost_is_unknown_without_input_and_output_prices(): void
    {
        $this->assertNull((new LlmModel(['price_in_per_mtok' => '1']))->costOf(10, 0, 10));
        $this->assertNull((new LlmModel(['price_out_per_mtok' => '1']))->costOf(10, 0, 10));
    }

    public function test_marking_a_digest_model_unmarks_the_others(): void
    {
        $a = LlmModel::create(['provider' => 'openai', 'model' => 'a', 'is_digest_model' => true]);
        $b = LlmModel::create(['provider' => 'openai', 'model' => 'b', 'is_digest_model' => true]);

        $this->assertFalse($a->fresh()->is_digest_model);
        $this->assertSame($b->id, LlmModel::digestModel()->id);
    }

    public function test_usage_totals_count_only_this_provider_and_model(): void
    {
        $m = LlmModel::create(['provider' => 'openai', 'model' => 'a']);
        $row = ['provider' => 'openai', 'model' => 'a', 'prompt_tokens' => 100, 'cached_prompt_tokens' => 10, 'completion_tokens' => 20];
        DigestUsage::create($row);
        DigestUsage::create($row);
        DigestUsage::create(['model' => 'b'] + $row);
        DigestUsage::create(['provider' => 'other'] + $row);

        $u = $m->usageTotals();

        $this->assertSame([2, 200, 20, 40], [$u->calls, $u->prompt_tokens, $u->cached_prompt_tokens, $u->completion_tokens]);
    }
}
