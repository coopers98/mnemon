@props(['confidence' => 'medium'])

@php
    $klass = match ($confidence) {
        'high' => 'is-rubric',
        'low' => '',
        default => 'is-ink',
    };
@endphp

<span class="pill {{ $klass }}"><span class="dot"></span>conf · {{ $confidence }}</span>
