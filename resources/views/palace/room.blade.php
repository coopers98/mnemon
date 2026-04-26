@extends('layouts.wiki')

@section('title', $room->name . ' — ' . $wing->name . ' — Palace — Mnemon')

@section('content')
    <div class="mx-auto max-w-4xl">
        {{-- Breadcrumb --}}
        <nav class="mb-4 text-sm text-slate-400">
            <a href="{{ route('palace.index') }}" class="hover:text-white">Palace</a>
            <span class="mx-1">›</span>
            <a href="{{ route('palace.wing', $wing->slug) }}" class="hover:text-white">{{ $wing->name }}</a>
            <span class="mx-1">›</span>
            <span class="text-white">{{ $room->name }}</span>
        </nav>

        <h1 class="mb-6 text-2xl font-bold text-white">{{ $room->name }}</h1>

        @if ($drawers->isEmpty())
            <div class="rounded-lg border border-slate-700 bg-slate-800 p-8 text-center">
                <p class="text-slate-400">No drawers in this room yet.</p>
            </div>
        @else
            <div class="space-y-3">
                @foreach ($drawers as $drawer)
                    <a href="{{ route('palace.drawer', $drawer) }}"
                       class="block rounded-lg border border-slate-700 bg-slate-800 p-4 transition hover:border-indigo-600">
                        <div class="mb-2 flex items-center gap-2">
                            <x-tier-badge :tier="$drawer->tier" />
                            @if ($drawer->source)
                                <span class="text-xs text-slate-500">{{ $drawer->source }}</span>
                            @endif
                            <span class="ml-auto text-xs text-slate-500">{{ $drawer->created_at->format('M j, Y') }}</span>
                        </div>
                        <p class="text-sm text-slate-300">{{ Str::limit($drawer->content, 200) }}</p>
                    </a>
                @endforeach
            </div>

            <div class="mt-6">
                {{ $drawers->links() }}
            </div>
        @endif
    </div>
@endsection
