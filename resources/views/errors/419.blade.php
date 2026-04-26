@extends('errors.layout', ['code' => 419, 'title' => 'The session has gone cold.'])

@section('lede')
    Your CSRF token expired before this dispatch arrived. Time, in Mnemon as in every careful office,
    is part of the audit. <em>Sign in again</em> and the page will accept your form on its second
    attempt.
@endsection

@section('marg-1')
    Hit back, refresh, and resubmit. If the form held something important, copy it before reloading;
    the fresh page will be empty.
@endsection

@section('emblem')
    <svg viewBox="0 0 320 320" fill="none" stroke="currentColor" stroke-width="1">
        {{-- hourglass --}}
        <g style="color: var(--ink); stroke-width: 1.5;">
            <line x1="80" y1="40" x2="240" y2="40" />
            <line x1="80" y1="280" x2="240" y2="280" />
            <path d="M 100 40 L 220 40 L 165 160 L 220 280 L 100 280 L 155 160 Z" />
        </g>
        {{-- sand fallen --}}
        <g style="color: var(--rubric);">
            <path d="M 110 270 L 210 270 L 175 200 L 145 200 Z" fill="currentColor" stroke="none" />
        </g>
        {{-- empty top --}}
        <g style="color: var(--ink-faint); stroke-width: 0.5;" stroke-dasharray="2 4">
            <line x1="105" y1="55" x2="215" y2="55" />
            <line x1="108" y1="68" x2="212" y2="68" />
            <line x1="112" y1="81" x2="208" y2="81" />
        </g>
        {{-- frame --}}
        <g style="color: var(--ink);">
            <rect x="40" y="20" width="240" height="280" stroke-width="0.75" fill="none" />
        </g>
        <g style="color: var(--ink-faint); font-family: 'JetBrains Mono', monospace; font-size: 8px; letter-spacing: 1.4px;" stroke="none" fill="currentColor">
            <text x="160" y="158" text-anchor="middle" style="font-style: italic; font-family: 'EB Garamond'; fill: var(--ink-soft);">elapsed</text>
            <text x="10" y="315" style="font-style: italic; font-family: 'EB Garamond';">Plate vi · errata</text>
            <text x="310" y="315" text-anchor="end">419</text>
        </g>
    </svg>
@endsection

@section('emblem-quote')
    even sealed wax has a half-life
@endsection
