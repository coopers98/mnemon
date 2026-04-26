@extends('layouts.wiki')

@section('title', $page->title . ' History — Mnemon Wiki')

@section('content')
    <div class="mx-auto max-w-4xl">
        {{-- Breadcrumb --}}
        <nav class="mb-4 text-sm text-slate-400">
            <a href="{{ route('wiki.index') }}" class="hover:text-white">Wiki</a>
            <span class="mx-1">›</span>
            <a href="{{ route('wiki.show', $page->name) }}" class="hover:text-white">{{ $page->title }}</a>
            <span class="mx-1">›</span>
            <span class="text-white">History</span>
        </nav>

        <h1 class="mb-6 text-2xl font-bold text-white">{{ $page->title }} — Revision History</h1>

        @if ($revisions->isEmpty())
            <div class="rounded-lg border border-slate-700 bg-slate-800 p-8 text-center">
                <p class="text-slate-400">No revisions recorded yet.</p>
            </div>
        @else
            <div class="space-y-3">
                @foreach ($revisions as $revision)
                    <div class="rounded-lg border border-slate-700 bg-slate-800 p-4">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <span class="rounded bg-slate-700 px-2 py-0.5 text-xs font-mono text-slate-300">
                                    Rev {{ $revision->revision }}
                                </span>
                                <span class="text-sm text-slate-300">
                                    {{ $revision->written_at?->format('M j, Y g:i A') ?? $revision->created_at->format('M j, Y g:i A') }}
                                </span>
                            </div>
                            @if ($revision->agent_id)
                                <span class="text-xs text-slate-500">Agent: {{ $revision->agent_id }}</span>
                            @endif
                        </div>
                        @if ($revision->content_hash)
                            <div class="mt-2 text-xs font-mono text-slate-500">
                                {{ Str::limit($revision->content_hash, 16) }}
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>

            <div class="mt-6">
                {{ $revisions->links() }}
            </div>
        @endif
    </div>
@endsection
