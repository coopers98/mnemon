@extends('layouts.wiki')

@section('title', 'Palace — Mnemon')

@section('content')
    <div class="mx-auto max-w-4xl">
        <h1 class="mb-6 text-2xl font-bold text-white">Palace</h1>

        @if ($wings->isEmpty())
            <div class="rounded-lg border border-slate-700 bg-slate-800 p-8 text-center">
                <p class="text-slate-400">No wings yet. Create wings through the admin panel or MCP tools.</p>
            </div>
        @else
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($wings as $wing)
                    <a href="{{ route('palace.wing', $wing->slug) }}"
                       class="rounded-lg border border-slate-700 bg-slate-800 p-5 transition hover:border-indigo-600 hover:bg-slate-750">
                        <h2 class="text-lg font-semibold text-white">{{ $wing->name }}</h2>
                        @if ($wing->description)
                            <p class="mt-1 text-sm text-slate-400">{{ Str::limit($wing->description, 80) }}</p>
                        @endif
                        <div class="mt-3 flex items-center gap-4 text-xs text-slate-500">
                            <span>{{ $wing->rooms_count }} room{{ $wing->rooms_count !== 1 ? 's' : '' }}</span>
                            <span>{{ $wing->drawers_count }} drawer{{ $wing->drawers_count !== 1 ? 's' : '' }}</span>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
@endsection
