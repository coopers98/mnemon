@extends('layouts.palace')

@section('title', 'The Palace — Mnemon')

@section('rail')
    <div class="rail-head">
        <h2 class="serif">The <em>palace</em>.</h2>
        <span class="sub">{{ $wings->count() }} wing{{ $wings->count() !== 1 ? 's' : '' }} · {{ $wings->sum('drawers_count') }} drawers</span>
    </div>

    <div class="rail-section"><span>Wings</span><span>{{ str_pad((string)$wings->count(), 2, '0', STR_PAD_LEFT) }}</span></div>
    <ul class="room-list">
        @foreach ($wings as $wing)
            <li class="room-item" onclick="window.location='{{ route('palace.wing', $wing->slug) }}'" style="cursor:pointer;">
                <span class="glyph"></span>
                <span class="name">
                    {{ $wing->name }}
                    <span class="meta">{{ $wing->rooms_count }} rooms · {{ $wing->drawers_count }} drawers</span>
                </span>
                <span class="count">{{ str_pad((string)$wing->drawers_count, 2, '0', STR_PAD_LEFT) }}</span>
            </li>
        @endforeach
    </ul>

    <div class="rail-foot">
        <strong style="color:var(--ink);font-family:var(--serif);font-style:italic;font-size:1rem;">Walk this palace</strong><br/>
        Click a wing to enter.<br/>
        <a href="{{ route('wiki.search') }}">⌕ search</a>
    </div>
@endsection

@section('stage-bar')
    <div class="breadcrumb">
        <span>Palace /</span>
        <span class="here">All wings</span>
        <span class="rubric" style="margin-left:0.75rem;">●</span>
        <span>{{ now()->format('Y-m-d') }}</span>
    </div>
    <div style="display:flex;gap:0.5rem;align-items:center;">
        <span class="tag"><span class="dot"></span>live</span>
        <a class="btn" href="/admin" style="font-size:0.75rem;padding:0.5rem 0.85rem;">
            <span>Open admin</span><span class="arrow">→</span>
        </a>
    </div>
@endsection

@section('stage')
    @if ($wings->isEmpty())
        <div class="empty" style="max-width: 32rem; margin: 4rem auto;">
            No wings yet. Create your first wing through the
            <a href="/admin" style="color:var(--rubric);border-bottom:1px solid var(--rubric);">admin panel</a>
            or via MCP tools.
        </div>
    @else
        <div style="max-width: 64rem; margin: 0 auto;">
            <div class="sec-head">
                <span class="num">§ Plate i</span>
                <div>
                    <div class="title">The whole house, in one plate.</div>
                    <span class="lede">Each wing is a namespace; each room a coherent context. Click in.</span>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(20rem, 1fr));gap:0;border-top:var(--hairline) solid var(--rule-strong);border-left:var(--hairline) solid var(--rule);">
                @foreach ($wings as $wing)
                    <a href="{{ route('palace.wing', $wing->slug) }}"
                       style="display:block;padding:1.75rem 1.5rem;border-right:var(--hairline) solid var(--rule);border-bottom:var(--hairline) solid var(--rule);background:var(--paper);transition:background 0.15s;color:var(--ink);text-decoration:none;position:relative;min-height:11rem;">
                        <div style="position:absolute;top:0.65rem;right:0.85rem;font-family:var(--mono);font-size:0.6rem;color:var(--rubric);letter-spacing:0.14em;">
                            {{ chr(96 + $loop->iteration) }}.
                        </div>
                        <div style="font-family:var(--mono);font-size:var(--t-micro);letter-spacing:0.18em;text-transform:uppercase;color:var(--ink-faint);margin-bottom:0.5rem;">
                            wing · {{ $wing->slug }}
                        </div>
                        <div style="font-family:var(--serif);font-size:1.65rem;line-height:1.15;letter-spacing:-0.01em;margin-bottom:0.5rem;">
                            {{ $wing->name }}
                        </div>
                        @if ($wing->description)
                            <p style="font-size:0.875rem;color:var(--ink-faint);line-height:1.5;margin:0 0 0.85rem;">{{ Str::limit($wing->description, 120) }}</p>
                        @endif
                        <div style="display:flex;gap:1.25rem;font-family:var(--mono);font-size:var(--t-micro);letter-spacing:0.12em;text-transform:uppercase;color:var(--ink-faint);padding-top:0.85rem;border-top:var(--hairline) solid var(--rule);">
                            <span><b style="color:var(--ink);">{{ $wing->rooms_count }}</b> rooms</span>
                            <span><b style="color:var(--ink);">{{ $wing->drawers_count }}</b> drawers</span>
                        </div>
                    </a>
                @endforeach
            </div>
        </div>
    @endif
@endsection

@section('stage-foot')
    <div class="group">
        <span><b>{{ $wings->count() }}</b> wings</span>
        <span><b>{{ $wings->sum('rooms_count') }}</b> rooms</span>
        <span><b>{{ $wings->sum('drawers_count') }}</b> drawers</span>
    </div>
    <div class="group">
        <span class="rubric">●</span><span>sealed</span>
    </div>
    <div class="group">
        <span>postgres · localhost:5432</span>
    </div>
@endsection
