<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\LlmModels\Pages\CreateLlmModel;
use App\Filament\Resources\LlmModels\Pages\EditLlmModel;
use App\Filament\Resources\LlmModels\Pages\ListLlmModels;
use App\Models\DigestUsage;
use App\Models\LlmModel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LlmModelResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_the_configured_model_has_a_row_to_price(): void
    {
        // Added by the migration, unpriced and unmarked.
        $row = LlmModel::for('openai', config('mnemon.llm.model'));

        $this->assertNotNull($row);
        $this->assertNull($row->price_in_per_mtok);
        $this->assertFalse($row->is_digest_model);
    }

    public function test_list_shows_usage_averages_and_spend_at_the_current_price(): void
    {
        $m = LlmModel::create(['provider' => 'openai', 'model' => 'gpt-a', 'price_in_per_mtok' => '1', 'price_out_per_mtok' => '10']);
        $unpriced = LlmModel::create(['provider' => 'openai', 'model' => 'gpt-b']);
        $row = ['provider' => 'openai', 'model' => 'gpt-a', 'prompt_tokens' => 500_000, 'completion_tokens' => 50_000];
        DigestUsage::create($row);
        DigestUsage::create($row);
        DigestUsage::create(['model' => 'gpt-b'] + $row);

        Livewire::test(ListLlmModels::class)
            ->assertCanSeeTableRecords([$m, $unpriced])
            ->assertTableColumnStateSet('calls', 2, $m)
            ->assertTableColumnStateSet('avg_out', 50_000, $m)
            ->assertTableColumnStateSet('spend', '$2.00', $m)
            ->assertTableColumnStateSet('spend', 'no price', $unpriced);
    }

    public function test_a_price_edit_re_prices_recorded_usage(): void
    {
        $m = LlmModel::create(['provider' => 'openai', 'model' => 'gpt-a', 'price_in_per_mtok' => '1', 'price_out_per_mtok' => '10']);
        DigestUsage::create(['provider' => 'openai', 'model' => 'gpt-a', 'prompt_tokens' => 1_000_000, 'completion_tokens' => 0]);

        Livewire::test(EditLlmModel::class, ['record' => $m->getRouteKey()])
            ->fillForm(['price_in_per_mtok' => '3'])
            ->call('save')
            ->assertHasNoFormErrors();

        Livewire::test(ListLlmModels::class)->assertTableColumnStateSet('spend', '$3.00', $m->fresh());
    }

    public function test_create_a_model_and_mark_it_for_digests(): void
    {
        Livewire::test(CreateLlmModel::class)
            ->fillForm(['provider' => 'openai', 'model' => 'gpt-new', 'price_in_per_mtok' => '0.5', 'price_out_per_mtok' => '4', 'is_digest_model' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('gpt-new', LlmModel::digestModel()->model);
    }

    public function test_only_a_provider_with_a_driver_can_be_marked_for_digests(): void
    {
        Livewire::test(CreateLlmModel::class)
            ->fillForm(['provider' => 'anthropic', 'model' => 'claude-x', 'is_digest_model' => true])
            ->call('create')
            ->assertHasFormErrors(['is_digest_model']);

        // Kept for price comparison, unmarked, it saves.
        Livewire::test(CreateLlmModel::class)
            ->fillForm(['provider' => 'anthropic', 'model' => 'claude-x', 'price_in_per_mtok' => '3', 'price_out_per_mtok' => '15'])
            ->call('create')
            ->assertHasNoFormErrors();
    }

    public function test_the_same_model_cannot_be_entered_twice_for_a_provider(): void
    {
        LlmModel::create(['provider' => 'openai', 'model' => 'gpt-a']);

        Livewire::test(CreateLlmModel::class)
            ->fillForm(['provider' => 'openai', 'model' => 'gpt-a'])
            ->call('create')
            ->assertHasFormErrors(['model']);
    }

    public function test_a_model_id_with_a_space_is_rejected(): void
    {
        Livewire::test(CreateLlmModel::class)
            ->fillForm(['provider' => 'openai', 'model' => 'gpt-5.6-luna ', 'is_digest_model' => true])
            ->call('create')
            ->assertHasFormErrors(['model']);

        $this->assertNull(LlmModel::digestModel());
    }

    public function test_prices_display_without_padding(): void
    {
        $m = LlmModel::create(['provider' => 'openai', 'model' => 'gpt-a', 'price_in_per_mtok' => '0.250000']);

        Livewire::test(ListLlmModels::class)->assertTableColumnFormattedStateSet('price_in_per_mtok', '0.25', $m);
    }

    public function test_a_negative_price_is_rejected(): void
    {
        Livewire::test(CreateLlmModel::class)
            ->fillForm(['provider' => 'openai', 'model' => 'gpt-z', 'price_in_per_mtok' => '-1'])
            ->call('create')
            ->assertHasFormErrors(['price_in_per_mtok']);
    }
}
