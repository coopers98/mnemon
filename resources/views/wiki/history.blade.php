@extends('layouts.wiki')

@section('title', $page->title . ' — Revision history — Mnemon')

@section('sidebar')
    <h6>Provenance</h6>
    <a href="{{ route('wiki.show', $page->name) }}" class="btn-bare" style="display:inline-block;margin-bottom:1rem;font-family:var(--mono);font-size:var(--t-micro);letter-spacing:0.14em;text-transform:uppercase;">← back to entry</a>

    <div class="toc-section"><span class="n">§</span><span>This view</span></div>
    <ul class="toc-list">
        <li><a href="#" class="is-active"><span class="num">·</span><span>Revision history</span></a></li>
        <li><a href="{{ route('wiki.show', $page->name) }}"><span class="num">·</span><span>{{ $page->title }}</span></a></li>
    </ul>

    <div class="toc-foot">
        {{ $revisions->total() ?? $revisions->count() }} revision{{ ($revisions->total() ?? $revisions->count()) !== 1 ? 's' : '' }}<br/>
        @if ($page->last_compiled_at)
            head sealed {{ $page->last_compiled_at->diffForHumans() }}
        @endif
    </div>
@endsection

@section('content')
    <div class="article-meta">
        <span class="crumb"><a href="{{ route('wiki.index') }}">Wiki</a> / <a href="{{ route('wiki.show', $page->name) }}">{{ $page->title }}</a> / history</span>
    </div>

    <h1 class="doc-title">Revision <em>history</em>.</h1>
    <p class="doc-sub">
        Every compilation of <em>{{ $page->title }}</em>, in order. The current head sits at the top.
        Each row is a sealed snapshot — a content hash that can be replayed against its source drawers.
    </p>

    @if ($revisions->isEmpty())
        <div class="empty">
            No revisions recorded yet for this entry.
        </div>
    @else
        <div style="display:grid;gap:0;border-top:var(--hairline) solid var(--rule-strong);">
            @foreach ($revisions as $revision)
                <div style="display:grid;grid-template-columns:5rem 1fr auto;gap:1.5rem;padding:1.25rem 0;border-bottom:var(--hairline) solid var(--rule);align-items:baseline;">
                    <span style="font-family:var(--mono);font-size:var(--t-micro);letter-spacing:0.16em;text-transform:uppercase;color:var(--rubric);">rev · {{ $revision->revision }}</span>
                    <div>
                        <div style="font-family:var(--serif);font-size:1.1rem;color:var(--ink);line-height:1.25;">
                            {{ $revision->written_at?->format('Y-m-d · H:i \U\T\C') ?? $revision->created_at->format('Y-m-d · H:i \U\T\C') }}
                        </div>
                        @if ($revision->content_hash)
                            <div style="font-family:var(--mono);font-size:var(--t-micro);letter-spacing:0.04em;color:var(--ink-faint);margin-top:0.25rem;">
                                {{ Str::limit($revision->content_hash, 24) }}
                            </div>
                        @endif
                    </div>
                    <div style="font-family:var(--mono);font-size:var(--t-micro);letter-spacing:0.1em;text-transform:uppercase;color:var(--ink-faint);text-align:right;white-space:nowrap;">
                        @if ($revision->agent_id)
                            agent · {{ $revision->agent_id }}
                        @else
                            <span class="dim">manual</span>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        <div style="margin-top:2rem;">
            {{ $revisions->links() }}
        </div>
    @endif
@endsection

@section('marginalia')
    <div class="margin-block">
        <div class="lab">Why this exists</div>
        <div class="text">
            Compilation is <em>lossy</em>; the source drawers are not. History gives you the audit trail
            from any past entry back to its sealed inputs.
        </div>
    </div>

    <div class="margin-block">
        <div class="lab">Reading the hash</div>
        <div class="text">
            The first 24 chars of a <code>blake3</code> digest of the rendered entry. Identical hashes
            mean a compile produced byte-equivalent output.
        </div>
    </div>
@endsection
