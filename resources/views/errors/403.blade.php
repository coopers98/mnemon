@extends('errors.layout', ['code' => 403, 'title' => 'The seal is not yours to break.'])

@section('lede')
    This room is sealed against your bearer. Every drawer is reachable to <em>someone</em>; this one is
    not reachable to you. Either the credentials are wrong, or the wing's scope does not include your
    name.
@endsection

@section('marg-1')
    Check that you are <a href="{{ route('login') }}">signed in</a> as the right user, and that your
    API key (if any) carries the <em>correct scope</em> for this resource.
@endsection

@section('emblem')
    <svg viewBox="0 0 320 320" fill="none" stroke="currentColor" stroke-width="1">
        {{-- locked door --}}
        <g style="color: var(--ink); stroke-width: 1.5;">
            <rect x="80" y="40" width="160" height="240" />
            <line x1="80" y1="60" x2="240" y2="60" />
            <rect x="100" y="80" width="120" height="180" />
            <circle cx="200" cy="170" r="3" fill="currentColor" />
        </g>
        {{-- vermilion seal/wax stamp --}}
        <g transform="translate(160 170)">
            <circle r="42" style="fill: var(--rubric); stroke: var(--rubric-deep); stroke-width: 1.5;" />
            <circle r="34" style="fill: none; stroke: var(--paper); stroke-width: 0.75;" />
            <text x="0" y="-6" text-anchor="middle" style="fill: var(--paper); font-family: 'EB Garamond', serif; font-style: italic; font-size: 18px;">sealed</text>
            <text x="0" y="14" text-anchor="middle" style="fill: var(--paper); font-family: 'JetBrains Mono', monospace; font-size: 7px; letter-spacing: 1.4px;">FORBIDDEN</text>
        </g>
        {{-- hatching --}}
        <g style="color: var(--ink-ghost); stroke-width: 0.5;" stroke-dasharray="2 4">
            <line x1="40" y1="40" x2="80" y2="40" />
            <line x1="240" y1="40" x2="280" y2="40" />
            <line x1="40" y1="280" x2="80" y2="280" />
            <line x1="240" y1="280" x2="280" y2="280" />
        </g>
        <g style="color: var(--ink-faint); font-family: 'JetBrains Mono', monospace; font-size: 8px; letter-spacing: 1.4px;" stroke="none" fill="currentColor">
            <text x="10" y="315" style="font-style: italic; font-family: 'EB Garamond';">Plate v · errata</text>
            <text x="310" y="315" text-anchor="end">403</text>
        </g>
    </svg>
@endsection

@section('emblem-quote')
    a closed door is not the same as a missing one
@endsection
