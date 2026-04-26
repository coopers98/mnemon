<?php

namespace App\Http\Controllers;

use App\Mail\ContactNotification;
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

        try {
            Mail::to('coopersellers@gmail.com')->send(new ContactNotification($validated));
        } catch (\Throwable $e) {
            report($e);
        }

        return redirect('/')->with('contact_success', 'Thanks for reaching out! We\'ll get back to you soon.');
    }
}
