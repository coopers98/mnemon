@extends('layouts.mnemon')

@section('title', 'Sign in — Mnemon')

@push('head')
<style>
    .auth-shell { min-height: calc(100vh - 4rem); display: grid; grid-template-columns: 1fr 1fr; }
    @media (max-width: 880px) { .auth-shell { grid-template-columns: 1fr; } .auth-side { display: none; } }
    .auth-form-col { display: grid; align-items: center; padding: clamp(2rem, 6vw, 5rem) clamp(1.5rem, 5vw, 4rem); }
    .auth-form-card { width: 100%; max-width: 24rem; margin: 0 auto; }
    .auth-form-card h1 { font-family: var(--serif); font-size: clamp(2rem, 4vw, 2.75rem); font-weight: 500; letter-spacing: -0.02em; line-height: 1.05; margin: 0 0 0.5rem; }
    .auth-form-card h1 em { color: var(--rubric); font-style: italic; font-weight: 400; }
    .auth-form-card .sub { font-family: var(--serif); font-style: italic; font-size: 1.1rem; color: var(--ink-faint); margin: 0 0 2rem; line-height: 1.45; }
    .auth-form { display: grid; gap: 1.1rem; }
    .auth-form label { display: block; font-family: var(--mono); font-size: var(--t-micro); letter-spacing: 0.18em; text-transform: uppercase; color: var(--ink-faint); margin-bottom: 0.4rem; }
    .auth-form input[type="email"], .auth-form input[type="password"] { width: 100%; font: inherit; font-family: var(--sans); font-size: var(--t-body); color: var(--ink); background: var(--paper); border: var(--hairline) solid var(--rule-strong); padding: 0.7rem 0.85rem; border-radius: 0; transition: border-color 0.15s; }
    .auth-form input:focus { outline: none; border-color: var(--ink); }
    .auth-form .check { display: flex; align-items: center; gap: 0.5rem; font-family: var(--mono); font-size: var(--t-micro); letter-spacing: 0.1em; text-transform: uppercase; color: var(--ink-faint); }
    .auth-form .check input[type="checkbox"] { accent-color: var(--rubric); }
    .auth-error { padding: 0.85rem 1rem; border: var(--hairline) solid var(--rubric); background: var(--rubric-wash); color: var(--ink); font-family: var(--mono); font-size: 0.78rem; letter-spacing: 0.04em; }

    .auth-side { background: var(--paper-deep); border-left: var(--hairline) solid var(--rule-strong); padding: clamp(2rem, 6vw, 5rem) clamp(1.5rem, 5vw, 4rem); display: grid; align-content: center; gap: 1.5rem; position: relative; overflow: hidden; }
    .auth-side .pretitle { font-family: var(--mono); font-size: var(--t-micro); letter-spacing: 0.22em; text-transform: uppercase; color: var(--ink-faint); }
    .auth-side h2 { font-family: var(--serif); font-size: clamp(2rem, 3.5vw, 3rem); font-weight: 500; letter-spacing: -0.015em; line-height: 1.05; margin: 0; }
    .auth-side h2 em { color: var(--rubric); font-style: italic; font-weight: 400; }
    .auth-side p { font-family: var(--serif); font-size: 1.1rem; line-height: 1.55; color: var(--ink-soft); margin: 0; max-width: 32rem; }
    .auth-side .ornament { font-family: var(--serif); color: var(--rubric); letter-spacing: 0.5em; font-size: 1.25rem; }
    .auth-side .marks { display: flex; gap: 1.5rem; padding-top: 1.5rem; margin-top: 0.5rem; border-top: var(--hairline) solid var(--rule-strong); font-family: var(--mono); font-size: var(--t-micro); letter-spacing: 0.14em; text-transform: uppercase; color: var(--ink-faint); }
    .auth-side .marks b { color: var(--ink); font-weight: 500; }
</style>
@endpush

@section('body')

@include('partials.colophon', ['edition' => 'sign in', 'active' => null])

<main class="auth-shell">
    <section class="auth-form-col">
        <div class="auth-form-card">
            <div style="font-family:var(--mono);font-size:var(--t-micro);letter-spacing:0.22em;text-transform:uppercase;color:var(--ink-faint);margin-bottom:0.85rem;">
                Rite of entry
            </div>
            <h1>Sign in to <em>your</em> archive.</h1>
            <p class="sub">Bearer tokens for agents, a password for you.</p>

            @if ($errors->any())
                <div class="auth-error" style="margin-bottom:1rem;">
                    <span class="rubric">● </span>{{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('login') }}" class="auth-form">
                @csrf
                <div>
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus />
                </div>
                <div>
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required />
                </div>
                <label class="check">
                    <input type="checkbox" name="remember" />
                    <span>Remember this terminal</span>
                </label>
                <div>
                    <button type="submit" class="btn" style="width:100%;justify-content:center;">
                        <span>Enter the palace</span><span class="arrow">→</span>
                    </button>
                </div>
            </form>

            <p style="margin-top:2rem;font-family:var(--mono);font-size:var(--t-micro);letter-spacing:0.14em;text-transform:uppercase;color:var(--ink-faint);">
                <a href="{{ route('landing') }}" style="color:var(--ink-faint);border-bottom:1px dotted var(--rule-strong);">← back to the frontispiece</a>
            </p>
        </div>
    </section>

    <aside class="auth-side" aria-hidden="true">
        <div class="pretitle">— Marginalia —</div>
        <h2>One memory, <em>across</em> every agent in your house.</h2>
        <p>
            Mnemon does not authenticate your agents through us. It hands them an API key and trusts
            the bearer. Your password protects only this admin door — the rest of the palace is for
            machines.
        </p>
        <div class="ornament">❦ · ❦ · ❦</div>
        <div class="marks">
            <span><b>MIT</b> · self-hosted</span>
            <span><b>Postgres</b> · pgvector</span>
            <span><b>MCP</b> · stdio + sse</span>
        </div>
    </aside>
</main>

@endsection
