@extends('layouts.palace')

@section('title', $wing->name . ' — Palace — Mnemon')

@section('rail')
    <div class="rail-head">
        <h2 class="serif">{{ $wing->name }}.</h2>
        <span class="sub">wing · {{ $wing->slug }} · {{ $rooms->count() }} rooms</span>
    </div>

    <div class="rail-section"><span>Rooms</span><span>{{ str_pad((string)$rooms->count(), 2, '0', STR_PAD_LEFT) }}</span></div>
    <ul class="room-list">
        @foreach ($rooms as $room)
            <li class="room-item" onclick="window.location='{{ route('palace.room', [$wing->slug, $room->slug]) }}'" style="cursor:pointer;">
                <span class="glyph"></span>
                <span class="name">
                    {{ $room->name }}
                    <span class="meta">{{ chr(96 + $loop->iteration) }} · {{ $room->drawers_count }} drawers</span>
                </span>
                <span class="count">{{ str_pad((string)$room->drawers_count, 2, '0', STR_PAD_LEFT) }}</span>
            </li>
        @endforeach
    </ul>

    <div class="rail-foot">
        <a href="{{ route('palace.index') }}">← all wings</a><br/>
        @if ($wikiPage)
            <a href="{{ route('wiki.show', $wikiPage->name) }}">read wiki entry</a>
        @endif
    </div>
@endsection

@section('stage-bar')
    <div class="breadcrumb">
        <a href="{{ route('palace.index') }}">Palace</a>
        <span>/</span>
        <span class="here">{{ $wing->name }}</span>
        <span class="rubric" style="margin-left:0.75rem;">●</span>
        <span>{{ $rooms->count() }} rooms · {{ $rooms->sum('drawers_count') }} drawers</span>
    </div>
    <div style="display:flex;gap:0.5rem;align-items:center;">
        @if ($wikiPage)
            <a class="btn btn-ghost" href="{{ route('wiki.show', $wikiPage->name) }}" style="font-size:0.75rem;padding:0.5rem 0.85rem;">
                <span>View wiki entry</span><span class="arrow">→</span>
            </a>
        @endif
    </div>
@endsection

@section('stage')
    <div style="max-width: 60rem; margin: 0 auto;">
        @if ($wing->description)
            <p style="font-family:var(--serif);font-size:1.2rem;line-height:1.55;color:var(--ink-soft);margin-bottom:2rem;font-style:italic;">{{ $wing->description }}</p>
        @endif

        <div class="sec-head">
            <span class="num">§ {{ strtoupper($wing->slug) }}</span>
            <div>
                <div class="title">Rooms in this wing.</div>
                <span class="lede">A coherent context apiece — a project, a client, a research thread.</span>
            </div>
        </div>

        @if ($rooms->isEmpty())
            <div class="empty">
                No rooms yet. Add one through the admin panel.
            </div>
        @else
            <div style="display:grid;gap:0;border-top:var(--hairline) solid var(--rule);">
                @foreach ($rooms as $room)
                    <a href="{{ route('palace.room', [$wing->slug, $room->slug]) }}"
                       style="display:grid;grid-template-columns:5rem 1fr auto;gap:1.5rem;padding:1.4rem 0;border-bottom:var(--hairline) solid var(--rule);align-items:baseline;color:var(--ink);text-decoration:none;">
                        <span style="font-family:var(--mono);font-size:var(--t-micro);letter-spacing:0.18em;color:var(--rubric);">
                            room · {{ str_pad((string)$loop->iteration, 2, '0', STR_PAD_LEFT) }}
                        </span>
                        <div>
                            <div style="font-family:var(--serif);font-size:1.3rem;line-height:1.2;">{{ $room->name }}</div>
                            <div style="font-family:var(--mono);font-size:var(--t-micro);letter-spacing:0.1em;color:var(--ink-faint);margin-top:0.3rem;text-transform:uppercase;">
                                {{ $wing->slug }}/{{ $room->slug }}
                            </div>
                        </div>
                        <div style="font-family:var(--mono);font-size:var(--t-micro);letter-spacing:0.12em;text-transform:uppercase;color:var(--ink-faint);text-align:right;white-space:nowrap;">
                            <b style="color:var(--ink);">{{ $room->drawers_count }}</b> drawers <span class="rubric" style="margin-left:0.5rem;">→</span>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
@endsection

@section('stage-foot')
    <div class="group">
        <span><b>{{ $rooms->count() }}</b> rooms</span>
        <span><b>{{ $rooms->sum('drawers_count') }}</b> drawers</span>
    </div>
    <div class="group">
        <span class="rubric">●</span><span>{{ $wing->slug }}</span>
    </div>
    <div class="group">
        @if ($wikiPage)
            <span>wiki · <b>{{ $wikiPage->name }}</b></span>
        @endif
    </div>
@endsection
