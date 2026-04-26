<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Mnemon — The memory your AI deserves</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-full bg-slate-900 text-slate-200 antialiased">
    {{-- Nav --}}
    <header class="border-b border-slate-800">
        <div class="mx-auto flex max-w-5xl items-center justify-between px-6 py-4">
            <span class="text-xl font-bold text-indigo-400">Mnemon</span>
            <a href="{{ route('login') }}"
               class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                Sign in
            </a>
        </div>
    </header>

    {{-- Hero --}}
    <section class="mx-auto max-w-5xl px-6 py-24 text-center">
        <h1 class="mb-4 text-5xl font-bold tracking-tight text-white">
            The memory your AI deserves
        </h1>
        <p class="mx-auto mb-10 max-w-2xl text-lg text-slate-400">
            Mnemon is a self-hosted second brain that gives AI agents persistent, structured memory.
            Store verbatim content, compile knowledge, and expose it all via MCP tools.
        </p>
        <a href="{{ route('login') }}"
           class="inline-block rounded-md bg-indigo-600 px-6 py-3 text-base font-medium text-white hover:bg-indigo-500">
            Get started →
        </a>
    </section>

    {{-- Features --}}
    <section class="border-t border-slate-800 bg-slate-800/30">
        <div class="mx-auto grid max-w-5xl gap-8 px-6 py-16 md:grid-cols-3">
            <div class="rounded-lg border border-slate-700 bg-slate-800 p-6">
                <div class="mb-3 text-2xl">🏛️</div>
                <h2 class="mb-2 text-lg font-semibold text-white">The Palace</h2>
                <p class="text-sm text-slate-400">
                    Append-only verbatim storage organized into wings, rooms, and drawers.
                    Every piece of content preserved exactly as received, with source attribution and metadata.
                </p>
            </div>
            <div class="rounded-lg border border-slate-700 bg-slate-800 p-6">
                <div class="mb-3 text-2xl">📖</div>
                <h2 class="mb-2 text-lg font-semibold text-white">The Wiki</h2>
                <p class="text-sm text-slate-400">
                    Compiled, synthesized knowledge pages with wikilinks, confidence scores, and revision history.
                    Raw content distilled into structured articles.
                </p>
            </div>
            <div class="rounded-lg border border-slate-700 bg-slate-800 p-6">
                <div class="mb-3 text-2xl">🔌</div>
                <h2 class="mb-2 text-lg font-semibold text-white">MCP Tools</h2>
                <p class="text-sm text-slate-400">
                    12 Model Context Protocol tools let AI agents read, write, search, and compile
                    your knowledge base programmatically. API key scoped access.
                </p>
            </div>
        </div>
    </section>

    {{-- Stats --}}
    <section class="mx-auto max-w-5xl px-6 py-12 text-center">
        <div class="inline-flex gap-12">
            <div>
                <div class="text-3xl font-bold text-indigo-400">{{ number_format($stats['drawers']) }}</div>
                <div class="text-sm text-slate-500">Drawers</div>
            </div>
            <div>
                <div class="text-3xl font-bold text-indigo-400">{{ number_format($stats['wiki_pages']) }}</div>
                <div class="text-sm text-slate-500">Wiki Pages</div>
            </div>
            <div>
                <div class="text-3xl font-bold text-indigo-400">{{ number_format($stats['wings']) }}</div>
                <div class="text-sm text-slate-500">Wings</div>
            </div>
        </div>
    </section>

    {{-- Contact form --}}
    <section class="border-t border-slate-800">
        <div class="mx-auto max-w-lg px-6 py-16">
            <h2 class="mb-6 text-center text-2xl font-bold text-white">Get in touch</h2>

            @if (session('contact_success'))
                <div class="mb-6 rounded-lg border border-green-700 bg-green-900/50 p-4 text-center text-sm text-green-300">
                    {{ session('contact_success') }}
                </div>
            @endif

            <form method="POST" action="{{ route('contact.store') }}">
                @csrf
                <div class="mb-4">
                    <label for="name" class="mb-1 block text-sm text-slate-400">Name</label>
                    <input type="text" id="name" name="name" value="{{ old('name') }}" required
                           class="w-full rounded-md border border-slate-600 bg-slate-700 px-3 py-2 text-sm text-slate-200 placeholder-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                    @error('name')
                        <p class="mt-1 text-xs text-red-400">{{ $message }}</p>
                    @enderror
                </div>
                <div class="mb-4">
                    <label for="contact_email" class="mb-1 block text-sm text-slate-400">Email</label>
                    <input type="email" id="contact_email" name="email" value="{{ old('email') }}" required
                           class="w-full rounded-md border border-slate-600 bg-slate-700 px-3 py-2 text-sm text-slate-200 placeholder-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                    @error('email')
                        <p class="mt-1 text-xs text-red-400">{{ $message }}</p>
                    @enderror
                </div>
                <div class="mb-4">
                    <label for="message" class="mb-1 block text-sm text-slate-400">Message</label>
                    <textarea id="message" name="message" rows="4" required
                              class="w-full rounded-md border border-slate-600 bg-slate-700 px-3 py-2 text-sm text-slate-200 placeholder-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">{{ old('message') }}</textarea>
                    @error('message')
                        <p class="mt-1 text-xs text-red-400">{{ $message }}</p>
                    @enderror
                </div>
                <button type="submit"
                        class="w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                    Send message
                </button>
            </form>
        </div>
    </section>

    {{-- Footer --}}
    <footer class="border-t border-slate-800 py-8 text-center text-xs text-slate-600">
        Built with Laravel · Powered by Mnemon
    </footer>
</body>
</html>
