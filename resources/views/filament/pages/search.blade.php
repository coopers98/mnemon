<x-filament-panels::page>
    {{ $this->form }}

    @php
        $results = $this->getResults();
        $query = trim((string) ($this->data['q'] ?? ''));
    @endphp

    <x-filament::section heading="Results ({{ $results->count() }})">
        @forelse ($results as $result)
            <a
                href="{{ $result['url'] }}"
                class="block border-b border-gray-100 py-4 last:border-0 hover:bg-gray-50 dark:border-white/10 dark:hover:bg-white/5"
            >
                <div class="flex items-center justify-between gap-4">
                    <div class="font-medium">
                        <x-filament::badge :color="$result['kind'] === 'drawer' ? 'primary' : 'info'">
                            {{ $result['kind'] === 'drawer' ? 'Drawer' : 'Wiki' }}
                        </x-filament::badge>
                        <span class="ml-2">{{ $result['title'] }}</span>
                    </div>
                    <div class="text-xs text-gray-500">score: {{ number_format($result['score'], 3) }}</div>
                </div>
                <div class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $result['snippet'] }}</div>
                <div class="mt-1 text-xs text-gray-400">{{ $result['location'] }}</div>
            </a>
        @empty
            <p class="text-sm text-gray-500">
                {{ $query === '' ? 'Type a query above to search.' : 'No results.' }}
            </p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
