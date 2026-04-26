@extends('layouts.mnemon')

@push('head')
<style>
    body { overflow-x: hidden; }
    .palace-shell { display: grid; grid-template-rows: auto 1fr; min-height: calc(100vh - 5rem); }
    .palace-main { display: grid; grid-template-columns: 16rem minmax(0, 1fr); min-height: 0; gap: 0; }
    @media (max-width: 1180px) { .palace-main { grid-template-columns: 14rem 1fr; } }
    @media (max-width: 820px) { .palace-main { grid-template-columns: 1fr; } .rooms-rail { display: none; } }

    /* Left rail */
    .rooms-rail { border-right: var(--hairline) solid var(--rule-strong); background: var(--paper); overflow-y: auto; display: flex; flex-direction: column; min-height: calc(100vh - 4rem); }
    .rail-head { padding: 1.25rem 1.25rem 0.85rem; border-bottom: var(--hairline) solid var(--rule); }
    .rail-head h2 { font-family: var(--serif); font-size: 1.55rem; letter-spacing: -0.01em; margin-bottom: 0.25rem; font-weight: 500; }
    .rail-head h2 em { color: var(--rubric); font-style: italic; font-weight: 400; }
    .rail-head .sub { font-family: var(--mono); font-size: var(--t-micro); letter-spacing: 0.14em; text-transform: uppercase; color: var(--ink-faint); }

    .rail-section { padding: 1.25rem 1.25rem 0.5rem; font-family: var(--mono); font-size: var(--t-micro); letter-spacing: 0.18em; text-transform: uppercase; color: var(--ink-faint); display: flex; justify-content: space-between; align-items: baseline; }
    .room-list { list-style: none; padding: 0 0.6rem; margin: 0; }
    .room-item { display: grid; grid-template-columns: 1.4rem 1fr auto; gap: 0.5rem; padding: 0.55rem 0.65rem; align-items: center; transition: background 0.15s; color: var(--ink); }
    .room-item:hover { background: var(--paper-deep); }
    .room-item.is-active { background: var(--paper-deep); }
    .room-item.is-active .glyph { background: var(--rubric); border-color: var(--rubric); }
    .room-item.is-active .name { color: var(--rubric); }
    .room-item .glyph { width: 12px; height: 12px; border: 1.25px solid var(--ink); transform: rotate(45deg); }
    .room-item .name { font-family: var(--serif); font-size: 1rem; line-height: 1.15; }
    .room-item .name .meta { display: block; font-family: var(--mono); font-size: 0.65rem; color: var(--ink-faint); letter-spacing: 0.1em; margin-top: 0.1rem; text-transform: uppercase; }
    .room-item .count { font-family: var(--mono); font-size: 0.7rem; color: var(--ink-faint); letter-spacing: 0.05em; }
    .rail-foot { margin-top: auto; padding: 1rem 1.25rem; border-top: var(--hairline) solid var(--rule); font-family: var(--mono); font-size: var(--t-micro); letter-spacing: 0.06em; color: var(--ink-faint); line-height: 1.6; }
    .rail-foot a { color: var(--ink); border-bottom: 1px dotted var(--ink-faint); }
    .rail-foot a:hover { color: var(--rubric); border-color: var(--rubric); }

    /* Stage */
    .stage { position: relative; background: linear-gradient(var(--rule) 1px, transparent 1px) 0 0 / 32px 32px, linear-gradient(90deg, var(--rule) 1px, transparent 1px) 0 0 / 32px 32px, var(--paper-deep); display: flex; flex-direction: column; min-height: calc(100vh - 4rem); }
    .stage-bar { display: flex; align-items: center; justify-content: space-between; padding: 0.75rem 1.25rem; border-bottom: var(--hairline) solid var(--rule); background: color-mix(in oklch, var(--paper) 92%, transparent); backdrop-filter: blur(6px); gap: 1rem; flex-wrap: wrap; }
    .stage-bar .breadcrumb { display: flex; align-items: baseline; gap: 0.6rem; font-family: var(--mono); font-size: var(--t-micro); letter-spacing: 0.14em; text-transform: uppercase; color: var(--ink-faint); flex-wrap: wrap; }
    .stage-bar .breadcrumb a { color: var(--ink-faint); border-bottom: 1px dotted var(--rule-strong); padding-bottom: 1px; }
    .stage-bar .breadcrumb a:hover { color: var(--rubric); border-color: var(--rubric); }
    .stage-bar .breadcrumb .here { font-family: var(--serif); text-transform: none; letter-spacing: 0; font-size: 1.05rem; color: var(--ink); font-style: italic; }
    .stage-body { flex: 1; padding: clamp(1.5rem, 3vw, 2.5rem); overflow-y: auto; }
    .stage-foot { display: flex; justify-content: space-between; align-items: center; padding: 0.6rem 1.25rem; border-top: var(--hairline) solid var(--rule); background: color-mix(in oklch, var(--paper) 92%, transparent); font-family: var(--mono); font-size: var(--t-micro); letter-spacing: 0.14em; text-transform: uppercase; color: var(--ink-faint); gap: 1rem; flex-wrap: wrap; }
    .stage-foot .group { display: flex; gap: 1.5rem; }
    .stage-foot b { color: var(--ink); font-weight: 500; }

    /* Locus pin */
    .locus { display: inline-flex; align-items: center; gap: 0.45rem; padding: 0.35rem 0.55rem; background: var(--paper); border: 1px solid var(--ink); font-family: var(--mono); font-size: var(--t-micro); letter-spacing: 0.06em; color: var(--ink); cursor: pointer; transition: transform 0.15s, color 0.15s, background 0.15s, border-color 0.15s; white-space: nowrap; max-width: 18rem; text-decoration: none; }
    .locus::before { content: ""; width: 6px; height: 6px; background: var(--rubric); transform: rotate(45deg); flex-shrink: 0; }
    .locus:hover { background: var(--ink); color: var(--paper); border-color: var(--ink); transform: scale(1.04); }

    .empty { padding: 3rem 2rem; border: var(--hairline) solid var(--rule); background: var(--paper); text-align: center; color: var(--ink-faint); font-family: var(--serif); font-style: italic; font-size: 1.1rem; }
</style>
@stack('palace-head')
@endpush

@section('body')

@include('partials.colophon', ['edition' => $edition ?? 'palace · ground floor', 'active' => 'palace'])

<div class="palace-shell">
    <div class="palace-main">
        <aside class="rooms-rail" aria-label="Rooms">
            @yield('rail')
        </aside>

        <section class="stage" aria-label="Stage">
            <div class="stage-bar">
                @yield('stage-bar')
            </div>
            <div class="stage-body">
                @yield('stage')
            </div>
            <div class="stage-foot">
                @yield('stage-foot')
            </div>
        </section>
    </div>
</div>

@endsection
