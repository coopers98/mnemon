<!DOCTYPE html>
<html lang="en" class="antialiased">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <title>Mnemon — A self-hosted second brain</title>
    <meta name="description" content="A self-hosted second brain for AI-augmented work. Verbatim storage, a synthesised wiki, exposed to any agent that speaks the Model Context Protocol.">
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-stone-50 text-zinc-900 dark:bg-zinc-950 dark:text-zinc-100">
    <main class="mx-auto flex min-h-screen max-w-3xl flex-col px-6 py-16 sm:py-24">
        {{-- Hero --}}
        <section class="flex flex-col gap-6">
            <h1 class="font-serif text-6xl font-bold tracking-tight sm:text-7xl">
                Mnemon
            </h1>

            <p class="text-xl font-medium text-zinc-700 dark:text-zinc-300 sm:text-2xl">
                A self-hosted second brain for AI-augmented work.
            </p>

            <p class="max-w-2xl text-base leading-relaxed text-zinc-600 dark:text-zinc-400 sm:text-lg">
                Verbatim storage at the bottom, a synthesised wiki on top, exposed to any agent that
                speaks the Model Context Protocol. One memory across Claude, Cursor, your scripts,
                and whatever else you connect.
            </p>
        </section>

        {{-- Architecture cards --}}
        <section class="mt-14 grid gap-5 sm:grid-cols-2">
            {{-- Palace card --}}
            <article class="rounded-lg border border-zinc-200 bg-white p-6 transition-colors hover:border-amber-400 dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-amber-400">
                <div class="flex items-center gap-3">
                    {{-- Heroicons: building-library --}}
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-6 w-6 text-amber-500" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 21v-8.25M15.75 21v-8.25M8.25 21v-8.25M3 9l9-6 9 6m-1.5 12V10.332A48.36 48.36 0 0 0 12 9.75c-2.551 0-5.056.2-7.5.582V21M3 21h18M12 6.75h.008v.008H12V6.75Z" />
                    </svg>
                    <h2 class="text-lg font-semibold">The palace</h2>
                </div>
                <p class="mt-3 text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">
                    Wings &rarr; rooms &rarr; drawers. Append-only, verbatim. Hybrid retrieval combines
                    semantic similarity, full-text search, and recency.
                </p>
            </article>

            {{-- Wiki card --}}
            <article class="rounded-lg border border-zinc-200 bg-white p-6 transition-colors hover:border-amber-400 dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-amber-400">
                <div class="flex items-center gap-3">
                    {{-- Heroicons: book-open --}}
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-6 w-6 text-amber-500" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" />
                    </svg>
                    <h2 class="text-lg font-semibold">The wiki</h2>
                </div>
                <p class="mt-3 text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">
                    Compiled, structured pages distilled from palace content. Markdown-rendered,
                    type-aware (person, project, concept, decision, synthesis), and tracked for staleness.
                </p>
            </article>
        </section>

        {{-- Primary CTA --}}
        <section class="mt-14">
            <a href="/admin"
               class="inline-flex items-center gap-2 rounded-lg bg-amber-600 px-6 py-3 text-base font-semibold text-white shadow-sm transition-colors hover:bg-amber-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-600">
                Open admin
                <span aria-hidden="true">&rarr;</span>
            </a>
        </section>

        {{-- Footer --}}
        <footer class="mt-auto pt-20">
            <div class="flex flex-col gap-4 border-t border-zinc-200 pt-6 text-sm text-zinc-500 dark:border-zinc-800 dark:text-zinc-400 sm:flex-row sm:items-center sm:justify-between">
                <nav class="flex flex-wrap gap-x-5 gap-y-2">
                    <a href="https://github.com/coopers98/mnemon"
                       class="transition-colors hover:text-amber-700 dark:hover:text-amber-400"
                       rel="noopener noreferrer"
                       target="_blank">GitHub</a>
                    <a href="https://github.com/coopers98/mnemon/blob/main/docs/FRD.md"
                       class="transition-colors hover:text-amber-700 dark:hover:text-amber-400"
                       rel="noopener noreferrer"
                       target="_blank">Read the FRD</a>
                    <a href="https://modelcontextprotocol.io/"
                       class="transition-colors hover:text-amber-700 dark:hover:text-amber-400"
                       rel="noopener noreferrer"
                       target="_blank">MCP protocol</a>
                </nav>
                <p class="text-xs text-zinc-500 dark:text-zinc-500">
                    Built on Laravel 13 + Filament v5. MIT-licensed.
                </p>
            </div>
        </footer>
    </main>
</body>
</html>
