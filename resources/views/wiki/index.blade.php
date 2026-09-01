@extends('layouts.wiki')

@section('title', 'Wiki — Mnemon')

@section('sidebar')
    <h6>Atlas — by type</h6>

    <form action="{{ route('wiki.search') }}" method="GET" style="margin: 0 0 1rem;">
        <div style="display:flex;align-items:center;gap:0.5rem;padding:0.5rem 0.7rem;border:var(--hairline) solid var(--rule-strong);background:var(--paper-deep);">
            <span aria-hidden="true" style="color:var(--ink-faint);">⌕</span>
            <input type="text" name="q" placeholder="walk to…" value="{{ request('q') }}"
                   style="border:0;background:transparent;outline:none;flex:1;font:inherit;font-family:var(--mono);font-size:0.8rem;color:var(--ink);" />
        </div>
    </form>

    @forelse ($grouped as $type => $pages)
        <div class="toc-section"><span class="n">§</span><span>{{ ucfirst($type) }}s · {{ $pages->count() }}</span></div>
        <ul class="toc-list">
            @foreach ($pages as $idx => $page)
                <li>
                    <a href="{{ route('wiki.show', $page->name) }}">
                        <span class="num">{{ str_pad((string)($idx + 1), 2, '0', STR_PAD_LEFT) }}</span>
                        <span>{{ $page->title }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    @empty
        <p style="font-family:var(--serif);font-style:italic;color:var(--ink-faint);">No entries compiled yet.</p>
    @endforelse

    <div class="toc-foot">
        Compiled <span class="rubric">●</span> {{ $stats['last_compiled']?->diffForHumans() ?? 'never' }}<br/>
        From {{ number_format($stats['total_drawers']) }} verbatim sources<br/>
        {{ number_format($stats['total_pages']) }} entries · compiled
    </div>
@endsection

@section('content')
    <div class="article-meta">
        <span class="crumb">Wiki · vol. i</span>
        <span>est. {{ now()->format('Y-m-d') }}</span>
        <span class="rubric">● compiled</span>
    </div>

    <h1 class="doc-title">The <em>atlas</em>.</h1>
    <p class="doc-sub">
        Compiled entries, distilled from the verbatim archive. Every page traces back to a verbatim
        drawer; every drawer back to a wing. Walk the index, or jump to a specific kind of memory.
    </p>

    <div class="doc-byline">
        <span><b>{{ number_format($stats['total_pages']) }}</b> entries</span>
        <span><b>{{ number_format($stats['total_drawers']) }}</b> verbatim sources</span>
        <span><b>{{ $grouped->count() }}</b> page kinds</span>
        <span><b>last compiled</b> {{ $stats['last_compiled']?->format('Y-m-d') ?? '—' }}</span>
    </div>

    @forelse ($grouped as $type => $pages)
        <h2 id="{{ $type }}">
            {{ ucfirst($type) }}<span style="font-family:var(--mono);font-size:0.45em;letter-spacing:0.18em;color:var(--ink-faint);text-transform:uppercase;margin-left:0.75rem;">— {{ $pages->count() }} entries</span>
        </h2>

        <div style="display:grid;gap:0;border-top:var(--hairline) solid var(--rule);">
            @foreach ($pages as $page)
                <a href="{{ route('wiki.show', $page->name) }}"
                   style="display:grid;grid-template-columns:1fr auto;gap:1.5rem;padding:1.1rem 0;border-bottom:var(--hairline) solid var(--rule);align-items:baseline;color:var(--ink);border-left:0;border-right:0;border-top:0;">
                    <div>
                        <div style="display:flex;align-items:baseline;gap:0.85rem;flex-wrap:wrap;">
                            <span style="font-family:var(--serif);font-size:1.2rem;line-height:1.2;">{{ $page->title }}</span>
                            <x-confidence-badge :confidence="$page->confidence ?? 'medium'" />
                            @if ($page->pending_drawers_since_compile > 0)
                                <span class="pill is-rubric"><span class="dot"></span>{{ $page->pending_drawers_since_compile }} pending</span>
                            @endif
                        </div>
                        @if ($page->description)
                            <p style="font-family:var(--serif);font-size:1rem;color:var(--ink-soft);line-height:1.55;margin:0.4rem 0 0;border:0;">{{ Str::limit($page->description, 150) }}</p>
                        @endif
                    </div>
                    <div style="font-family:var(--mono);font-size:var(--t-micro);letter-spacing:0.1em;text-transform:uppercase;color:var(--ink-faint);text-align:right;line-height:1.6;white-space:nowrap;">
                        {{ $page->word_count ?? 0 }} words<br/>
                        @if ($page->last_compiled_at)
                            compiled {{ $page->last_compiled_at->format('Y-m-d') }}
                        @else
                            <span class="rubric">not compiled</span>
                        @endif
                    </div>
                </a>
            @endforeach
        </div>
    @empty
        <div class="empty">
            No wiki pages yet. Compile entries through the admin panel or via MCP tools.
        </div>
    @endforelse

    @if ($grouped->isNotEmpty())
        <span class="stamp">Compiled · {{ now()->format('Y-m-d') }}</span>
    @endif
@endsection

@section('marginalia')
    <div class="margin-block">
        <div class="lab">Editor's note</div>
        <div class="text">
            Entries are <em>compiled</em> — distilled from one or more verbatim drawers. The drawer is
            always the truth; the entry is a useful lie until it is recompiled.
        </div>
        <span class="ref">— {{ now()->format('Y-m-d') }}</span>
    </div>

    <div class="margin-block">
        <div class="lab">Glyph</div>
        <div class="text">
            The vermilion mark denotes a <em>compiled</em> entry — one with a recorded compile time. Drawers
            are not hashed, and the mark says nothing about the audit log.
        </div>
    </div>

    <div class="margin-block">
        <div class="lab">Recall</div>
        <div class="text">
            Use <a href="{{ route('wiki.search') }}">⌕ search</a> for a hybrid query, or
            <a href="{{ route('palace.index') }}">walk the palace</a> to follow lineage.
        </div>
    </div>
@endsection
