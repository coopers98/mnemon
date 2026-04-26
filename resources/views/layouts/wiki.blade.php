@extends('layouts.mnemon')

@push('head')
<style>
    .wiki-shell { display: grid; grid-template-columns: 17rem minmax(0, 1fr) 16rem; gap: clamp(2rem, 4vw, 4rem); padding: clamp(2.5rem, 5vw, 4rem) 0 6rem; align-items: start; }
    @media (max-width: 1180px) { .wiki-shell { grid-template-columns: 15rem minmax(0,1fr); } .marginalia-rail { display: none; } }
    @media (max-width: 820px)  { .wiki-shell { grid-template-columns: 1fr; } .toc-rail { position: static !important; border-right: 0 !important; padding-right: 0 !important; max-height: none !important; } }

    .toc-rail { position: sticky; top: 5rem; align-self: start; max-height: calc(100vh - 6rem); overflow-y: auto; padding-right: 1.25rem; border-right: var(--hairline) solid var(--rule); font-size: 0.875rem; }
    .toc-rail h6 { font-family: var(--mono); font-size: var(--t-micro); letter-spacing: 0.18em; text-transform: uppercase; color: var(--ink-faint); margin: 0 0 0.85rem; font-weight: 500; }
    .toc-section { font-family: var(--serif); font-size: 1.05rem; letter-spacing: -0.005em; color: var(--ink); margin: 1.5rem 0 0.5rem; padding-bottom: 0.4rem; border-bottom: var(--hairline) solid var(--rule); display: flex; align-items: baseline; flex-wrap: nowrap; white-space: nowrap; gap: 0.6rem; }
    .toc-section .n { font-family: var(--mono); font-size: 0.65rem; letter-spacing: 0.16em; color: var(--rubric); flex-shrink: 0; }
    .toc-section > span:last-child { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .toc-list { list-style: none; padding: 0; margin: 0; display: grid; gap: 0.35rem; }
    .toc-list a { display: grid; grid-template-columns: 1.6rem 1fr; gap: 0.4rem; padding: 0.25rem 0; color: var(--ink-soft); transition: color 0.15s; }
    .toc-list a:hover, .toc-list a.is-active { color: var(--rubric); }
    .toc-list a.is-active { font-weight: 500; }
    .toc-list a .num { font-family: var(--mono); font-size: 0.7rem; color: var(--ink-ghost); letter-spacing: 0.06em; padding-top: 0.1em; }
    .toc-list a.is-active .num { color: var(--rubric); }
    .toc-foot { margin-top: 2rem; padding-top: 1rem; border-top: var(--hairline) solid var(--rule); font-family: var(--mono); font-size: var(--t-micro); letter-spacing: 0.06em; color: var(--ink-faint); line-height: 1.6; }
    .toc-foot a { color: var(--ink); border-bottom: 1px dotted var(--ink-faint); }
    .toc-foot a:hover { color: var(--rubric); border-color: var(--rubric); }

    .article { max-width: 38rem; }
    .article-meta { display: flex; gap: 1.25rem; align-items: baseline; flex-wrap: wrap; font-family: var(--mono); font-size: var(--t-micro); letter-spacing: 0.16em; text-transform: uppercase; color: var(--ink-faint); margin-bottom: 1.5rem; }
    .article-meta .crumb { color: var(--ink); }
    .article-meta .crumb a { border-bottom: 1px dotted var(--rule-strong); padding-bottom: 1px; }
    .article-meta .crumb a:hover { color: var(--rubric); border-color: var(--rubric); }

    .article h1.doc-title { font-size: clamp(2.5rem, 5vw, 3.75rem); font-weight: 500; letter-spacing: -0.02em; line-height: 1.02; margin-bottom: 0.75rem; }
    .article h1.doc-title em { color: var(--rubric); font-style: italic; font-weight: 400; }
    .article .doc-sub { font-family: var(--serif); font-style: italic; font-size: 1.35rem; line-height: 1.45; color: var(--ink-faint); margin: 0 0 1.75rem; }

    .doc-byline { display: flex; gap: 2rem; flex-wrap: wrap; padding: 0.85rem 0; border-top: var(--hairline) solid var(--rule-strong); border-bottom: var(--hairline) solid var(--rule-strong); font-family: var(--mono); font-size: var(--t-micro); letter-spacing: 0.14em; text-transform: uppercase; color: var(--ink-faint); margin-bottom: 2.5rem; }
    .doc-byline b { color: var(--ink); font-weight: 500; }

    .article p, .article li { font-family: var(--serif); font-size: 1.125rem; line-height: 1.65; color: var(--ink); }
    .article p { margin: 0 0 1.1em; }
    .article p.lede { font-size: 1.3rem; line-height: 1.5; color: var(--ink); }
    .article p.lede.dropcap::first-letter { color: var(--rubric); }

    .article h2 { font-family: var(--serif); font-size: 2.2rem; letter-spacing: -0.015em; margin: 4rem 0 1rem; padding-top: 1.5rem; border-top: var(--hairline) solid var(--rule-strong); font-weight: 500; }
    .article h3 { font-family: var(--serif); font-size: 1.4rem; font-weight: 600; margin: 2.25rem 0 0.5rem; letter-spacing: -0.005em; }
    .article h4, .article h5, .article h6 { font-family: var(--serif); font-weight: 600; margin: 1.75rem 0 0.5rem; }
    .article ul, .article ol { padding-left: 1.5rem; margin: 0 0 1.1em; }
    .article a { color: var(--ink); border-bottom: 1px solid var(--rule-strong); transition: color 0.15s, border-color 0.15s; }
    .article a:hover { color: var(--rubric); border-color: var(--rubric); }
    .article code { font-family: var(--mono); font-size: 0.9em; background: var(--paper-deep); border: var(--hairline) solid var(--rule); padding: 0.05em 0.35em; }
    .article pre { font-family: var(--mono); font-size: 0.875rem; line-height: 1.7; background: var(--paper-deep); border: var(--hairline) solid var(--rule-strong); padding: 1.25rem 1.25rem; overflow-x: auto; margin: 1.75rem 0; }
    .article pre code { background: transparent; border: 0; padding: 0; }
    .article blockquote { margin: 2.5rem -1rem; padding: 1.5rem 1.5rem 1.5rem 1.75rem; border-left: 3px solid var(--rubric); background: var(--paper-deep); font-family: var(--serif); font-style: italic; font-size: 1.45rem; line-height: 1.4; color: var(--ink); }
    .article blockquote p:last-child { margin: 0; }

    /* Wikilinks */
    .article .wiki-link { color: var(--ink); border-bottom: 1px dotted var(--rubric); }
    .article .wiki-link:hover { color: var(--rubric); border-color: var(--rubric); }
    .article .wiki-link-broken { color: var(--ink-faint); border-bottom: 1px dashed var(--rubric); font-style: italic; }

    .marginalia-rail { position: sticky; top: 5rem; align-self: start; max-height: calc(100vh - 6rem); overflow-y: auto; padding-left: 1.25rem; border-left: var(--hairline) solid var(--rule); }
    .margin-block { margin-bottom: 2.25rem; padding-bottom: 1.25rem; border-bottom: var(--hairline) solid var(--rule); }
    .margin-block:last-child { border-bottom: 0; }
    .margin-block .lab { font-family: var(--mono); font-size: var(--t-micro); letter-spacing: 0.16em; text-transform: uppercase; color: var(--rubric); margin-bottom: 0.4rem; }
    .margin-block .text { font-family: var(--serif); font-size: 0.95rem; line-height: 1.5; color: var(--ink-soft); }
    .margin-block .text em { color: var(--ink); font-style: italic; }
    .margin-block .ref { display: block; margin-top: 0.85rem; padding-top: 0.4rem; border-top: 1px dotted var(--rule); font-family: var(--mono); font-size: 0.7rem; color: var(--ink-faint); letter-spacing: 0.04em; }
    .margin-block a { color: var(--ink); border-bottom: 1px dotted var(--rule-strong); }
    .margin-block a:hover { color: var(--rubric); border-color: var(--rubric); }

    .stamp { display: inline-block; margin-top: 2rem; padding: 0.5rem 0.75rem; border: 1px solid var(--rubric); font-family: var(--mono); font-size: var(--t-micro); letter-spacing: 0.16em; text-transform: uppercase; color: var(--rubric); transform: rotate(-1deg); }

    .pill { display: inline-flex; align-items: center; gap: 0.4rem; font-family: var(--mono); font-size: var(--t-micro); letter-spacing: 0.14em; text-transform: uppercase; color: var(--ink-faint); padding: 0.2rem 0.55rem; border: var(--hairline) solid var(--rule-strong); }
    .pill.is-rubric { color: var(--rubric); border-color: var(--rubric); }
    .pill.is-ink { color: var(--ink); border-color: var(--ink); }
    .pill .dot { width: 5px; height: 5px; background: currentColor; transform: rotate(45deg); }

    .empty { padding: 3rem 2rem; border: var(--hairline) solid var(--rule); background: var(--paper-deep); text-align: center; color: var(--ink-faint); font-family: var(--serif); font-style: italic; font-size: 1.1rem; }
</style>
@stack('wiki-head')
@endpush

@section('body')

@include('partials.colophon', ['edition' => $edition ?? 'wiki / vol. i', 'active' => $navActive ?? 'wiki'])

<main class="frame">
    <div class="wiki-shell">
        <aside class="toc-rail" aria-label="Table of contents">
            @yield('sidebar')
        </aside>

        <article class="article">
            @yield('content')
        </article>

        @hasSection('marginalia')
            <aside class="marginalia-rail" aria-label="Marginalia">
                @yield('marginalia')
            </aside>
        @else
            <aside class="marginalia-rail" aria-label="Marginalia"></aside>
        @endif
    </div>
</main>

@include('partials.footer')
@endsection
