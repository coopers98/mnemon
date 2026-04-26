@extends('layouts.mnemon')

@section('title', ($code ?? '???') . ' — ' . ($title ?? 'A page is missing') . ' — Mnemon')

@push('head')
<style>
    .err-shell { min-height: calc(100vh - 4rem); display: grid; grid-template-rows: auto 1fr auto; }
    .err-frontispiece { padding: clamp(2rem, 6vw, 5rem) 0 clamp(1rem, 3vw, 2rem); border-bottom: var(--hairline) solid var(--rule-strong); }
    .err-grid { display: grid; grid-template-columns: 1fr 1fr; gap: clamp(2rem, 5vw, 5rem); align-items: end; }
    @media (max-width: 880px) { .err-grid { grid-template-columns: 1fr; } .err-emblem { max-width: 18rem; margin: 2rem 0 0; } }

    .err-pretitle { display: flex; align-items: center; gap: 1rem; font-family: var(--mono); font-size: var(--t-micro); letter-spacing: 0.22em; text-transform: uppercase; color: var(--ink-faint); margin-bottom: 2rem; }
    .err-pretitle .bar { flex: 1; height: 1px; background: var(--rule-strong); max-width: 4rem; }

    .err-code { font-family: var(--serif); font-weight: 500; font-size: clamp(5rem, 18vw, 14rem); line-height: 0.9; letter-spacing: -0.04em; color: var(--ink); margin: 0; }
    .err-code em { color: var(--rubric); font-style: italic; font-weight: 400; }

    .err-title { font-family: var(--serif); font-weight: 500; font-size: clamp(1.75rem, 4vw, 2.75rem); line-height: 1.05; letter-spacing: -0.02em; margin: 0.75rem 0 1rem; }
    .err-title em { color: var(--rubric); font-style: italic; font-weight: 400; }

    .err-lede { font-family: var(--serif); font-size: clamp(1.05rem, 1.4vw, 1.2rem); line-height: 1.55; color: var(--ink-soft); max-width: 32rem; margin: 0 0 2rem; }
    .err-lede em { color: var(--ink); font-style: italic; }

    .err-cta { display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; }

    .err-emblem { position: relative; aspect-ratio: 1 / 1; max-width: 28rem; margin-left: auto; width: 100%; }
    .err-emblem svg { width: 100%; height: 100%; }
    .err-emblem .caption { margin-top: 0.85rem; display: flex; justify-content: space-between; align-items: baseline; gap: 1rem; font-family: var(--mono); font-size: var(--t-micro); letter-spacing: 0.14em; text-transform: uppercase; color: var(--ink-faint); }
    .err-emblem .caption em { font-family: var(--serif); font-style: italic; text-transform: none; letter-spacing: 0; color: var(--ink-soft); font-size: 0.95rem; }

    .err-marginalia { padding: clamp(2rem, 5vw, 4rem) 0; border-bottom: var(--hairline) solid var(--rule-strong); }
    .err-marg-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0; border-top: var(--hairline) solid var(--rule); border-left: var(--hairline) solid var(--rule); }
    @media (max-width: 880px) { .err-marg-grid { grid-template-columns: 1fr; } }
    .err-marg { padding: 1.5rem 1.5rem; border-right: var(--hairline) solid var(--rule); border-bottom: var(--hairline) solid var(--rule); }
    .err-marg .lab { font-family: var(--mono); font-size: var(--t-micro); letter-spacing: 0.18em; text-transform: uppercase; color: var(--rubric); margin-bottom: 0.5rem; }
    .err-marg .text { font-family: var(--serif); font-size: 1rem; line-height: 1.55; color: var(--ink-soft); }
    .err-marg .text em { color: var(--ink); font-style: italic; }
    .err-marg a { color: var(--ink); border-bottom: 1px dotted var(--rule-strong); }
    .err-marg a:hover { color: var(--rubric); border-color: var(--rubric); }
</style>
@endpush

@section('body')

@include('partials.colophon', ['edition' => 'errata · status ' . ($code ?? ''), 'active' => null])

<main class="err-shell">
    <section class="err-frontispiece">
        <div class="frame">
            <div class="err-pretitle">
                <span class="bar"></span>
                <span>Errata · status {{ $code ?? '???' }}</span>
                <span class="bar"></span>
            </div>

            <div class="err-grid">
                <div>
                    <h1 class="err-code">
                        @if ($code)
                            {{ substr((string) $code, 0, 1) }}<em>{{ substr((string) $code, 1) }}</em>
                        @else
                            ???
                        @endif
                    </h1>
                    <h2 class="err-title">{{ $title ?? 'A page is missing.' }}</h2>
                    <p class="err-lede">@yield('lede')</p>

                    <div class="err-cta">
                        <a class="btn" href="{{ route('landing') }}">
                            <span>Return to the frontispiece</span><span class="arrow">→</span>
                        </a>
                        @auth
                            <a class="btn btn-ghost" href="{{ route('palace.index') }}">
                                <span>Walk the palace</span><span class="arrow">→</span>
                            </a>
                        @endauth
                    </div>
                </div>

                <figure class="err-emblem" aria-hidden="true">
                    @yield('emblem')
                    <figcaption class="caption">
                        <span>Plate · errata</span>
                        <em>"@yield('emblem-quote')"</em>
                        <span>{{ $code ?? '???' }}</span>
                    </figcaption>
                </figure>
            </div>
        </div>
    </section>

    <section class="err-marginalia">
        <div class="frame">
            <div class="err-marg-grid">
                <div class="err-marg">
                    <div class="lab">What now</div>
                    <div class="text">@yield('marg-1')</div>
                </div>
                <div class="err-marg">
                    <div class="lab">If this is a bug</div>
                    <div class="text">
                        File an issue on
                        <a href="https://github.com/coopers98/mnemon/issues" rel="noopener noreferrer" target="_blank">github / coopers98</a>
                        with the URL and the time. The audit log keeps a copy.
                    </div>
                </div>
                <div class="err-marg">
                    <div class="lab">Audit</div>
                    <div class="text">
                        Status <em>{{ $code ?? '???' }}</em> at
                        <em>{{ now()->format('Y-m-d H:i') }} UTC</em>. Logged on this host;
                        nothing left the building.
                    </div>
                </div>
            </div>
        </div>
    </section>

    @include('partials.footer')
</main>

@endsection
