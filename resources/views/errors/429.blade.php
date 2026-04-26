@extends('errors.layout', ['code' => 429, 'title' => 'Too many requests at the door.'])

@section('lede')
    The doorman has counted more requests than the wing's policy allows. Mnemon does not lose work —
    nothing is dropped — but it will keep this one waiting. <em>Slow your hand a little</em> and the
    queue will catch up.
@endsection

@section('marg-1')
    Wait a few seconds and retry. If your agent is repeating itself, check the rate-limit on the API
    key in the admin panel.
@endsection

@section('emblem')
    <svg viewBox="0 0 320 320" fill="none" stroke="currentColor" stroke-width="1">
        <g style="color: var(--ink); stroke-width: 1.5;">
            <rect x="80" y="100" width="40" height="160" />
        </g>
        {{-- a queue of figures --}}
        <g style="color: var(--ink-soft); stroke-width: 1;">
            <circle cx="100" cy="80" r="12" />
            <line x1="100" y1="92" x2="100" y2="100" />
            <circle cx="150" cy="80" r="12" />
            <line x1="150" y1="92" x2="150" y2="100" />
            <circle cx="200" cy="80" r="12" />
            <line x1="200" y1="92" x2="200" y2="100" />
        </g>
        <g style="color: var(--rubric); stroke-width: 1.5;">
            <circle cx="250" cy="80" r="12" />
            <line x1="250" y1="92" x2="250" y2="100" />
        </g>
        {{-- the door --}}
        <g style="color: var(--ink); stroke-width: 1.5;">
            <rect x="50" y="180" width="60" height="100" />
            <line x1="80" y1="180" x2="80" y2="280" />
        </g>
        <g style="color: var(--ink-faint); font-family: 'JetBrains Mono', monospace; font-size: 8px; letter-spacing: 1.4px;" stroke="none" fill="currentColor">
            <text x="160" y="250" text-anchor="middle" style="font-style: italic; font-family: 'EB Garamond'; fill: var(--ink-soft);">— rate-limited —</text>
            <text x="10" y="315" style="font-style: italic; font-family: 'EB Garamond';">Plate viii · errata</text>
            <text x="310" y="315" text-anchor="end">429</text>
        </g>
    </svg>
@endsection

@section('emblem-quote')
    even the fastest scribe needs a breath
@endsection
