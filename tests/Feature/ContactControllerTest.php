<?php

namespace Tests\Feature;

use App\Mail\ContactNotification;
use App\Models\ContactSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ContactControllerTest extends TestCase
{
    use RefreshDatabase;

    private function submit(): TestResponse
    {
        return $this->post('/contact', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'message' => 'Hello from the landing page.',
        ]);
    }

    public function test_no_mail_is_sent_when_contact_to_is_not_configured(): void
    {
        config(['mnemon.contact_to' => null]);
        Mail::fake();

        $this->submit();

        Mail::assertNothingSent();
    }

    public function test_mail_is_sent_to_the_configured_address_when_contact_to_is_set(): void
    {
        config(['mnemon.contact_to' => 'ops@example.com']);
        Mail::fake();

        $this->submit();

        Mail::assertSent(ContactNotification::class, function (ContactNotification $mail) {
            return $mail->hasTo('ops@example.com');
        });
    }

    public function test_submission_is_persisted_when_contact_to_is_not_configured(): void
    {
        config(['mnemon.contact_to' => null]);
        Mail::fake();

        $this->submit();

        $this->assertDatabaseHas(ContactSubmission::class, [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'message' => 'Hello from the landing page.',
        ]);
    }

    public function test_submission_is_persisted_when_contact_to_is_configured(): void
    {
        config(['mnemon.contact_to' => 'ops@example.com']);
        Mail::fake();

        $this->submit();

        $this->assertDatabaseHas(ContactSubmission::class, [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'message' => 'Hello from the landing page.',
        ]);
    }
}
