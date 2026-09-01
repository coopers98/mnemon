<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ContactSubmissions\ContactSubmissionResource;
use App\Filament\Resources\ContactSubmissions\Pages\ListContactSubmissions;
use App\Models\ContactSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ContactSubmissionResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_list_page_renders_for_authenticated_user(): void
    {
        Livewire::test(ListContactSubmissions::class)
            ->assertSuccessful();
    }

    public function test_list_page_shows_submissions(): void
    {
        $submission = ContactSubmission::create([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'message' => 'An objection to the compile pipeline.',
        ]);

        Livewire::test(ListContactSubmissions::class)
            ->assertCanSeeTableRecords([$submission])
            ->assertSee('Ada Lovelace')
            ->assertSee('ada@example.com');
    }

    public function test_submissions_are_read_only(): void
    {
        $submission = ContactSubmission::create([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'message' => 'An objection to the compile pipeline.',
        ]);

        // The contact form is the only writer; the panel is a reader.
        $this->assertFalse(ContactSubmissionResource::canCreate());
        $this->assertFalse(ContactSubmissionResource::canEdit($submission));
        $this->assertFalse(ContactSubmissionResource::canDelete($submission));
    }

    public function test_newest_submission_sorts_first(): void
    {
        $older = ContactSubmission::create([
            'name' => 'Older',
            'email' => 'older@example.com',
            'message' => 'first',
        ]);
        $older->forceFill(['created_at' => now()->subDay()])->save();

        $newer = ContactSubmission::create([
            'name' => 'Newer',
            'email' => 'newer@example.com',
            'message' => 'second',
        ]);

        Livewire::test(ListContactSubmissions::class)
            ->assertCanSeeTableRecords([$newer, $older], inOrder: true);
    }
}
