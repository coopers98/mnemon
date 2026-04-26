@props(['tier' => 'raw'])

@php
    $colors = [
        'raw' => 'bg-slate-700 text-slate-300 border-slate-600',
        'reviewed' => 'bg-blue-900/50 text-blue-300 border-blue-700',
        'consolidated' => 'bg-purple-900/50 text-purple-300 border-purple-700',
    ];
    $colorClass = $colors[$tier] ?? $colors['raw'];
@endphp

<span class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium {{ $colorClass }}">
    {{ ucfirst($tier) }}
</span>
