@extends('errors.layout', ['code' => 404, 'title' => 'A locus is missing.'])

@section('lede')
    The room you tried to enter has no <em>drawer</em> by that name. The palace is faithful — if it is not
    here, it was never sealed. Try a different door, or ask the wiki to recall the entry by some other
    handle.
@endsection

@section('marg-1')
    Re-check the URL, or use <a href="{{ route('wiki.search') }}">⌕ recall</a> to find the entry by
    fuzzy match. The hybrid retriever often forgives a typo.
@endsection

@section('emblem')
    <svg viewBox="0 0 320 320" fill="none" stroke="currentColor" stroke-width="1">
        {{-- floor plan with one missing room --}}
        <g style="color: var(--ink); stroke-width: 1.25;">
            <rect x="20" y="20" width="280" height="280" />
            <line x1="160" y1="20" x2="160" y2="160" />
            <line x1="160" y1="200" x2="160" y2="300" />
            <line x1="20" y1="160" x2="120" y2="160" />
            <line x1="200" y1="160" x2="300" y2="160" />
        </g>
        {{-- hatch the missing room --}}
        <g style="color: var(--ink-ghost); stroke-width: 0.5;">
            @for ($i = 0; $i < 12; $i++)
                <line x1="20" y1="{{ 30 + $i * 12 }}" x2="160" y2="{{ 30 + $i * 12 }}" />
            @endfor
        </g>
        {{-- vermilion question --}}
        <g style="color: var(--rubric); font-family: 'EB Garamond', serif; font-style: italic;" stroke="none" fill="currentColor">
            <text x="90" y="105" text-anchor="middle" font-size="60">?</text>
        </g>
        <g style="color: var(--ink-faint); font-family: 'JetBrains Mono', monospace; font-size: 8px; letter-spacing: 1.4px;" stroke="none" fill="currentColor">
            <text x="30" y="35">ROOM · I</text>
            <text x="170" y="35">ROOM · II</text>
            <text x="170" y="180">ROOM · III</text>
            <text x="30" y="180" style="fill: var(--rubric);">— missing —</text>
            <text x="10" y="315" style="font-style: italic; font-family: 'EB Garamond';">Plate iv · errata</text>
            <text x="310" y="315" text-anchor="end">404</text>
        </g>
    </svg>
@endsection

@section('emblem-quote')
    not every door opens onto a room
@endsection
