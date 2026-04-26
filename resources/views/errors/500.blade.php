@extends('errors.layout', ['code' => 500, 'title' => 'The compile worker faltered.'])

@section('lede')
    Something on this side of the wall threw. The archive is unharmed — the base layer never lies — but
    the page that should have rendered did not. The audit log has the stack trace; <em>your data is
    untouched</em>.
@endsection

@section('marg-1')
    Try the page again. If it persists, check the application log on the host. Mnemon never silently
    discards a request — the failure is recorded.
@endsection

@section('emblem')
    <svg viewBox="0 0 320 320" fill="none" stroke="currentColor" stroke-width="1">
        {{-- broken column --}}
        <g style="color: var(--ink); stroke-width: 1.5;">
            <rect x="60" y="40" width="40" height="100" />
            <rect x="60" y="180" width="40" height="100" />
            <rect x="220" y="40" width="40" height="240" />
            <line x1="40" y1="280" x2="280" y2="280" />
            <line x1="40" y1="40" x2="120" y2="40" />
            <line x1="200" y1="40" x2="280" y2="40" />
        </g>
        {{-- jagged break in left column --}}
        <g style="color: var(--rubric); stroke-width: 1.5;">
            <path d="M 60 140 L 75 155 L 65 165 L 85 175 L 70 180 M 100 140 L 90 155 L 100 165 L 80 175 L 100 180" />
        </g>
        {{-- dust/debris --}}
        <g style="color: var(--ink-faint); stroke-width: 0.5;" stroke-dasharray="2 3">
            <line x1="55" y1="160" x2="105" y2="160" />
            <line x1="50" y1="167" x2="110" y2="167" />
        </g>
        <g style="color: var(--ink-faint); font-family: 'JetBrains Mono', monospace; font-size: 8px; letter-spacing: 1.4px;" stroke="none" fill="currentColor">
            <text x="40" y="298">EXCEPTION · UNCAUGHT</text>
            <text x="280" y="298" text-anchor="end">500</text>
            <text x="80" y="35" text-anchor="middle" style="font-style: italic; font-family: 'EB Garamond';">column iv</text>
            <text x="240" y="35" text-anchor="middle" style="font-style: italic; font-family: 'EB Garamond';">intact</text>
        </g>
    </svg>
@endsection

@section('emblem-quote')
    even a well-laid stone can crack
@endsection
