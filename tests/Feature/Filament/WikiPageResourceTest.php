<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\WikiPages\Pages\CreateWikiPage;
use App\Filament\Resources\WikiPages\Pages\EditWikiPage;
use App\Filament\Resources\WikiPages\Pages\ListWikiPages;
use App\Filament\Resources\WikiPages\Pages\ViewWikiPage;
use App\Models\User;
use App\Models\WikiPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class WikiPageResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Skip embedding generation in tests so the WikiPageObserver doesn't
        // try to call out to OpenAI / Ollama.
        config(['mnemon.embedding.driver' => 'none']);

        $this->actingAs(User::factory()->create());
    }

    public function test_list_page_renders_for_authenticated_user(): void
    {
        Livewire::test(ListWikiPages::class)
            ->assertSuccessful();
    }

    public function test_list_page_shows_wiki_pages(): void
    {
        $alice = WikiPage::create([
            'name' => 'person:alice',
            'type' => 'person',
            'content' => 'About Alice',
        ]);
        $atlas = WikiPage::create([
            'name' => 'project:atlas',
            'type' => 'project',
            'content' => 'About Atlas',
        ]);

        Livewire::test(ListWikiPages::class)
            ->assertCanSeeTableRecords([$alice, $atlas]);
    }

    public function test_type_badge_column_shows_the_type(): void
    {
        $page = WikiPage::create([
            'name' => 'project:atlas',
            'type' => 'project',
            'content' => 'About Atlas',
        ]);

        Livewire::test(ListWikiPages::class)
            ->assertCanSeeTableRecords([$page])
            ->assertSee('project');
    }

    public function test_type_filter_narrows_results(): void
    {
        $person = WikiPage::create([
            'name' => 'person:alice',
            'type' => 'person',
            'content' => 'About Alice',
        ]);
        $project = WikiPage::create([
            'name' => 'project:atlas',
            'type' => 'project',
            'content' => 'About Atlas',
        ]);

        Livewire::test(ListWikiPages::class)
            ->filterTable('type', 'person')
            ->assertCanSeeTableRecords([$person])
            ->assertCanNotSeeTableRecords([$project]);
    }

    public function test_word_count_column_reflects_content(): void
    {
        $page = WikiPage::create([
            'name' => 'concept:wordy',
            'type' => 'concept',
            'content' => 'one two three',
        ]);

        $this->assertSame(3, $page->word_count);

        Livewire::test(ListWikiPages::class)
            ->assertCanSeeTableRecords([$page])
            ->assertTableColumnExists('word_count');
    }

    public function test_create_form_persists_a_wiki_page_and_stamps_last_compiled_at(): void
    {
        Carbon::setTestNow('2026-04-25 12:00:00');

        Livewire::test(CreateWikiPage::class)
            ->fillForm([
                'name' => 'project:mnemon',
                'type' => 'project',
                'description' => 'The second brain itself',
                'content' => 'Mnemon is a self-hosted second brain.',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('wiki_pages', [
            'name' => 'project:mnemon',
            'type' => 'project',
            'description' => 'The second brain itself',
        ]);

        $page = WikiPage::where('name', 'project:mnemon')->first();
        $this->assertNotNull($page->last_compiled_at);
        $this->assertTrue($page->last_compiled_at->equalTo(Carbon::parse('2026-04-25 12:00:00')));

        Carbon::setTestNow();
    }

    public function test_edit_form_updates_content_and_bumps_last_compiled_at(): void
    {
        $page = WikiPage::create([
            'name' => 'concept:original',
            'type' => 'concept',
            'content' => 'original',
            'last_compiled_at' => Carbon::parse('2026-01-01 00:00:00'),
        ]);

        Carbon::setTestNow('2026-04-25 09:30:00');

        Livewire::test(EditWikiPage::class, ['record' => $page->getRouteKey()])
            ->fillForm([
                'content' => 'updated content',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $page->refresh();

        $this->assertSame('updated content', $page->content);
        $this->assertTrue($page->last_compiled_at->equalTo(Carbon::parse('2026-04-25 09:30:00')));

        Carbon::setTestNow();
    }

    public function test_edit_form_does_not_bump_last_compiled_at_for_description_only_change(): void
    {
        $originalCompiledAt = Carbon::parse('2026-01-01 00:00:00');

        $page = WikiPage::create([
            'name' => 'concept:stable',
            'type' => 'concept',
            'description' => 'original description',
            'content' => 'unchanged content',
            'last_compiled_at' => $originalCompiledAt,
        ]);

        Carbon::setTestNow('2026-04-25 09:30:00');

        Livewire::test(EditWikiPage::class, ['record' => $page->getRouteKey()])
            ->fillForm([
                'description' => 'updated description only',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $page->refresh();

        $this->assertSame('updated description only', $page->description);
        $this->assertSame('unchanged content', $page->content);
        $this->assertTrue($page->last_compiled_at->equalTo($originalCompiledAt));

        Carbon::setTestNow();
    }

    public function test_create_form_requires_name(): void
    {
        Livewire::test(CreateWikiPage::class)
            ->fillForm([
                'name' => '',
                'type' => 'concept',
                'content' => 'has content',
            ])
            ->call('create')
            ->assertHasFormErrors(['name' => 'required']);
    }

    public function test_create_form_requires_type(): void
    {
        Livewire::test(CreateWikiPage::class)
            ->fillForm([
                'name' => 'concept:typeless',
                'type' => null,
                'content' => 'has content',
            ])
            ->call('create')
            ->assertHasFormErrors(['type' => 'required']);
    }

    public function test_create_form_requires_content(): void
    {
        Livewire::test(CreateWikiPage::class)
            ->fillForm([
                'name' => 'concept:empty',
                'type' => 'concept',
                'content' => '',
            ])
            ->call('create')
            ->assertHasFormErrors(['content' => 'required']);
    }

    public function test_delete_action_removes_the_record(): void
    {
        $page = WikiPage::create([
            'name' => 'concept:expendable',
            'type' => 'concept',
            'content' => 'goodbye',
        ]);

        Livewire::test(EditWikiPage::class, ['record' => $page->getRouteKey()])
            ->callAction('delete');

        $this->assertDatabaseMissing('wiki_pages', ['id' => $page->id]);
    }

    public function test_default_sort_is_last_compiled_at_descending(): void
    {
        $oldest = WikiPage::create([
            'name' => 'concept:oldest',
            'type' => 'concept',
            'content' => 'old',
            'last_compiled_at' => Carbon::parse('2026-01-01 00:00:00'),
        ]);
        $newest = WikiPage::create([
            'name' => 'concept:newest',
            'type' => 'concept',
            'content' => 'new',
            'last_compiled_at' => Carbon::parse('2026-04-01 00:00:00'),
        ]);
        $middle = WikiPage::create([
            'name' => 'concept:middle',
            'type' => 'concept',
            'content' => 'mid',
            'last_compiled_at' => Carbon::parse('2026-02-15 00:00:00'),
        ]);

        Livewire::test(ListWikiPages::class)
            ->assertCanSeeTableRecords([$newest, $middle, $oldest], inOrder: true);
    }

    public function test_default_sort_places_null_last_compiled_at_last(): void
    {
        $newer = WikiPage::create([
            'name' => 'concept:newer',
            'type' => 'concept',
            'content' => 'newer',
            'last_compiled_at' => Carbon::parse('2026-04-25 00:00:00'),
        ]);
        $older = WikiPage::create([
            'name' => 'concept:older',
            'type' => 'concept',
            'content' => 'older',
            'last_compiled_at' => Carbon::parse('2026-04-24 00:00:00'),
        ]);
        $never = WikiPage::create([
            'name' => 'concept:never',
            'type' => 'concept',
            'content' => 'never compiled',
            'last_compiled_at' => null,
        ]);

        Livewire::test(ListWikiPages::class)
            ->assertCanSeeTableRecords([$newer, $older, $never], inOrder: true);
    }

    public function test_view_page_renders_content_as_markdown(): void
    {
        $page = WikiPage::create([
            'name' => 'concept:visible',
            'type' => 'concept',
            'description' => 'A page meant to be viewed',
            'content' => "# Visible heading\n\nThis is some body text on the view page.",
            'last_compiled_at' => Carbon::parse('2026-04-20 00:00:00'),
        ]);

        Livewire::test(ViewWikiPage::class, ['record' => $page->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Visible heading')
            ->assertSee('This is some body text on the view page.')
            ->assertSee('A page meant to be viewed')
            ->assertSee('concept');
    }

    public function test_word_count_accessor_handles_missing_content(): void
    {
        $page = new WikiPage(['content' => null]);

        $this->assertSame(0, $page->word_count);
    }
}
