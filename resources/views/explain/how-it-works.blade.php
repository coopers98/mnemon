@extends('layouts.mnemon')

@section('title', 'How Mnemon works — storage, retrieval, and what it refuses to do')
@section('meta_description', 'A long-form explanation of Mnemon: the two storage layers, how hybrid retrieval scores results, why there is no ontology, what the benchmark does and does not prove, and what happens to your private data.')

@push('head')
<style>
    .ex-head { padding: clamp(2.5rem, 6vw, 4.5rem) 0 clamp(2rem, 4vw, 3rem); border-bottom: var(--hairline) solid var(--rule-strong); }
    .ex-pretitle { display: flex; align-items: center; gap: 1rem; font-family: var(--mono); font-size: var(--t-micro); letter-spacing: 0.22em; text-transform: uppercase; color: var(--ink-faint); margin-bottom: 1.75rem; }
    .ex-pretitle .bar { flex: 1; height: 1px; background: var(--rule-strong); max-width: 4rem; }
    .ex-title { font-family: var(--serif); font-weight: 500; font-size: clamp(2.4rem, 6.5vw, 5rem); line-height: 1.0; letter-spacing: -0.025em; margin: 0 0 1.5rem; text-wrap: balance; }
    .ex-title em { font-style: italic; color: var(--rubric); }
    .ex-standfirst { font-family: var(--serif); font-size: clamp(1.1rem, 1.6vw, 1.35rem); line-height: 1.55; color: var(--ink-soft); max-width: 46rem; margin: 0; }

    /* Contents — a real index, because the page is long. */
    .ex-toc { margin-top: 2.5rem; padding-top: 1.5rem; border-top: var(--hairline) solid var(--rule); display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.1rem 2rem; }
    @media (max-width: 860px) { .ex-toc { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 520px) { .ex-toc { grid-template-columns: 1fr; } }
    .ex-toc a { display: grid; grid-template-columns: 1.75rem 1fr; gap: 0.6rem; padding: 0.4rem 0; font-size: var(--t-small); color: var(--ink-soft); text-decoration: none; border-bottom: var(--hairline) solid var(--rule); }
    .ex-toc a:hover { color: var(--rubric); }
    .ex-toc a span:first-child { font-family: var(--mono); font-size: var(--t-micro); color: var(--rubric); padding-top: 0.15em; }

    /* Sections */
    .ex-sec { padding: clamp(3rem, 6vw, 4.5rem) 0; border-bottom: var(--hairline) solid var(--rule-strong); scroll-margin-top: 5rem; }
    .ex-body { max-width: 42rem; display: flex; flex-direction: column; gap: 1.1rem; }
    .ex-body p { font-family: var(--serif); font-size: 1.1rem; line-height: 1.68; color: var(--ink-soft); margin: 0; }
    .ex-body p.first { font-size: 1.25rem; color: var(--ink); }
    .ex-body p strong { color: var(--ink); font-weight: 600; }
    .ex-body h3 { font-family: var(--serif); font-weight: 600; font-size: var(--t-h4); line-height: 1.2; letter-spacing: -0.01em; margin: 1rem 0 -0.4rem; color: var(--ink); }
    .ex-body code { font-family: var(--mono); font-size: 0.82em; background: var(--paper-deep); border: var(--hairline) solid var(--rule); padding: 0.1em 0.34em; color: var(--ink); }
    .ex-body a { color: var(--rubric); }

    .ex-wide { max-width: 58rem; }

    /* A question posed, then answered — the page's recurring device. */
    .ex-q { border-left: 2px solid var(--rubric); padding: 0.1rem 0 0.1rem 1.35rem; margin: 0.5rem 0; }
    .ex-q p { font-family: var(--serif); font-style: italic; font-size: 1.15rem; line-height: 1.5; color: var(--ink); margin: 0; }

    /* Figure plate */
    .ex-fig { margin: 2rem 0 0; max-width: 58rem; }
    .ex-plate { background: var(--paper-deep); border: var(--hairline) solid var(--rule-strong); padding: 1.75rem 1.25rem; overflow-x: auto; }
    .ex-plate svg { display: block; max-width: 100%; height: auto; margin: 0 auto; min-width: 460px; }
    .ex-fig figcaption { margin-top: 0.9rem; font-family: var(--mono); font-size: var(--t-micro); line-height: 1.75; letter-spacing: 0.04em; color: var(--ink-faint); }
    .ex-fig figcaption b { color: var(--ink-soft); font-weight: 500; }

    /* Ledger */
    .ex-scroll { overflow-x: auto; border: var(--hairline) solid var(--rule-strong); margin: 1.5rem 0 0; max-width: 46rem; }
    .ex-table { border-collapse: collapse; width: 100%; font-size: var(--t-small); min-width: 28rem; }
    .ex-table caption { font-family: var(--mono); font-size: var(--t-micro); letter-spacing: 0.16em; text-transform: uppercase; color: var(--ink-faint); text-align: left; padding: 0.85rem 1rem; border-bottom: var(--hairline) solid var(--rule-strong); }
    .ex-table th, .ex-table td { padding: 0.6rem 1rem; text-align: left; border-bottom: var(--hairline) solid var(--rule); }
    .ex-table thead th { font-family: var(--mono); font-size: var(--t-micro); letter-spacing: 0.12em; text-transform: uppercase; color: var(--ink-faint); font-weight: 400; }
    .ex-table tbody tr:last-child th, .ex-table tbody tr:last-child td { border-bottom: 0; }
    .ex-table tbody th { font-weight: 400; color: var(--ink-soft); }
    .ex-table .n { font-family: var(--mono); font-variant-numeric: tabular-nums; text-align: right; color: var(--ink); }
    .ex-table .flag { background: var(--rubric-wash); }
    .ex-table .flag .n { color: var(--rubric); font-weight: 500; }

    /* Two-column trade ledger */
    .ex-trades { display: grid; grid-template-columns: 1fr 1fr; gap: 2rem 3rem; margin-top: 1.5rem; max-width: 58rem; }
    @media (max-width: 720px) { .ex-trades { grid-template-columns: 1fr; } }
    .ex-trades > div { display: flex; flex-direction: column; gap: 0.7rem; }
    .ex-trades h3 { font-family: var(--mono); font-size: var(--t-micro); letter-spacing: 0.16em; text-transform: uppercase; color: var(--ink-faint); margin: 0; padding-bottom: 0.6rem; border-bottom: var(--hairline) solid var(--rule); }
    .ex-trades ul { margin: 0; padding-left: 1.05rem; display: flex; flex-direction: column; gap: 0.6rem; }
    .ex-trades li { font-size: 0.95rem; line-height: 1.55; color: var(--ink-soft); }
    .ex-trades li strong { color: var(--ink); font-weight: 600; }
    .ex-trades li::marker { color: var(--rubric); }

    /* Honest limitations list */
    .ex-limits { list-style: none; padding: 0; margin: 1.5rem 0 0; max-width: 50rem; border-top: var(--hairline) solid var(--rule); }
    .ex-limits li { display: grid; grid-template-columns: 1.75rem 1fr; gap: 0.9rem; padding: 0.95rem 0; border-bottom: var(--hairline) solid var(--rule); align-items: baseline; }
    .ex-limits .n { font-family: var(--mono); font-size: var(--t-micro); color: var(--rubric); }
    .ex-limits .t { font-size: 0.95rem; line-height: 1.55; color: var(--ink-soft); }
    .ex-limits .t b { color: var(--ink); font-weight: 600; }

    /* Metric pairs */
    .ex-metrics { display: grid; grid-template-columns: repeat(4, 1fr); border: var(--hairline) solid var(--rule-strong); margin: 1.5rem 0 0; max-width: 50rem; }
    @media (max-width: 720px) { .ex-metrics { grid-template-columns: repeat(2, 1fr); } }
    .ex-metric { padding: 1.1rem 1.25rem; border-right: var(--hairline) solid var(--rule); }
    .ex-metric:last-child { border-right: 0; }
    @media (max-width: 720px) {
        .ex-metric:nth-child(2) { border-right: 0; }
        .ex-metric:nth-child(-n+2) { border-bottom: var(--hairline) solid var(--rule); }
    }
    .ex-metric .lab { font-family: var(--mono); font-size: var(--t-micro); letter-spacing: 0.14em; text-transform: uppercase; color: var(--ink-faint); display: block; margin-bottom: 0.5rem; }
    .ex-metric .val { font-family: var(--mono); font-size: 1.05rem; font-variant-numeric: tabular-nums; color: var(--ink); }
    .ex-metric .val .was { color: var(--ink-ghost); }
    .ex-metric .val .now { color: var(--rubric); font-weight: 500; }

    /* Caveat note */
    .ex-note { background: var(--paper-deep); border-left: 2px solid var(--ink-ghost); padding: 1.1rem 1.35rem; margin: 1.5rem 0 0; max-width: 46rem; display: flex; flex-direction: column; gap: 0.5rem; }
    .ex-note .tag { font-family: var(--mono); font-size: var(--t-micro); letter-spacing: 0.16em; text-transform: uppercase; color: var(--ink-faint); }
    .ex-note p { font-size: 0.95rem; line-height: 1.6; color: var(--ink-soft); margin: 0; }
    .ex-note code { font-family: var(--mono); font-size: 0.85em; }

    /* Install block */
    .ex-pre { background: var(--paper-deep); border: var(--hairline) solid var(--rule-strong); padding: 1.35rem 1.5rem; overflow-x: auto; margin: 1.5rem 0 0; max-width: 46rem; }
    .ex-pre code { font-family: var(--mono); font-size: var(--t-small); line-height: 1.9; color: var(--ink); white-space: pre; display: block; background: none; border: 0; padding: 0; }
    .ex-pre .c { color: var(--ink-faint); }
</style>
@endpush

@section('body')

@include('partials.colophon', ['edition' => 'how it works', 'active' => 'how-it-works'])

<main>
    <header class="ex-head">
        <div class="frame">
            <div class="ex-pretitle">
                <span class="bar"></span>
                <span>Treatise No. 002 — On the Reading of Memory</span>
            </div>
            <h1 class="ex-title">How it works, and what it <em>refuses</em> to do.</h1>
            <p class="ex-standfirst">Mnemon stores what you and your agents say, verbatim, and hands the
                relevant parts back to any tool that speaks MCP. This page explains the mechanism rather
                than the pitch: how results are actually scored, why there is no schema for relationships,
                what the benchmark does and does not prove, and what happens to private content you would
                rather not have leave the building.</p>

            <nav class="ex-toc" aria-label="Contents">
                <a href="#what"><span>01</span><span>What this actually is</span></a>
                <a href="#layers"><span>02</span><span>Two layers, and why</span></a>
                <a href="#retrieval"><span>03</span><span>How retrieval scores</span></a>
                <a href="#ontology"><span>04</span><span>Why there's no ontology</span></a>
                <a href="#evidence"><span>05</span><span>Does it work?</span></a>
                <a href="#privacy"><span>06</span><span>Your private content</span></a>
                <a href="#limits"><span>07</span><span>What isn't done</span></a>
                <a href="#cost"><span>08</span><span>What it costs to run</span></a>
                <a href="#try"><span>09</span><span>Trying it</span></a>
            </nav>
        </div>
    </header>

    {{-- 01 --}}
    <section class="ex-sec" id="what">
        <div class="frame">
            <div class="sec-head">
                <span class="num">§ 01</span>
                <div>
                    <div class="title">What this actually is.</div>
                    <span class="lede">A server your agents read from and write to — not a product with a chat window.</span>
                </div>
            </div>
            <div class="ex-body">
                <p class="first">Mnemon is a Laravel application you run yourself. It keeps two things: every
                    piece of content you or your agents decide is worth keeping, stored word-for-word and
                    never overwritten; and a set of compiled pages that synthesise that raw material into
                    something readable. Agents reach both through {{ 14 }} tools over the Model Context
                    Protocol.</p>
                <p>The point is that the memory is <strong>yours and shared</strong>. One instance serves
                    Claude Code, Claude Desktop, Cursor, ChatGPT and anything that can make an authenticated
                    HTTP request, so context captured in one tool is available in the next. There is no
                    hosted version, no account, and nothing phones home.</p>
                <p>If you are evaluating this, the useful question is not whether it stores text — everything
                    does. It is whether retrieval hands back the <em>right</em> text, and what it gives up to
                    do that. The rest of this page is about those two things.</p>
            </div>
        </div>
    </section>

    {{-- 02 --}}
    <section class="ex-sec" id="layers">
        <div class="frame">
            <div class="sec-head">
                <span class="num">§ 02</span>
                <div>
                    <div class="title">Two layers, and why it matters<br/>which one you are reading.</div>
                    <span class="lede">Verbatim at the base, synthesised on top. Neither is allowed to pretend to be the other.</span>
                </div>
            </div>
            <div class="ex-body">
                <p class="first">The <strong>palace</strong> is append-only storage, organised as
                    <em>wings → rooms → drawers</em>. A drawer holds content exactly as it was written.
                    Nothing is summarised at ingest and nothing is rewritten later, which means the palace
                    can always answer “what was actually said”.</p>
                <p>The <strong>wiki</strong> is the opposite: pages compiled <em>from</em> drawers, typed as
                    <code>person:</code>, <code>project:</code>, <code>concept:</code>,
                    <code>decision:</code> or <code>synthesis:</code>. A wiki page is an interpretation, and
                    it records which drawers it came from, when it was last compiled, and how many new
                    drawers have landed since.</p>
                <p>Be clear about what that last number means:
                    <strong>a page does not recompile on its own.</strong>
                    Compiling is something an agent does when asked — there is no scheduled job
                    that rewrites pages as material accumulates. Mnemon counts the backlog and will tell you
                    which pages have drifted, but closing that gap is a decision you make, because each
                    recompile is a model call against your own account.</p>
                <p>Keeping them separate is the whole design. A system that only summarises loses the
                    evidence; one that only stores raw text makes the reader do all the work every time. The
                    split means a compiled claim can always be traced back to the verbatim record that
                    produced it — and when the two disagree, the drawer wins.</p>
                <p>This instance currently holds
                    <strong>{{ number_format($stats['drawers']) }} drawers</strong>
                    across {{ number_format($stats['wings']) }} wings, compiled into
                    {{ number_format($stats['wiki_pages']) }} wiki pages.</p>
            </div>
        </div>
    </section>

    {{-- 03 --}}
    <section class="ex-sec" id="retrieval">
        <div class="frame">
            <div class="sec-head">
                <span class="num">§ 03</span>
                <div>
                    <div class="title">How a result gets chosen.</div>
                    <span class="lede">Three scoring passes over text, merged under fixed weights. No magic, and no traversal.</span>
                </div>
            </div>
            <div class="ex-body ex-wide">
                <p class="first">A query fans out to three independent legs. Each pulls three times the
                    requested number of candidates, and the results are merged under weights you can change
                    in configuration.</p>
                <p><strong>Semantic (0.6)</strong> — cosine distance between the query's embedding and each
                    drawer's, in pgvector. This is what finds a drawer about deployment when you asked about
                    shipping. <strong>Full-text (0.3)</strong> — PostgreSQL's own index, applied one term at a
                    time with stop-words removed, so an exact identifier or error string still wins.
                    <strong>Recency (0.1)</strong> — a thumb on the scale for anything from the last seven
                    days, never a filter.</p>
            </div>

            <figure class="ex-fig">
                <div class="ex-plate">
                    <svg viewBox="0 0 700 330" role="img" aria-label="A query fans out to three scoring legs — semantic at weight 0.6, full-text at 0.3, recency at 0.1 — which merge into one ranked set of drawers. The typed relationship graph sits apart from this path, reachable only by an explicit wiki_graph call, and is never consulted during retrieval.">
                        <defs>
                            <marker id="exar" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
                                <polygon points="0,1 10,5 0,9" fill="currentColor"></polygon>
                            </marker>
                        </defs>

                        <rect x="16" y="128" width="104" height="44" fill="none" stroke="currentColor" stroke-width="1.2"></rect>
                        <text x="68" y="155" text-anchor="middle" font-family="JetBrains Mono, monospace" font-size="12.5" fill="currentColor">query</text>

                        <line x1="120" y1="150" x2="206" y2="60" stroke="currentColor" stroke-width="1" marker-end="url(#exar)"></line>
                        <line x1="120" y1="150" x2="206" y2="150" stroke="currentColor" stroke-width="1" marker-end="url(#exar)"></line>
                        <line x1="120" y1="150" x2="206" y2="240" stroke="currentColor" stroke-width="1" marker-end="url(#exar)"></line>

                        <rect x="208" y="38" width="186" height="44" fill="none" stroke="currentColor" stroke-width="1.2"></rect>
                        <text x="222" y="60" font-family="JetBrains Mono, monospace" font-size="12" fill="currentColor">pgvector cosine</text>
                        <text x="222" y="74" font-family="JetBrains Mono, monospace" font-size="10.5" fill="currentColor" opacity="0.62">meaning, not words</text>
                        <text x="406" y="65" font-family="JetBrains Mono, monospace" font-size="13" fill="var(--rubric)">0.6</text>

                        <rect x="208" y="128" width="186" height="44" fill="none" stroke="currentColor" stroke-width="1.2"></rect>
                        <text x="222" y="150" font-family="JetBrains Mono, monospace" font-size="12" fill="currentColor">tsvector match</text>
                        <text x="222" y="164" font-family="JetBrains Mono, monospace" font-size="10.5" fill="currentColor" opacity="0.62">exact terms, per word</text>
                        <text x="406" y="155" font-family="JetBrains Mono, monospace" font-size="13" fill="var(--rubric)">0.3</text>

                        <rect x="208" y="218" width="186" height="44" fill="none" stroke="currentColor" stroke-width="1.2"></rect>
                        <text x="222" y="240" font-family="JetBrains Mono, monospace" font-size="12" fill="currentColor">recency boost</text>
                        <text x="222" y="254" font-family="JetBrains Mono, monospace" font-size="10.5" fill="currentColor" opacity="0.62">7-day window</text>
                        <text x="406" y="245" font-family="JetBrains Mono, monospace" font-size="13" fill="var(--rubric)">0.1</text>

                        <line x1="440" y1="60" x2="516" y2="140" stroke="currentColor" stroke-width="1" marker-end="url(#exar)"></line>
                        <line x1="440" y1="150" x2="516" y2="150" stroke="currentColor" stroke-width="1" marker-end="url(#exar)"></line>
                        <line x1="440" y1="240" x2="516" y2="162" stroke="currentColor" stroke-width="1" marker-end="url(#exar)"></line>

                        <rect x="518" y="124" width="166" height="52" fill="none" stroke="currentColor" stroke-width="1.6"></rect>
                        <text x="601" y="146" text-anchor="middle" font-family="JetBrains Mono, monospace" font-size="12" fill="currentColor">weighted merge</text>
                        <text x="601" y="163" text-anchor="middle" font-family="JetBrains Mono, monospace" font-size="11" fill="currentColor" opacity="0.62">ranked drawers</text>

                        <line x1="68" y1="172" x2="68" y2="292" stroke="var(--rubric)" stroke-width="1" stroke-dasharray="3 4"></line>
                        <line x1="68" y1="292" x2="508" y2="292" stroke="var(--rubric)" stroke-width="1" stroke-dasharray="3 4"></line>
                        <polygon points="508,288 517,292 508,296" fill="var(--rubric)"></polygon>
                        <text x="80" y="283" font-family="JetBrains Mono, monospace" font-size="11" fill="var(--rubric)">only when an agent asks for it by name</text>
                        <rect x="518" y="274" width="166" height="36" fill="none" stroke="var(--rubric)" stroke-width="1.2"></rect>
                        <text x="601" y="296" text-anchor="middle" font-family="JetBrains Mono, monospace" font-size="11.5" fill="var(--rubric)">relationship graph</text>
                    </svg>
                </div>
                <figcaption><b>Figure 1.</b> Scoring is entirely over text. The relationship graph is a
                    separate structure an agent must request explicitly — nothing in the automatic path
                    traverses it. That absence is deliberate, and the next section explains why.</figcaption>
            </figure>
        </div>
    </section>

    {{-- 04 --}}
    <section class="ex-sec" id="ontology">
        <div class="frame">
            <div class="sec-head">
                <span class="num">§ 04</span>
                <div>
                    <div class="title">Why there is no ontology.</div>
                    <span class="lede">The most common objection to this design, answered without hedging.</span>
                </div>
            </div>
            <div class="ex-body ex-wide">
                <div class="ex-q">
                    <p>“How are you searching data with complex relationships without defining a full-blown
                        ontology?”</p>
                </div>
                <p class="first">Short answer: it doesn't. There are no joins, no multi-hop traversal in the
                    retrieval path, and no schema constraining what a relationship may be. That is a
                    position, not an oversight.</p>
                <p>The bet is that <strong>relationships live in compiled prose, and the reader is a language
                    model.</strong> You don't need <code>(alice)-[:MANAGES]-&gt;(bob)</code> in a graph store
                    if a retrievable page says “Alice manages Bob” and retrieval reliably surfaces that page.
                    The relational reasoning happens at read time, in the model, over text — rather than at
                    write time, in a schema.</p>
                <p>An ontology-first design asks you to know your entity types before you have data. For a
                    system ingesting arbitrary working transcripts, you never do. Every relationship type you
                    failed to anticipate becomes a migration, and every fact that doesn't fit the schema gets
                    dropped at the door.</p>

                <h3>What the graph actually contains</h3>
                <p>There <em>is</em> a typed relationship store, and being straight about it matters more than
                    defending it. The schema defines {{ $stats['edge_types_available'] }} edge types and the
                    traversal code will walk five hops. Here is what is in it right now:</p>
            </div>

            <div class="ex-scroll">
                <table class="ex-table">
                    <caption>This instance, live</caption>
                    <thead>
                        <tr><th scope="col">Quantity</th><th scope="col" class="n">Count</th></tr>
                    </thead>
                    <tbody>
                        <tr><th scope="row">Drawers (verbatim)</th><td class="n">{{ number_format($stats['drawers']) }}</td></tr>
                        <tr><th scope="row">Compiled wiki pages</th><td class="n">{{ number_format($stats['wiki_pages']) }}</td></tr>
                        <tr><th scope="row">Typed edges</th><td class="n">{{ number_format($stats['edges']) }}</td></tr>
                        <tr class="flag"><th scope="row">Generic <code>references</code> edges</th><td class="n">{{ number_format($stats['generic_edges']) }}</td></tr>
                        <tr class="flag"><th scope="row">Edge types in use</th><td class="n">{{ $stats['edge_types_used'] }} of {{ $stats['edge_types_available'] }}</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="ex-body ex-wide" style="margin-top:1.5rem;">
                <p>Nearly every edge is the most generic term in the vocabulary. <code>uses</code>,
                    <code>depends-on</code>, <code>caused</code> and <code>contradicts</code> are defined and
                    effectively unwritten. So what ships is a shallow, largely untyped link graph beside a
                    retrieval engine that doesn't consult it — and the typed vocabulary is, honestly,
                    aspirational.</p>
            </div>

            <div class="ex-trades">
                <div>
                    <h3>What the choice buys</h3>
                    <ul>
                        <li>It works on arbitrary transcripts from the <strong>first ingest</strong> — no modelling phase.</li>
                        <li>A new kind of relationship needs <strong>no migration</strong>. Prose absorbs it.</li>
                        <li>Nothing is <strong>silently dropped</strong> for not fitting a schema.</li>
                        <li>Only the compile step must be clever, and it is a prompt rather than a data model.</li>
                    </ul>
                </div>
                <div>
                    <h3>What it costs</h3>
                    <ul>
                        <li><strong>No multi-hop queries.</strong> “Which projects depend on a library Alice owns” is unanswerable.</li>
                        <li><strong>No contradiction detection</strong>, though the edge type exists to express it.</li>
                        <li><strong>Fidelity equals whatever the compiler noticed.</strong> Nothing forces the question, so an unobserved relationship isn't there.</li>
                        <li>Edges are navigational, not semantic.</li>
                    </ul>
                </div>
            </div>

            <div class="ex-note">
                <span class="tag">If you need relational search</span>
                <p>The route is not “define an ontology” — it is to make the compile step emit the typed
                    edges it already has a schema for. The table, traversal, depth limits, vocabulary and MCP
                    tool all exist and sit unused. That makes it an extraction-and-prompting problem rather
                    than a data-modelling one, and it keeps the original bet intact: edges become an index
                    <em>over</em> prose that still carries the meaning, rather than a cage the prose is
                    flattened into.</p>
            </div>
        </div>
    </section>

    {{-- 05 --}}
    <section class="ex-sec" id="evidence">
        <div class="frame">
            <div class="sec-head">
                <span class="num">§ 05</span>
                <div>
                    <div class="title">Does it actually work?</div>
                    <span class="lede">Measured on a public benchmark, with the scope stated rather than buried.</span>
                </div>
            </div>
            <div class="ex-body ex-wide">
                <p class="first">Most memory systems assert that semantic retrieval helps. This one measures
                    it. The full 500-question LongMemEval-S set was run twice over identical content — once
                    with embeddings off, once on — so the only variable is the embedding.</p>
            </div>

            <div class="ex-metrics">
                <div class="ex-metric">
                    <span class="lab">Hit rate @1</span>
                    <span class="val"><span class="was">0.742</span> → <span class="now">0.886</span></span>
                </div>
                <div class="ex-metric">
                    <span class="lab">Recall @5</span>
                    <span class="val"><span class="was">0.832</span> → <span class="now">0.952</span></span>
                </div>
                <div class="ex-metric">
                    <span class="lab">MRR</span>
                    <span class="val"><span class="was">0.817</span> → <span class="now">0.924</span></span>
                </div>
                <div class="ex-metric">
                    <span class="lab">Answer accuracy</span>
                    <span class="val"><span class="was">0.557</span> → <span class="now">0.627</span></span>
                </div>
            </div>

            <div class="ex-body ex-wide" style="margin-top:1.5rem;">
                <p>Embeddings improve every metric at every depth. Total spend was <strong>$36.76 against a
                    $37 estimate</strong> extrapolated from a two-question smoke test — within one percent at
                    250× the sample size.</p>
                <p>An earlier 25-question subset had suggested embeddings only re-ranked results rather than
                    finding more evidence. The full run overturned that. The subset was not merely imprecise,
                    it was misleading, and the correction is documented rather than quietly replaced.</p>
                <div class="ex-note">
                    <span class="tag">What this does not prove</span>
                    <p>The benchmark measures <em>whether the right evidence is retrieved</em> from the palace
                        layer. It is not a test of relational reasoning, of the wiki layer, or of long-horizon
                        agent behaviour, and it should not be cited as one. The answer-accuracy figure is also
                        judged by the same model family that produced the answers. Full methodology and three
                        further caveats ship in the repository.</p>
                </div>
            </div>
        </div>
    </section>

    {{-- 06 --}}
    <section class="ex-sec" id="privacy">
        <div class="frame">
            <div class="sec-head">
                <span class="num">§ 06</span>
                <div>
                    <div class="title">What happens to content you<br/>would rather not share.</div>
                    <span class="lede">Self-hosting answers most of it. Three mechanisms answer the rest.</span>
                </div>
            </div>
            <div class="ex-body ex-wide">
                <p class="first">The instance is yours, so the baseline is simple: content goes to your
                    database on your host, and nothing is transmitted anywhere unless you configure an
                    embedding provider. With the default settings nothing leaves the machine at all.</p>

                <h3>Secrets are stripped before storage</h3>
                <p>Captured content passes a sanitiser on the way in. It redacts OpenAI-style keys,
                    GitHub tokens, bearer tokens, database passwords embedded in connection URLs, and
                    <code>PASSWORD=</code> / <code>SECRET=</code> / <code>API_KEY=</code> assignments. This
                    runs at write time, so an accidentally pasted credential is redacted in the stored copy
                    rather than sitting in your memory forever.</p>

                <h3>Agents are restricted per device</h3>
                <p>Each device gets its own OAuth credential, and each credential is scoped to the wings it
                    may read. An agent restricted to <code>work</code> cannot read a <code>personal</code>
                    drawer — the check runs before the tool does, and a denial is written to the audit log.
                    Every tool call is recorded with which credential made it and when.</p>

                <h3>One caveat worth knowing before you compile</h3>
                <p>Wiki pages take their wing from the page <em>name</em>, not from the drawers they were
                    compiled from. A page named after the wing it synthesises is scoped correctly. A page
                    named generically but compiled from restricted material would be scoped by its name
                    instead of its content. Name pages after the wing they belong to and the isolation
                    holds.</p>
            </div>
        </div>
    </section>

    {{-- 07 --}}
    <section class="ex-sec" id="limits">
        <div class="frame">
            <div class="sec-head">
                <span class="num">§ 07</span>
                <div>
                    <div class="title">What isn't done.</div>
                    <span class="lede">A working personal tool, not a finished product. The gaps, in full.</span>
                </div>
            </div>
            <div class="ex-body ex-wide">
                <p class="first">This list is maintained in the repository and kept current. If something
                    here is a dealbreaker, better to find out now than after an afternoon of setup.</p>
            </div>
            <ol class="ex-limits">
                <li><span class="n">01</span><span class="t"><b>Wiki wing scope follows the page name</b>, not the sources it was compiled from — as described above.</span></li>
                <li><span class="n">02</span><span class="t"><b>The wiki does not maintain itself.</b> Pages are compiled when an agent is asked to compile them. <code>mnemon:auto-compile-stale</code> reports which pages have drifted; it does not rewrite them, and nothing is scheduled to.</span></li>
                <li><span class="n">03</span><span class="t"><b>Single-tenant.</b> Any registered user of the admin panel is an administrator. Agent isolation is per-credential; human isolation does not exist.</span></li>
                <li><span class="n">04</span><span class="t"><b>Semantic search requires PostgreSQL with pgvector.</b> SQLite works and falls back to full-text plus recency, with no semantic ranking.</span></li>
                <li><span class="n">05</span><span class="t"><b>No server-push streaming.</b> The MCP transport is request/response; a GET on the endpoint returns 405, which the specification permits.</span></li>
                <li><span class="n">06</span><span class="t"><b>No reverse-proxy TLS support.</b> <code>X-Forwarded-*</code> headers are not processed, so run the bundled HTTPS path rather than terminating TLS upstream.</span></li>
                <li><span class="n">07</span><span class="t"><b>Drawers cannot be hard-deleted through the API.</b> Removal is an admin-panel action; the agent-facing layer is read-and-append.</span></li>
                <li><span class="n">08</span><span class="t"><b>Access tokens last one hour</b>, refresh tokens ninety days. Enrolled devices renew themselves; a static token does not.</span></li>
                <li><span class="n">09</span><span class="t"><b>Word counts are ASCII-only</b>, so multi-byte content under-counts. Cosmetic, and documented.</span></li>
            </ol>
        </div>
    </section>

    {{-- 08 --}}
    <section class="ex-sec" id="cost">
        <div class="frame">
            <div class="sec-head">
                <span class="num">§ 08</span>
                <div>
                    <div class="title">What it costs to run.</div>
                    <span class="lede">Zero by default. The only meter that can run is one you switch on.</span>
                </div>
            </div>
            <div class="ex-body ex-wide">
                <p class="first">The shipped default is <code>MNEMON_EMBEDDING_DRIVER=none</code>: no account,
                    no API key, no spend. Retrieval falls back to full-text plus recency, which works —
                    it is simply the weaker leg the benchmark above measures against.</p>
                <p>Turning on semantic search means choosing a provider. <strong>OpenAI</strong>
                    (<code>text-embedding-3-small</code>) is the path the benchmark used. <strong>Ollama</strong>
                    (<code>nomic-embed-text</code>) runs locally against a model on your own hardware, so it
                    costs nothing and sends nothing out; it needs an Ollama server reachable from the app.</p>
                <p>Embedding cost is incurred twice: once per drawer when it is stored or re-embedded, and
                    once per query. Backfilling an existing corpus is the expensive moment — a measured run
                    embedded roughly two drawers per second, so a few thousand drawers is a job to start and
                    walk away from, not something to wait on.</p>
                <p>Beyond that it is a PHP application and a Postgres database. It runs comfortably on the
                    smallest instance any host offers.</p>
            </div>
        </div>
    </section>

    {{-- 09 --}}
    <section class="ex-sec" id="try">
        <div class="frame">
            <div class="sec-head">
                <span class="num">§ 09</span>
                <div>
                    <div class="title">Trying it.</div>
                    <span class="lede">Four commands, bound to loopback, no account.</span>
                </div>
            </div>
            <div class="ex-body ex-wide">
                <p class="first">The stack binds to <code>127.0.0.1</code> by default, so a trial is never
                    exposed to the network.</p>
            </div>
            <div class="ex-pre"><code><span class="c"># clone, configure, start</span>
git clone https://github.com/coopers98/mnemon.git
cd mnemon
cp .env.docker.example .env
docker compose up -d</code></div>
            <div class="ex-body ex-wide" style="margin-top:1.5rem;">
                <p>Then open <code>http://localhost:8080</code>. The admin password is generated on first
                    boot and written inside the container — there is no reset flow, so save it. Connecting an
                    agent, serving a real hostname with automatic certificates, and the native install are all
                    covered in the user guide.</p>
                <p>The source, the benchmark harness, and the limitations list above all live at
                    <a href="https://github.com/coopers98/mnemon">github.com/coopers98/mnemon</a> under the
                    MIT licence.</p>
            </div>
        </div>
    </section>
</main>

@include('partials.footer')

@endsection
