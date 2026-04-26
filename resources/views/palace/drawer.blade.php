@extends('layouts.wiki')

@section('title', 'Drawer — Palace — Mnemon')

@section('content')
    <div class="mx-auto max-w-4xl">
        {{-- Breadcrumb --}}
        <nav class="mb-4 text-sm text-slate-400">
            <a href="{{ route('palace.index') }}" class="hover:text-white">Palace</a>
            <span class="mx-1">›</span>
            <a href="{{ route('palace.wing', $drawer->room->wing->slug) }}" class="hover:text-white">{{ $drawer->room->wing->name }}</a>
            <span class="mx-1">›</span>
            <a href="{{ route('palace.room', [$drawer->room->wing->slug, $drawer->room->slug]) }}" class="hover:text-white">{{ $drawer->room->name }}</a>
            <span class="mx-1">›</span>
            <span class="text-white">Drawer #{{ $drawer->id }}</span>
        </nav>

        <h1 class="mb-6 text-2xl font-bold text-white">Drawer #{{ $drawer->id }}</h1>

        {{-- Metadata --}}
        <div class="mb-6 flex flex-wrap items-center gap-3">
            <x-tier-badge :tier="$drawer->tier" />
            @if ($drawer->source)
                <span class="text-sm text-slate-400">Source: {{ $drawer->source }}</span>
            @endif
            <span class="text-sm text-slate-500">Created {{ $drawer->created_at->format('M j, Y g:i A') }}</span>
            @if ($drawer->retention_score !== null)
                <span class="text-xs text-slate-500">Retention: {{ round($drawer->retention_score * 100) }}%</span>
            @endif
        </div>

        {{-- Content --}}
        <div class="rounded-lg border border-slate-700 bg-slate-800 p-6">
            <div class="prose prose-invert max-w-none whitespace-pre-wrap text-slate-200">{{ $drawer->content }}</div>
        </div>

        {{-- Metadata JSON --}}
        @if ($drawer->metadata)
            <div class="mt-4">
                <h2 class="mb-2 text-sm font-semibold text-slate-400">Metadata</h2>
                <pre class="rounded-lg border border-slate-700 bg-slate-800 p-4 text-xs text-slate-300 overflow-x-auto">{{ json_encode($drawer->metadata, JSON_PRETTY_PRINT) }}</pre>
            </div>
        @endif

        {{-- Referenced by wiki pages --}}
        @if ($referencedBy->isNotEmpty())
            <div class="mt-6">
                <h2 class="mb-3 text-sm font-semibold text-slate-400">Referenced by Wiki Pages</h2>
                <div class="space-y-2">
                    @foreach ($referencedBy as $page)
                        <a href="{{ route('wiki.show', $page->name) }}"
                           class="flex items-center gap-2 rounded-lg border border-slate-700 bg-slate-800 p-3 transition hover:border-indigo-600">
                            <x-type-badge :type="$page->type" />
                            <span class="text-sm text-white">{{ $page->title }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
@endsection
