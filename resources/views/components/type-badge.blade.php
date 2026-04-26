@props(['type' => 'concept'])

@php
    $colors = [
        'person' => 'bg-emerald-900/50 text-emerald-300 border-emerald-700',
        'project' => 'bg-indigo-900/50 text-indigo-300 border-indigo-700',
        'concept' => 'bg-slate-700 text-slate-300 border-slate-600',
        'decision' => 'bg-amber-900/50 text-amber-300 border-amber-700',
        'synthesis' => 'bg-cyan-900/50 text-cyan-300 border-cyan-700',
    ];
    $colorClass = $colors[$type] ?? $colors['concept'];
@endphp

<span class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium {{ $colorClass }}">
    {{ ucfirst($type) }}
</span>
