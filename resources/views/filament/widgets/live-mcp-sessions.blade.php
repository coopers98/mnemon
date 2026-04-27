<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Recent MCP Activity</x-slot>
        <x-slot name="description">Last 5 OAuth-authenticated MCP tool calls</x-slot>

        @if ($sessions->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">No MCP activity recorded yet.</p>
        @else
            <div class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($sessions as $session)
                    <div class="flex items-center justify-between py-3">
                        <div class="flex items-center gap-3">
                            <x-filament::badge color="primary">
                                {{ $session->tool_name }}
                            </x-filament::badge>
                            @if ($session->source)
                                <span class="text-sm text-gray-500 dark:text-gray-400">
                                    via {{ $session->source }}
                                </span>
                            @endif
                            @if ($session->result_count !== null)
                                <span class="text-xs text-gray-400 dark:text-gray-500">
                                    {{ $session->result_count }} result{{ $session->result_count === 1 ? '' : 's' }}
                                </span>
                            @endif
                        </div>
                        <span class="text-xs text-gray-400 dark:text-gray-500 tabular-nums">
                            {{ $session->created_at->diffForHumans() }}
                        </span>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
