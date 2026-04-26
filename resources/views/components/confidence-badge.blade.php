@props(['confidence' => 'medium'])

@php
    $colors = [
        'high' => 'bg-green-900/50 text-green-300 border-green-700',
        'medium' => 'bg-yellow-900/50 text-yellow-300 border-yellow-700',
        'low' => 'bg-red-900/50 text-red-300 border-red-700',
    ];
    $colorClass = $colors[$confidence] ?? $colors['medium'];
@endphp

<span class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium {{ $colorClass }}">
    {{ ucfirst($confidence) }}
</span>
