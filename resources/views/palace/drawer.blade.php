@extends('layouts.palace')

@section('title', 'Drawer #' . $drawer->id . ' — Palace — Mnemon')

@section('rail')
    <div class="rail-head">
        <h2 class="serif">Drawer <em>d_{{ str_pad((string)$drawer->id, 6, '0', STR_PAD_LEFT) }}</em></h2>
        <span class="sub">{{ $drawer->room->wing->slug }}/{{ $drawer->room->slug }}</span>
    </div>

    <div class="rail-section"><span>Metadata</span><span>—</span></div>
    <div style="padding: 0 1.25rem 1rem; display: grid; grid-template-columns: 5rem 1fr; gap: 0.5rem 1rem; font-family: var(--mono); font-size: 0.75rem; line-height: 1.5;">
        <span style="color: var(--rubric); letter-spacing: 0.1em; text-transform: uppercase; font-size: 0.65rem; padding-top: 0.15rem;">tier</span>
        <span style="color: var(--ink);">{{ $drawer->tier }}</span>

        <span style="color: var(--rubric); letter-spacing: 0.1em; text-transform: uppercase; font-size: 0.65rem; padding-top: 0.15rem;">sealed</span>
        <span style="color: var(--ink);">{{ $drawer->created_at->format('Y-m-d H:i') }}</span>

        @if ($drawer->source)
            <span style="color: var(--rubric); letter-spacing: 0.1em; text-transform: uppercase; font-size: 0.65rem; padding-top: 0.15rem;">source</span>
            <span style="color: var(--ink); word-break: break-all;">{{ $drawer->source }}</span>
        @endif

        @if ($drawer->retention_score !== null)
            <span style="color: var(--rubric); letter-spacing: 0.1em; text-transform: uppercase; font-size: 0.65rem; padding-top: 0.15rem;">retention</span>
            <span style="color: var(--ink);">{{ round($drawer->retention_score * 100) }}%</span>
        @endif
    </div>

    <div class="rail-foot">
        <a href="{{ route('palace.room', [$drawer->room->wing->slug, $drawer->room->slug]) }}">← {{ $drawer->room->name }}</a><br/>
        <a href="{{ route('palace.wing', $drawer->room->wing->slug) }}">{{ $drawer->room->wing->name }}</a>
    </div>
@endsection

@section('stage-bar')
    <div class="breadcrumb">
        <a href="{{ route('palace.index') }}">Palace</a>
        <span>/</span>
        <a href="{{ route('palace.wing', $drawer->room->wing->slug) }}">{{ $drawer->room->wing->name }}</a>
        <span>/</span>
        <a href="{{ route('palace.room', [$drawer->room->wing->slug, $drawer->room->slug]) }}">{{ $drawer->room->name }}</a>
        <span>/</span>
        <span class="here">d_{{ str_pad((string)$drawer->id, 6, '0', STR_PAD_LEFT) }}</span>
        <span class="rubric" style="margin-left:0.75rem;">●</span>
        <span>sealed</span>
    </div>
@endsection

@section('stage')
    <div style="max-width: 50rem; margin: 0 auto;">
        <div class="article-meta" style="margin-bottom: 1.25rem; font-family: var(--mono); font-size: var(--t-micro); letter-spacing: 0.16em; text-transform: uppercase; color: var(--ink-faint); display: flex; gap: 1.25rem; flex-wrap: wrap;">
            <span class="rubric">● verbatim</span>
            <span>d_{{ str_pad((string)$drawer->id, 6, '0', STR_PAD_LEFT) }}</span>
            <span>{{ strlen($drawer->content) }} chars</span>
            <span>{{ $drawer->created_at->format('Y-m-d') }}</span>
        </div>

        <h1 style="font-family: var(--serif); font-size: clamp(2rem, 4vw, 2.75rem); font-weight: 500; letter-spacing: -0.02em; line-height: 1.05; margin: 0 0 0.75rem;">
            <em style="color: var(--rubric); font-style: italic; font-weight: 400;">drawer.</em>{{ str_pad((string)$drawer->id, 6, '0', STR_PAD_LEFT) }}
        </h1>
        <p style="font-family: var(--serif); font-style: italic; font-size: 1.2rem; line-height: 1.45; color: var(--ink-faint); margin: 0 0 2rem;">
            One sealed verbatim record from <em>{{ $drawer->room->name }}</em>. The contents below are
            byte-perfect; nothing has been rewritten.
        </p>

        <div style="display: flex; gap: 2rem; flex-wrap: wrap; padding: 0.85rem 0; border-top: var(--hairline) solid var(--rule-strong); border-bottom: var(--hairline) solid var(--rule-strong); font-family: var(--mono); font-size: var(--t-micro); letter-spacing: 0.14em; text-transform: uppercase; color: var(--ink-faint); margin-bottom: 2.5rem;">
            <x-tier-badge :tier="$drawer->tier" />
            @if ($drawer->source)
                <span><b style="color: var(--ink); font-weight: 500;">Source</b> {{ $drawer->source }}</span>
            @endif
            <span><b style="color: var(--ink); font-weight: 500;">Sealed</b> {{ $drawer->created_at->format('Y-m-d H:i') }}</span>
            @if ($drawer->retention_score !== null)
                <span><b style="color: var(--ink); font-weight: 500;">Retention</b> {{ round($drawer->retention_score * 100) }}%</span>
            @endif
        </div>

        <h3 style="font-family: var(--mono); font-size: var(--t-micro); letter-spacing: 0.18em; text-transform: uppercase; color: var(--ink-faint); margin: 0 0 0.85rem; font-weight: 500;">Content · verbatim</h3>

        <div style="border: var(--hairline) solid var(--rule-strong); background: var(--paper); padding: 1.75rem 1.5rem;">
            <div style="font-family: var(--serif); font-size: 1.1rem; line-height: 1.65; color: var(--ink); white-space: pre-wrap; word-wrap: break-word;">{{ $drawer->content }}</div>
        </div>

        @if ($drawer->metadata)
            <h3 style="font-family: var(--mono); font-size: var(--t-micro); letter-spacing: 0.18em; text-transform: uppercase; color: var(--ink-faint); margin: 2.5rem 0 0.85rem; font-weight: 500;">Metadata · structured</h3>
            <pre style="font-family: var(--mono); font-size: 0.82rem; line-height: 1.7; background: oklch(0.16 0.012 50); color: oklch(0.92 0.012 80); padding: 1.5rem 1.25rem; border: var(--hairline) solid var(--rule-strong); overflow-x: auto; margin: 0;">{{ json_encode($drawer->metadata, JSON_PRETTY_PRINT) }}</pre>
        @endif

        @if ($referencedBy->isNotEmpty())
            <h3 style="font-family: var(--mono); font-size: var(--t-micro); letter-spacing: 0.18em; text-transform: uppercase; color: var(--ink-faint); margin: 2.5rem 0 0.85rem; font-weight: 500;">Compiled into · {{ $referencedBy->count() }} wiki entries</h3>
            <div style="display:grid;gap:0;border-top:var(--hairline) solid var(--rule);">
                @foreach ($referencedBy as $page)
                    <a href="{{ route('wiki.show', $page->name) }}"
                       style="display:flex;align-items:baseline;gap:1rem;padding:1rem 0;border-bottom:var(--hairline) solid var(--rule);color:var(--ink);text-decoration:none;">
                        <x-type-badge :type="$page->type" />
                        <span style="font-family: var(--serif); font-size: 1.1rem;">{{ $page->title }}</span>
                        <span class="rubric" style="margin-left:auto;">→</span>
                    </a>
                @endforeach
            </div>
        @endif

        <span class="stamp" style="display: inline-block; margin-top: 2rem; padding: 0.5rem 0.75rem; border: 1px solid var(--rubric); font-family: var(--mono); font-size: var(--t-micro); letter-spacing: 0.16em; text-transform: uppercase; color: var(--rubric); transform: rotate(-1deg);">
            Sealed · {{ $drawer->created_at->format('Y-m-d') }}
        </span>
    </div>
@endsection

@section('stage-foot')
    <div class="group">
        <span><b>d_{{ str_pad((string)$drawer->id, 6, '0', STR_PAD_LEFT) }}</b></span>
        <span><b>{{ strlen($drawer->content) }}</b> chars</span>
    </div>
    <div class="group">
        <span class="rubric">●</span><span>sealed</span>
    </div>
    <div class="group">
        <span>{{ $drawer->room->wing->slug }}/{{ $drawer->room->slug }}</span>
    </div>
@endsection
