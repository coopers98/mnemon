@extends('layouts.wiki')

@section('title', 'Search — Mnemon Wiki')

@section('content')
    <div class="mx-auto max-w-4xl">
        <h1 class="mb-6 text-2xl font-bold text-white">Search</h1>

        <form action="{{ route('wiki.search') }}" method="GET" class="mb-8">
            <div class="flex gap-2">
                <input type="text" name="q" value="{{ $query }}" placeholder="Search wiki pages and drawers..."
                       autofocus
                       class="flex-1 rounded-md border border-slate-600 bg-slate-700 px-4 py-2 text-slate-200 placeholder-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                <button type="submit"
                        class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                    Search
                </button>
            </div>
        </form>

        @if ($query !== '')
            {{-- Wiki page results --}}
            @if ($wikiResults->isNotEmpty())
                <div class="mb-8">
                    <h2 class="mb-3 text-lg font-semibold text-white">Wiki Pages ({{ $wikiResults->count() }})</h2>
                    <div class="space-y-2">
                        @foreach ($wikiResults as $result)
                            <a href="{{ route('wiki.show', $result->name) }}"
                               class="block rounded-lg border border-slate-700 bg-slate-800 p-4 transition hover:border-indigo-600">
                                <div class="flex items-center gap-2">
                                    <x-type-badge :type="$result->type" />
                                    <span class="font-medium text-white">{{ $result->title }}</span>
                                    <span class="text-xs text-slate-500">{{ round($result->score * 100) }}% match</span>
                                </div>
                                @if ($result->description)
                                    <p class="mt-1 text-sm text-slate-400">{{ Str::limit($result->description, 200) }}</p>
                                @endif
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Drawer results --}}
            @if ($drawerResults->isNotEmpty())
                <div class="mb-8">
                    <h2 class="mb-3 text-lg font-semibold text-white">Palace Drawers ({{ $drawerResults->count() }})</h2>
                    <div class="space-y-2">
                        @foreach ($drawerResults as $result)
                            <a href="{{ route('palace.drawer', $result->id) }}"
                               class="block rounded-lg border border-slate-700 bg-slate-800 p-4 transition hover:border-indigo-600">
                                <div class="flex items-center gap-2">
                                    <x-tier-badge :tier="$result->tier" />
                                    <span class="text-sm text-slate-400">{{ $result->wing }} / {{ $result->room }}</span>
                                    <span class="text-xs text-slate-500">{{ round($result->score * 100) }}% match</span>
                                </div>
                                <p class="mt-1 text-sm text-slate-300">{{ Str::limit($result->content, 200) }}</p>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($wikiResults->isEmpty() && $drawerResults->isEmpty())
                <div class="rounded-lg border border-slate-700 bg-slate-800 p-8 text-center">
                    <p class="text-slate-400">No results found for "{{ $query }}".</p>
                </div>
            @endif
        @endif
    </div>
@endsection
