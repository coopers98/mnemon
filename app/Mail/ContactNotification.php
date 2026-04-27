<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContactNotification extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array{name: string, email: string, message: string}  $submission
     */
    public function __construct(public array $submission) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Mnemon · Correspondence from '.$this->submission['name'],
            replyTo: [$this->submission['email'] => $this->submission['name']],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.contact-notification',
            with: [
                'submission' => $this->submission,
            ],
        );
    }
}
