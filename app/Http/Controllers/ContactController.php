<?php

namespace App\Http\Controllers;

use App\Models\ContactSubmission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class ContactController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        ContactSubmission::create($validated);

        // Send notification email via Resend
        try {
            Mail::raw(
                "New contact form submission from Mnemon:\n\n"
                ."Name: {$validated['name']}\n"
                ."Email: {$validated['email']}\n\n"
                ."Message:\n{$validated['message']}",
                function ($mail) use ($validated) {
                    $mail->to('coopersellers@gmail.com')
                        ->replyTo($validated['email'], $validated['name'])
                        ->subject('Mnemon Contact: '.$validated['name']);
                }
            );
        } catch (\Throwable $e) {
            report($e);
        }

        return redirect('/')->with('contact_success', 'Thanks for reaching out! We\'ll get back to you soon.');
    }
}
