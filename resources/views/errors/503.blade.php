@extends('errors.layout', ['code' => 503, 'title' => 'The palace is being re-shelved.'])

@section('lede')
    Mnemon is in a maintenance window — a brief one, almost certainly. The archive is intact, the
    drawers are sealed, and the wiki will be back where you left it shortly. <em>Try again in a
    moment.</em>
@endsection

@section('marg-1')
    Refresh in a few seconds. If the maintenance is taking longer than expected, the operator will
    have left a note in the application status.
@endsection

@section('emblem')
    <svg viewBox="0 0 320 320" fill="none" stroke="currentColor" stroke-width="1">
        {{-- shelves --}}
        <g style="color: var(--ink); stroke-width: 1.25;">
            <rect x="40" y="40" width="240" height="240" />
            <line x1="40" y1="120" x2="280" y2="120" />
            <line x1="40" y1="200" x2="280" y2="200" />
        </g>
        {{-- books on top shelf --}}
        <g style="color: var(--ink); stroke-width: 1;">
            <rect x="55" y="55" width="14" height="55" />
            <rect x="74" y="65" width="14" height="45" />
            <rect x="93" y="58" width="14" height="52" />
        </g>
        {{-- middle shelf disrupted --}}
        <g style="color: var(--rubric); stroke-width: 1.25;">
            <rect x="55" y="135" width="14" height="55" transform="rotate(-8 62 162)" />
            <rect x="78" y="155" width="40" height="14" />
            <rect x="125" y="138" width="14" height="52" transform="rotate(5 132 164)" />
        </g>
        {{-- bottom shelf --}}
        <g style="color: var(--ink-faint); stroke-width: 1;">
            <rect x="55" y="215" width="14" height="55" />
            <rect x="74" y="222" width="14" height="48" />
            <rect x="93" y="218" width="14" height="52" />
            <rect x="112" y="225" width="14" height="45" />
        </g>
        {{-- ladder --}}
        <g style="color: var(--ink-faint); stroke-width: 1;">
            <line x1="200" y1="40" x2="200" y2="280" />
            <line x1="220" y1="40" x2="220" y2="280" />
            <line x1="200" y1="80" x2="220" y2="80" />
            <line x1="200" y1="140" x2="220" y2="140" />
            <line x1="200" y1="200" x2="220" y2="200" />
            <line x1="200" y1="260" x2="220" y2="260" />
        </g>
        <g style="color: var(--ink-faint); font-family: 'JetBrains Mono', monospace; font-size: 8px; letter-spacing: 1.4px;" stroke="none" fill="currentColor">
            <text x="10" y="315" style="font-style: italic; font-family: 'EB Garamond';">Plate vii · errata</text>
            <text x="310" y="315" text-anchor="end">503</text>
        </g>
    </svg>
@endsection

@section('emblem-quote')
    every archive has its rest day
@endsection
