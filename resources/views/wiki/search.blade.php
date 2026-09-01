@extends('layouts.wiki')

@section('title', 'Search — Mnemon Wiki')

@section('sidebar')
    <h6>Recall — hybrid</h6>
    <p style="font-family:var(--serif);font-size:0.95rem;line-height:1.5;color:var(--ink-soft);">
        Postgres full-text over <code>tsvector</code>, blended with <code>pgvector</code> cosine and a
        recency boost. Wiki and palace are searched together; results carry <em>provenance</em>.
    </p>

    <div class="toc-foot">
        <a href="{{ route('wiki.index') }}">← back to atlas</a><br/>
        <a href="{{ route('palace.index') }}">walk the palace</a>
    </div>
@endsection

@section('content')
    <div class="article-meta">
        <span class="crumb"><a href="{{ route('wiki.index') }}">Wiki</a> / search</span>
        @if ($query !== '')
            <span>q · {{ Str::limit($query, 60) }}</span>
        @endif
    </div>

    <h1 class="doc-title">{{ $query !== '' ? 'Recall.' : 'Walk to' }} <em>{{ $query !== '' ? '' : 'something' }}</em></h1>
    @if ($query === '')
        <p class="doc-sub">A hybrid query against both compiled entries and verbatim drawers. Results are ranked, deduped, and lineage-attributed.</p>
    @else
        <p class="doc-sub">{{ ($wikiResults?->count() ?? 0) + ($drawerResults?->count() ?? 0) }} result{{ (($wikiResults?->count() ?? 0) + ($drawerResults?->count() ?? 0)) !== 1 ? 's' : '' }} for <em>{{ $query }}</em>.</p>
    @endif

    <form action="{{ route('wiki.search') }}" method="GET" style="display:flex;align-items:center;gap:0.6rem;padding:0.65rem 0.85rem;border:var(--hairline) solid var(--ink);background:var(--paper);margin-bottom:2.5rem;">
        <span aria-hidden="true" style="color:var(--rubric);font-family:var(--mono);">⌕</span>
        <input type="text" name="q" value="{{ $query }}" placeholder="walk to…" autofocus
               style="flex:1;border:0;background:transparent;outline:none;font:inherit;font-family:var(--mono);font-size:0.95rem;color:var(--ink);" />
        <button type="submit" class="btn" style="font-size:0.75rem;padding:0.45rem 0.75rem;">
            <span>Recall</span><span class="arrow">→</span>
        </button>
    </form>

    @if ($query !== '')
        @if (($wikiResults?->isNotEmpty() ?? false))
            <h2 style="margin-top:0;">Wiki entries <span style="font-family:var(--mono);font-size:0.45em;letter-spacing:0.18em;color:var(--ink-faint);text-transform:uppercase;margin-left:0.75rem;">— {{ $wikiResults->count() }}</span></h2>
            <div style="display:grid;gap:0;border-top:var(--hairline) solid var(--rule);">
                @foreach ($wikiResults as $result)
                    <a href="{{ route('wiki.show', $result->name) }}"
                       style="display:grid;grid-template-columns:1fr auto;gap:1.5rem;padding:1.25rem 0;border-bottom:var(--hairline) solid var(--rule);align-items:baseline;color:var(--ink);">
                        <div>
                            <div style="display:flex;align-items:baseline;gap:0.85rem;flex-wrap:wrap;">
                                <span style="font-family:var(--serif);font-size:1.2rem;line-height:1.2;">{{ $result->title }}</span>
                                <x-type-badge :type="$result->type" />
                            </div>
                            @if ($result->description)
                                <p style="font-family:var(--serif);font-size:1rem;color:var(--ink-soft);line-height:1.55;margin:0.4rem 0 0;border:0;">{{ Str::limit($result->description, 200) }}</p>
                            @endif
                        </div>
                        <div style="font-family:var(--mono);font-size:var(--t-micro);letter-spacing:0.1em;text-transform:uppercase;color:var(--rubric);text-align:right;white-space:nowrap;">
                            score · {{ round($result->score * 100) }}
                        </div>
                    </a>
                @endforeach
            </div>
        @endif

        @if (($drawerResults?->isNotEmpty() ?? false))
            <h2>Verbatim drawers <span style="font-family:var(--mono);font-size:0.45em;letter-spacing:0.18em;color:var(--ink-faint);text-transform:uppercase;margin-left:0.75rem;">— {{ $drawerResults->count() }}</span></h2>
            <div style="display:grid;gap:0;border-top:var(--hairline) solid var(--rule);">
                @foreach ($drawerResults as $result)
                    <a href="{{ route('palace.drawer', $result->id) }}"
                       style="display:grid;grid-template-columns:1fr auto;gap:1.5rem;padding:1.25rem 0;border-bottom:var(--hairline) solid var(--rule);align-items:baseline;color:var(--ink);">
                        <div>
                            <div style="display:flex;align-items:baseline;gap:0.85rem;flex-wrap:wrap;">
                                <x-tier-badge :tier="$result->tier" />
                                <span style="font-family:var(--mono);font-size:var(--t-micro);letter-spacing:0.14em;text-transform:uppercase;color:var(--ink-faint);">
                                    {{ $result->wing }} / {{ $result->room }}
                                </span>
                            </div>
                            <p style="font-family:var(--serif);font-size:1.05rem;color:var(--ink);line-height:1.55;margin:0.5rem 0 0;border:0;">{{ Str::limit($result->content, 220) }}</p>
                        </div>
                        <div style="font-family:var(--mono);font-size:var(--t-micro);letter-spacing:0.1em;text-transform:uppercase;color:var(--rubric);text-align:right;white-space:nowrap;">
                            score · {{ round($result->score * 100) }}
                        </div>
                    </a>
                @endforeach
            </div>
        @endif

        @if (($wikiResults?->isEmpty() ?? true) && ($drawerResults?->isEmpty() ?? true))
            <div class="empty">
                Nothing recalled for <em>{{ $query }}</em>. The query may be too narrow, or the archive
                may not yet contain it.
            </div>
        @endif
    @endif
@endsection
