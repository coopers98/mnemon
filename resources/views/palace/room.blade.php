@extends('layouts.palace')

@section('title', $room->name . ' — ' . $wing->name . ' — Palace — Mnemon')

@section('rail')
    <div class="rail-head">
        <h2 class="serif">{{ $room->name }}.</h2>
        <span class="sub">{{ $wing->slug }}/{{ $room->slug }} · {{ $drawers->total() }} drawers</span>
    </div>

    <div class="rail-section"><span>This room</span><span>—</span></div>
    <div style="padding: 0 1.25rem 1rem; font-family: var(--serif); font-size: 0.95rem; line-height: 1.5; color: var(--ink-soft);">
        @if ($room->description)
            <p style="margin: 0;">{{ $room->description }}</p>
        @else
            <p style="margin: 0; font-style: italic; color: var(--ink-faint);">No room description.</p>
        @endif
    </div>

    <div class="rail-foot">
        <a href="{{ route('palace.wing', $wing->slug) }}">← {{ $wing->name }}</a><br/>
        <a href="{{ route('palace.index') }}">all wings</a>
    </div>
@endsection

@section('stage-bar')
    <div class="breadcrumb">
        <a href="{{ route('palace.index') }}">Palace</a>
        <span>/</span>
        <a href="{{ route('palace.wing', $wing->slug) }}">{{ $wing->name }}</a>
        <span>/</span>
        <span class="here">{{ $room->name }}</span>
        <span class="rubric" style="margin-left:0.75rem;">●</span>
        <span>{{ $drawers->total() }} drawers</span>
    </div>
@endsection

@section('stage')
    <div style="max-width: 60rem; margin: 0 auto;">
        <div class="sec-head">
            <span class="num">§ {{ strtoupper($wing->slug) }} / {{ strtoupper($room->slug) }}</span>
            <div>
                <div class="title">Drawers, in order of seal.</div>
                <span class="lede">Append-only. Each drawer is a sealed verbatim record — newest on top.</span>
            </div>
        </div>

        @if ($drawers->isEmpty())
            <div class="empty">
                No drawers in this room yet.
            </div>
        @else
            <div style="display:grid;gap:0;border-top:var(--hairline) solid var(--rule);">
                @foreach ($drawers as $drawer)
                    <a href="{{ route('palace.drawer', $drawer) }}"
                       style="display:block;padding:1.5rem 0;border-bottom:var(--hairline) solid var(--rule);color:var(--ink);text-decoration:none;">
                        <div style="display:flex;align-items:baseline;gap:0.85rem;flex-wrap:wrap;margin-bottom:0.65rem;">
                            <span style="font-family:var(--mono);font-size:var(--t-micro);letter-spacing:0.16em;color:var(--rubric);">
                                d_{{ str_pad((string)$drawer->id, 6, '0', STR_PAD_LEFT) }}
                            </span>
                            <x-tier-badge :tier="$drawer->tier" />
                            @if ($drawer->source)
                                <span style="font-family:var(--mono);font-size:var(--t-micro);letter-spacing:0.12em;text-transform:uppercase;color:var(--ink-faint);">{{ $drawer->source }}</span>
                            @endif
                            <span style="margin-left:auto;font-family:var(--mono);font-size:var(--t-micro);letter-spacing:0.12em;text-transform:uppercase;color:var(--ink-faint);">
                                {{ $drawer->created_at->format('Y-m-d · H:i') }}
                            </span>
                        </div>
                        <p style="font-family:var(--serif);font-size:1.05rem;line-height:1.55;color:var(--ink);margin:0;">
                            {{ Str::limit($drawer->content, 280) }}
                        </p>
                    </a>
                @endforeach
            </div>

            <div style="margin-top: 2rem;">
                {{ $drawers->links() }}
            </div>
        @endif
    </div>
@endsection

@section('stage-foot')
    <div class="group">
        <span><b>{{ $drawers->total() }}</b> drawers</span>
        <span>page {{ $drawers->currentPage() }} of {{ $drawers->lastPage() }}</span>
    </div>
    <div class="group">
        <span class="rubric">●</span><span>{{ $wing->slug }}/{{ $room->slug }}</span>
    </div>
@endsection
