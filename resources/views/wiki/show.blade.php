@extends('layouts.wiki')

@section('title', $page->title . ' — Mnemon Wiki')

@section('sidebar')
    <h6>Vol. I — Atlas</h6>

    <a href="{{ route('wiki.index') }}" class="btn-bare" style="display:inline-block;margin-bottom:1rem;font-family:var(--mono);font-size:var(--t-micro);letter-spacing:0.14em;text-transform:uppercase;">← all entries</a>

    <div class="toc-section"><span class="n">§ 1</span><span>This entry</span></div>
    <ul class="toc-list">
        <li><a href="#" class="is-active"><span class="num">1.01</span><span>{{ $page->title }}</span></a></li>
        <li><a href="{{ route('wiki.history', $page->name) }}"><span class="num">1.02</span><span>Revision history</span></a></li>
    </ul>

    @if ($relatedPages->isNotEmpty())
        <div class="toc-section"><span class="n">§ 2</span><span>Adjacent</span></div>
        <ul class="toc-list">
            @foreach ($relatedPages as $idx => $related)
                <li>
                    <a href="{{ route('wiki.show', $related->name) }}">
                        <span class="num">2.{{ str_pad((string)($idx + 1), 2, '0', STR_PAD_LEFT) }}</span>
                        <span>{{ $related->title }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif

    <div class="toc-foot">
        @if ($page->last_compiled_at)
            Compiled {{ $page->last_compiled_at->format('Y-m-d H:i') }} UTC<br/>
        @endif
        From {{ $sourceDrawers->count() }} verbatim sources<br/>
        @if ($page->last_compiled_at)
            <span class="rubric">●</span> compiled
        @else
            <span class="rubric">○</span> not compiled
        @endif
    </div>
@endsection

@section('content')
    <div class="article-meta">
        <span class="crumb">
            <a href="{{ route('wiki.index') }}">Wiki</a> /
            <a href="{{ route('wiki.index') }}#{{ $page->type }}">§ {{ ucfirst($page->type) }}s</a> /
            {{ $page->name }}
        </span>
        <span>{{ $page->word_count ?? 0 }} words</span>
        <span>est. read {{ max(1, (int) round(($page->word_count ?? 0) / 220)) }} min</span>
        @if ($page->last_compiled_at)
            <span class="rubric">● compiled</span>
        @else
            <span class="rubric">○ not compiled</span>
        @endif
    </div>

    <h1 class="doc-title">{{ $page->title }}</h1>
    @if ($page->description)
        <p class="doc-sub">{{ $page->description }}</p>
    @endif

    <div class="doc-byline">
        <span><b>Compiled</b> from {{ $sourceDrawers->count() }} verbatim source{{ $sourceDrawers->count() !== 1 ? 's' : '' }}</span>
        <span><b>Backlinks</b> {{ $page->backlinks_count ?? 0 }}</span>
        <span><b>Type</b> {{ ucfirst($page->type) }}</span>
        @if ($page->last_compiled_at)
            <span><b>Last compiled</b> {{ $page->last_compiled_at->format('Y-m-d') }}</span>
        @endif
    </div>

    {{-- Rendered markdown content --}}
    {!! $renderedContent !!}

    @if ($page->last_compiled_at)
        <span class="stamp">Sealed · {{ $page->last_compiled_at->format('Y-m-d') }}</span>
    @endif

    {{-- See also --}}
    @if ($relatedPages->isNotEmpty())
        <div style="margin-top:4rem;padding-top:2rem;border-top:var(--hairline) solid var(--rule-strong);">
            <h4 style="font-family:var(--mono);font-size:var(--t-micro);letter-spacing:0.18em;text-transform:uppercase;font-weight:500;color:var(--ink-faint);margin-bottom:1rem;border-top:0;padding-top:0;">See also — adjacent loci</h4>
            <ul style="list-style:none;padding:0;margin:0;display:grid;grid-template-columns:1fr 1fr;gap:0;border-top:var(--hairline) solid var(--rule);">
                @foreach ($relatedPages as $idx => $related)
                    <li style="padding:1rem 1rem 1rem 0;border-bottom:var(--hairline) solid var(--rule);{{ $idx % 2 === 0 ? 'border-right: var(--hairline) solid var(--rule); padding-right: 1.5rem;' : 'padding-left: 1.5rem;' }}">
                        <a href="{{ route('wiki.show', $related->name) }}" style="display:grid;gap:0.25rem;border-bottom:0;color:var(--ink);">
                            <span style="font-family:var(--mono);font-size:var(--t-micro);letter-spacing:0.16em;text-transform:uppercase;color:var(--ink-faint);">§ {{ ucfirst($related->type) }}</span>
                            <span style="font-family:var(--serif);font-size:1.2rem;line-height:1.2;">{{ $related->title }} <span class="rubric" style="margin-left:0.25rem;">→</span></span>
                            @if ($related->description)
                                <span style="font-size:0.875rem;color:var(--ink-faint);font-family:var(--sans);">{{ Str::limit($related->description, 100) }}</span>
                            @endif
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
@endsection

@section('marginalia')
    <div class="margin-block">
        <div class="lab">Provenance</div>
        <div class="text">
            <x-type-badge :type="$page->type" />
            <x-confidence-badge :confidence="$page->confidence ?? 'medium'" />
        </div>
        <span class="ref">{{ ($page->revision_count ?? 0) }} revision{{ ($page->revision_count ?? 0) !== 1 ? 's' : '' }} · <a href="{{ route('wiki.history', $page->name) }}">history</a></span>
    </div>

    @if ($page->pending_drawers_since_compile > 0)
        <div class="margin-block">
            <div class="lab">Stale</div>
            <div class="text">
                <em>{{ $page->pending_drawers_since_compile }} new drawer{{ $page->pending_drawers_since_compile !== 1 ? 's' : '' }}</em>
                added since this entry was last compiled. Re-seal in the admin to refresh.
            </div>
        </div>
    @endif

    @if ($sourceDrawers->isNotEmpty())
        <div class="margin-block">
            <div class="lab">Source drawers · {{ $sourceDrawers->count() }}</div>
            <div class="text">
                @foreach ($sourceDrawers->take(10) as $drawer)
                    <a href="{{ route('palace.drawer', $drawer) }}">
                        {{ $drawer->room?->wing?->name ?? '—' }} / {{ $drawer->room?->name ?? '—' }}
                    </a><br/>
                @endforeach
                @if ($sourceDrawers->count() > 10)
                    <span class="dim">+ {{ $sourceDrawers->count() - 10 }} more</span>
                @endif
            </div>
            <span class="ref">walk lineage one step deeper</span>
        </div>
    @endif

    <div class="margin-block">
        <div class="lab">Glyph</div>
        <div class="text">
            The vermilion mark denotes a <em>compiled</em> entry — one with a recorded compile time. An entry
            without the mark has never been compiled.
        </div>
    </div>
@endsection
