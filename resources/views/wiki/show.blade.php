@extends('layouts.wiki')

@section('title', $page->title . ' — Mnemon Wiki')

@section('sidebar')
    {{-- Page metadata --}}
    <div class="mb-6">
        <h3 class="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-500">Page Info</h3>
        <div class="space-y-2 text-sm">
            <div>
                <x-type-badge :type="$page->type" />
            </div>
            <div>
                <x-confidence-badge :confidence="$page->confidence ?? 'medium'" />
            </div>
            @if ($page->last_compiled_at)
                <div class="text-slate-400">
                    Compiled {{ $page->last_compiled_at->diffForHumans() }}
                </div>
            @endif
            <div class="text-slate-400">
                {{ $page->revision_count ?? 0 }} revision{{ ($page->revision_count ?? 0) !== 1 ? 's' : '' }}
                · <a href="{{ route('wiki.history', $page->name) }}" class="text-indigo-400 hover:text-indigo-300">History</a>
            </div>
            @if ($page->pending_drawers_since_compile > 0)
                <div class="rounded border border-amber-700 bg-amber-900/50 p-2 text-xs text-amber-300">
                    {{ $page->pending_drawers_since_compile }} pending update{{ $page->pending_drawers_since_compile !== 1 ? 's' : '' }}
                </div>
            @endif
            <div>
                <a href="/admin" class="text-xs text-slate-500 hover:text-slate-300">Edit in Admin →</a>
            </div>
        </div>
    </div>

    {{-- Related pages --}}
    @if ($relatedPages->isNotEmpty())
        <div class="mb-6">
            <h3 class="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-500">Related Pages</h3>
            <ul class="space-y-1">
                @foreach ($relatedPages as $related)
                    <li>
                        <a href="{{ route('wiki.show', $related->name) }}"
                           class="block truncate rounded px-2 py-1 text-sm text-slate-300 hover:bg-slate-700 hover:text-white">
                            {{ $related->title }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Source drawers --}}
    @if ($sourceDrawers->isNotEmpty())
        <div>
            <h3 class="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-500">
                Source Drawers ({{ $sourceDrawers->count() }})
            </h3>
            <ul class="space-y-2">
                @foreach ($sourceDrawers->take(10) as $drawer)
                    <li class="rounded border border-slate-700 bg-slate-750 p-2">
                        <a href="{{ route('palace.drawer', $drawer) }}" class="block text-xs text-indigo-400 hover:text-indigo-300">
                            {{ $drawer->room?->wing?->name }} / {{ $drawer->room?->name }}
                        </a>
                        <p class="mt-1 text-xs text-slate-400">{{ Str::limit($drawer->content, 100) }}</p>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
@endsection

@section('content')
    <div class="mx-auto max-w-4xl">
        {{-- Breadcrumb --}}
        <nav class="mb-4 text-sm text-slate-400">
            <a href="{{ route('wiki.index') }}" class="hover:text-white">Wiki</a>
            <span class="mx-1">›</span>
            <span class="text-slate-500">{{ ucfirst($page->type) }}</span>
            <span class="mx-1">›</span>
            <span class="text-white">{{ $page->title }}</span>
        </nav>

        {{-- Title --}}
        <div class="mb-6 flex items-center gap-3">
            <h1 class="text-3xl font-bold text-white">{{ $page->title }}</h1>
            <x-confidence-badge :confidence="$page->confidence ?? 'medium'" />
        </div>

        {{-- Rendered content --}}
        <article class="wiki-content prose prose-invert max-w-none prose-headings:text-slate-100 prose-a:text-indigo-400 prose-a:no-underline hover:prose-a:text-indigo-300 prose-code:text-violet-300 prose-pre:bg-slate-800 prose-pre:border prose-pre:border-slate-700">
            {!! $renderedContent !!}
        </article>
    </div>
@endsection
