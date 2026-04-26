@extends('layouts.wiki')

@section('title', 'Wiki — Mnemon')

@section('sidebar')
    <h3 class="mb-3 text-xs font-semibold uppercase tracking-wider text-slate-500">Pages by Type</h3>
    @foreach ($grouped as $type => $pages)
        <div class="mb-4">
            <h4 class="mb-1 text-xs font-medium text-slate-400">{{ ucfirst($type) }}</h4>
            <ul class="space-y-0.5">
                @foreach ($pages as $page)
                    <li>
                        <a href="{{ route('wiki.show', $page->name) }}"
                           class="block truncate rounded px-2 py-1 text-sm text-slate-300 hover:bg-slate-700 hover:text-white">
                            {{ $page->title }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endforeach
@endsection

@section('content')
    <div class="mx-auto max-w-4xl">
        <h1 class="mb-6 text-2xl font-bold text-white">Wiki</h1>

        {{-- Stats bar --}}
        <div class="mb-8 flex gap-6 rounded-lg border border-slate-700 bg-slate-800 p-4">
            <div>
                <span class="text-2xl font-bold text-indigo-400">{{ $stats['total_pages'] }}</span>
                <span class="ml-1 text-sm text-slate-400">pages</span>
            </div>
            <div>
                <span class="text-2xl font-bold text-indigo-400">{{ $stats['total_drawers'] }}</span>
                <span class="ml-1 text-sm text-slate-400">drawers</span>
            </div>
            @if ($stats['last_compiled'])
                <div>
                    <span class="text-sm text-slate-400">Last compiled</span>
                    <span class="ml-1 text-sm text-slate-300">{{ $stats['last_compiled']->diffForHumans() }}</span>
                </div>
            @endif
        </div>

        {{-- Pages grouped by type --}}
        @foreach ($grouped as $type => $pages)
            <div class="mb-8">
                <h2 class="mb-3 flex items-center gap-2 text-lg font-semibold text-white">
                    <x-type-badge :type="$type" />
                    {{ ucfirst($type) }}s
                    <span class="text-sm font-normal text-slate-500">({{ $pages->count() }})</span>
                </h2>
                <div class="space-y-2">
                    @foreach ($pages as $page)
                        <a href="{{ route('wiki.show', $page->name) }}"
                           class="block rounded-lg border border-slate-700 bg-slate-800 p-4 transition hover:border-indigo-600 hover:bg-slate-750">
                            <div class="flex items-center gap-2">
                                <span class="font-medium text-white">{{ $page->title }}</span>
                                <x-confidence-badge :confidence="$page->confidence ?? 'medium'" />
                                @if ($page->pending_drawers_since_compile > 0)
                                    <span class="rounded bg-amber-900/50 px-1.5 py-0.5 text-xs text-amber-300">{{ $page->pending_drawers_since_compile }} pending</span>
                                @endif
                            </div>
                            @if ($page->description)
                                <p class="mt-1 text-sm text-slate-400">{{ Str::limit($page->description, 120) }}</p>
                            @endif
                            <div class="mt-2 text-xs text-slate-500">
                                @if ($page->last_compiled_at)
                                    Compiled {{ $page->last_compiled_at->diffForHumans() }}
                                @else
                                    Never compiled
                                @endif
                                · {{ $page->word_count }} words
                            </div>
                        </a>
                    @endforeach
                </div>
            </div>
        @endforeach

        @if ($grouped->isEmpty())
            <div class="rounded-lg border border-slate-700 bg-slate-800 p-8 text-center">
                <p class="text-slate-400">No wiki pages yet. Create pages through the admin panel or MCP tools.</p>
            </div>
        @endif
    </div>
@endsection
