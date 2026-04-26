@extends('layouts.wiki')

@section('title', $wing->name . ' — Palace — Mnemon')

@section('content')
    <div class="mx-auto max-w-4xl">
        {{-- Breadcrumb --}}
        <nav class="mb-4 text-sm text-slate-400">
            <a href="{{ route('palace.index') }}" class="hover:text-white">Palace</a>
            <span class="mx-1">›</span>
            <span class="text-white">{{ $wing->name }}</span>
        </nav>

        <div class="mb-6 flex items-center justify-between">
            <h1 class="text-2xl font-bold text-white">{{ $wing->name }}</h1>
            @if ($wikiPage)
                <a href="{{ route('wiki.show', $wikiPage->name) }}"
                   class="rounded bg-indigo-600/20 px-3 py-1 text-sm text-indigo-400 hover:bg-indigo-600/30">
                    View Wiki Page →
                </a>
            @endif
        </div>

        @if ($wing->description)
            <p class="mb-6 text-slate-400">{{ $wing->description }}</p>
        @endif

        @if ($rooms->isEmpty())
            <div class="rounded-lg border border-slate-700 bg-slate-800 p-8 text-center">
                <p class="text-slate-400">No rooms in this wing yet.</p>
            </div>
        @else
            <div class="space-y-2">
                @foreach ($rooms as $room)
                    <a href="{{ route('palace.room', [$wing->slug, $room->slug]) }}"
                       class="flex items-center justify-between rounded-lg border border-slate-700 bg-slate-800 p-4 transition hover:border-indigo-600">
                        <span class="font-medium text-white">{{ $room->name }}</span>
                        <span class="text-sm text-slate-500">{{ $room->drawers_count }} drawer{{ $room->drawers_count !== 1 ? 's' : '' }}</span>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
@endsection
